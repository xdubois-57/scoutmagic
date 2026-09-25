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
 * The numbers a page prints, in the forms a Belgian reader expects.
 *
 * Money and file sizes, and nothing else: each one replaced dozens of
 * hand-written `number_format()` chains that had drifted apart, which is
 * the whole reason they are filters rather than call-site expressions.
 *
 * **An extension rather than closures inside `TwigFactory`**, for the
 * reason `DateFilterExtension` gives: a test that builds its own
 * `Environment` re-implements the filter its template happens to need.
 * Twelve test files carried their own `|money`, all of them a correct
 * copy — which is what made the duplication invisible, since nothing
 * fails on the day one copy stops matching.
 *
 * `|filesize` is what that day looks like. Both doubles of it were
 * `((int) $bytes) . ' o'`, which answers « 300 o » and « 2400000 o »
 * where this filter answers « < 1 Ko » and « 2,3 Mo ».
 * `Tests\Modules\InboundMail\Controller\InboundMailboxControllerTest`
 * renders `mailbox/show.html.twig`, which includes
 * `partials/attachments.html.twig`, which prints it — so that test was
 * rendering a page nobody sees, and the next assertion written about an
 * attachment's size would have been written against it. The other
 * (`Tests\Modules\Finance\Controller\ReceiptControllerTest`) stubbed
 * a filter no finance template calls at all (issue #465).
 */
class FormatFilterExtension extends AbstractExtension
{
    /**
     * @return array<int, TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            // Belgian-French money rendering — "1 234,56 €". One filter
            // instead of ~75 hand-written number_format(2, ',', ' ') ~ ' €'
            // chains; |money_cents is the same thing for integer cents (the
            // rental module stores cents and used to divide inline at every
            // call site). Null renders empty — an absent amount is not 0,00 €.
            new TwigFilter('money', [self::class, 'money']),
            new TwigFilter('money_cents', [self::class, 'moneyCents']),
            // A file size as a person reads it — « < 1 Ko », « 12 Ko »,
            // « 13,9 Mo » — where templates used to print « 13867 Ko » and
            // « 0 Ko » for a three-hundred-byte PDF.
            new TwigFilter('filesize', [self::class, 'fileSize']),
        ];
    }

    public static function money(mixed $amount): string
    {
        if ($amount === null || $amount === '') {
            return '';
        }

        return number_format((float) $amount, 2, ',', ' ') . ' €';
    }

    public static function moneyCents(mixed $cents): string
    {
        if ($cents === null || $cents === '') {
            return '';
        }

        return number_format(((int) $cents) / 100, 2, ',', ' ') . ' €';
    }

    public static function fileSize(mixed $bytes): string
    {
        $bytes = max(0, (int) $bytes);
        if ($bytes < 1024) {
            return $bytes === 0 ? '0 Ko' : '< 1 Ko';
        }
        if ($bytes < 1024 * 1024) {
            return (string) (int) round($bytes / 1024) . ' Ko';
        }
        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1, ',', ' ') . ' Mo';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 1, ',', ' ') . ' Go';
    }
}
