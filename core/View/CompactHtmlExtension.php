<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * `compact_html`: drop the indentation of a rendered block.
 *
 * A whitespace run spanning a line break disappears between two tags and
 * becomes one space elsewhere — exactly what the browser would render it
 * as, so nothing visible changes; a deliberate single space between two
 * inline elements on one line is untouched; and the content of a <pre>
 * or a <textarea> — the two places where whitespace IS the data — is left
 * exactly as it was. Used on the navigation partial, which is two copies
 * of the whole menu on every page (~135 KB, half of it indentation), and
 * on the large member tables. Its own extension, not a closure in TwigFactory, so a test
 * that renders a template with a bare Twig environment can add it in one
 * line.
 */
final class CompactHtmlExtension extends AbstractExtension
{
    public function getFilters(): array
    {
        return [
            new TwigFilter('compact_html', [self::class, 'compact'], ['is_safe' => ['html'], 'pre_escape' => 'html']),
        ];
    }

    public static function compact(?string $html): string
    {
        // Split on the preformatted elements; only the parts between them
        // are compacted, the elements themselves are copied verbatim.
        $parts = preg_split('/(<(?:textarea|pre)\b[^>]*>.*?<\/(?:textarea|pre)>)/is', (string) $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        if ($parts === false) {
            return (string) $html;
        }

        $out = '';
        foreach ($parts as $index => $part) {
            if ($index % 2 === 1) {
                $out .= $part;
                continue;
            }
            $compact = (string) preg_replace('/>[ \t]*\R\s*</', '><', $part);
            // The neighbouring preformatted element starts with "<" and
            // ends with ">", so a line break touching it is between tags too.
            if ($index > 0) {
                $compact = (string) preg_replace('/^[ \t]*\R\s*(?=<|$)/', '', $compact);
            }
            if ($index < count($parts) - 1) {
                $compact = (string) preg_replace('/(?<=>)[ \t]*\R\s*$/', '', $compact);
            }
            $out .= (string) preg_replace('/[ \t]*\R\s*/', ' ', $compact);
        }

        return trim($out);
    }
}
