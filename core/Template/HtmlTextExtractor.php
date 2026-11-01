<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */
declare(strict_types=1);

namespace Core\Template;

/** Reads text nodes directly: malformed attribute names never reach strip_tags(). */
final class HtmlTextExtractor
{
    public static function extract(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }
        $document = \Dom\HTMLDocument::createFromString($html, LIBXML_NOERROR, 'UTF-8');
        return self::text($document);
    }

    private static function text(\Dom\Node $node): string
    {
        if ($node instanceof \Dom\Text) {
            return $node->textContent;
        }
        $tag = strtolower($node->nodeName);
        if (in_array($tag, ['script', 'style', 'noscript'], true)) {
            return '';
        }
        $text = '';
        foreach ($node->childNodes as $child) {
            $text .= self::text($child);
        }
        $block = in_array($tag, ['br', 'p', 'div', 'li', 'tr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'title'], true);
        return $block ? "\n" . $text . "\n" : $text;
    }
}
