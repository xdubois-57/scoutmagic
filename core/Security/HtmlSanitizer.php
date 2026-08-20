<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

class HtmlSanitizer
{
    /** @var array<string, array<string>> */
    private const ALLOWED = [
        'p' => [],
        'br' => [],
        'strong' => [],
        'b' => [],
        'em' => [],
        'i' => [],
        'u' => [],
        'a' => ['href', 'title', 'target', 'rel'],
        'ul' => [],
        'ol' => [],
        'li' => [],
        'h2' => [],
        'h3' => [],
        'h4' => [],
        'blockquote' => [],
        // The editor's "Insérer une image" button produced <img> that the
        // sanitizer silently stripped (no 'img' key). Allowed with a tight
        // attribute set; src is scheme-validated below so only http/https/
        // relative URLs survive (never javascript:/data:).
        'img' => ['src', 'alt', 'width', 'height'],
    ];

    /** Tags whose content is removed entirely */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'textarea', 'select'];

    /**
     * URL schemes an href/src may carry. An allowlist, never a blocklist: a
     * blocklist of "dangerous" schemes (javascript:, data:, …) always misses
     * one — vbscript: survived the old check — so only the schemes a link or
     * image actually needs are accepted. A value with no scheme at all (a
     * relative URL, fragment or query) is always safe.
     */
    private const URL_SCHEME_ALLOWLIST = ['http', 'https', 'mailto', 'tel'];

    /**
     * Sanitize HTML string. Removes all tags and attributes not in ALLOWED.
     */
    public function sanitize(string $html): string
    {
        $html = trim($html);
        if ($html === '') {
            return '';
        }

        // Wrap in a container for DOMDocument parsing
        $wrapped = '<div>' . $html . '</div>';

        $doc = new \DOMDocument('1.0', 'UTF-8');

        // Suppress warnings from malformed HTML
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML(
            '<?xml encoding="UTF-8">' . $wrapped,
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        // Find the wrapper div
        $body = $doc->getElementsByTagName('div')->item(0);
        if ($body === null) {
            return '';
        }

        $this->walkNode($body, $doc);

        // Serialize children of the wrapper
        $output = '';
        if ($body->childNodes !== null) {
            foreach ($body->childNodes as $child) {
                $output .= $doc->saveHTML($child);
            }
        }

        return trim($output);
    }

    private function walkNode(\DOMNode $node, \DOMDocument $doc): void
    {
        // Process children in reverse (removal-safe)
        $children = [];
        if ($node->childNodes !== null) {
            foreach ($node->childNodes as $child) {
                $children[] = $child;
            }
        }

        foreach ($children as $child) {
            if ($child instanceof \DOMElement) {
                $tagName = strtolower($child->tagName);

                // Strip with content entirely
                if (in_array($tagName, self::STRIP_WITH_CONTENT, true)) {
                    $node->removeChild($child);
                    continue;
                }

                if (!array_key_exists($tagName, self::ALLOWED)) {
                    // Replace the tag with its children (keep text content)
                    $this->replaceWithChildren($child, $node, $doc);
                    continue;
                }

                // Sanitize attributes
                $this->sanitizeAttributes($child, $tagName);

                // Recurse into allowed tags
                $this->walkNode($child, $doc);
            } elseif (!($child instanceof \DOMText)) {
                // Keep only text nodes. Everything else here — comments,
                // processing instructions (otherwise re-serialized verbatim),
                // CDATA sections — has no place in sanitized rich text and is
                // removed.
                $node->removeChild($child);
            }
        }
    }

    private function replaceWithChildren(\DOMElement $element, \DOMNode $parent, \DOMDocument $doc): void
    {
        $insertedNodes = [];
        if ($element->childNodes !== null) {
            // Clone children first to avoid iterator invalidation
            $children = [];
            foreach ($element->childNodes as $child) {
                $children[] = $child;
            }
            foreach ($children as $child) {
                $cloned = $child->cloneNode(true);
                $parent->insertBefore($cloned, $element);
                $insertedNodes[] = $cloned;
            }
        }

        $parent->removeChild($element);

        // Walk newly inserted nodes to sanitize them
        foreach ($insertedNodes as $inserted) {
            if ($inserted instanceof \DOMElement) {
                $tagName = strtolower($inserted->tagName);

                if (in_array($tagName, self::STRIP_WITH_CONTENT, true)) {
                    $parent->removeChild($inserted);
                    continue;
                }

                if (!array_key_exists($tagName, self::ALLOWED)) {
                    $this->replaceWithChildren($inserted, $parent, $doc);
                    continue;
                }

                $this->sanitizeAttributes($inserted, $tagName);
                $this->walkNode($inserted, $doc);
            }
        }
    }

    private function sanitizeAttributes(\DOMElement $element, string $tagName): void
    {
        $allowedAttrs = self::ALLOWED[$tagName] ?? [];

        // Collect attributes to remove
        $toRemove = [];
        foreach ($element->attributes as $attr) {
            /** @var \DOMAttr $attr */
            $attrName = strtolower($attr->name);

            // Remove event handlers
            if (str_starts_with($attrName, 'on')) {
                $toRemove[] = $attr->name;
                continue;
            }

            if (!in_array($attrName, $allowedAttrs, true)) {
                $toRemove[] = $attr->name;
                continue;
            }

            // Scheme-check any URL-bearing attribute (a link's href, an
            // image's src) against the allowlist.
            if (($attrName === 'href' || $attrName === 'src') && !$this->isSafeUrlValue($attr->value)) {
                $toRemove[] = $attr->name;
                continue;
            }
        }

        foreach ($toRemove as $name) {
            $element->removeAttribute($name);
        }

        // An <img> that lost its src to the scheme check is an empty, broken
        // element — drop it rather than leave a bare <img> behind.
        if ($tagName === 'img' && !$element->hasAttribute('src')) {
            $element->parentNode?->removeChild($element);
            return;
        }

        // Force rel on <a> with target="_blank"
        if ($tagName === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }

    /**
     * Whether a URL-bearing attribute value is safe: no scheme (relative), or
     * a scheme in the allowlist. Tab/CR/LF are stripped first (browsers ignore
     * them inside a scheme, so "java\tscript:" would otherwise slip past).
     */
    private function isSafeUrlValue(string $value): bool
    {
        $normalized = strtolower(trim((string) preg_replace('/[\t\r\n]+/', '', $value)));
        if (preg_match('/^([a-z][a-z0-9+.-]*):/', $normalized, $m) !== 1) {
            return true;
        }

        return in_array($m[1], self::URL_SCHEME_ALLOWLIST, true);
    }
}
