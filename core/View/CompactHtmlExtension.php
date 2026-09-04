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
 * inline elements on one line is untouched; and a <pre>/<textarea> must
 * never be inside the block. Used on the navigation partial, which is two
 * copies of the whole menu on every page (~135 KB, half of it
 * indentation). Its own extension, not a closure in TwigFactory, so a test
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
        $compact = (string) preg_replace('/>[ \t]*\R\s*</', '><', (string) $html);

        return trim((string) preg_replace('/[ \t]*\R\s*/', ' ', $compact));
    }
}
