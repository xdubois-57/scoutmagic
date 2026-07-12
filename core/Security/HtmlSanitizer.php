<?php

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
    ];

    /** Tags whose content is removed entirely */
    private const STRIP_WITH_CONTENT = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'textarea', 'select'];

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
            } elseif ($child instanceof \DOMText || $child instanceof \DOMComment) {
                // Keep text nodes; remove comment nodes
                if ($child instanceof \DOMComment) {
                    $node->removeChild($child);
                }
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

            // Sanitize href values
            if ($attrName === 'href') {
                $value = strtolower(trim($attr->value));
                if (str_starts_with($value, 'javascript:') || str_starts_with($value, 'data:')) {
                    $toRemove[] = $attr->name;
                    continue;
                }
            }
        }

        foreach ($toRemove as $name) {
            $element->removeAttribute($name);
        }

        // Force rel on <a> with target="_blank"
        if ($tagName === 'a' && $element->getAttribute('target') === '_blank') {
            $element->setAttribute('rel', 'noopener noreferrer');
        }
    }
}
