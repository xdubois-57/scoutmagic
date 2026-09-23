<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Service\DateInput;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Every way a template is allowed to render a stored date.
 *
 * **Twig's own `|date()` is banned in templates**
 * (`Tests\Security\TemplateDateReadingRatchetTest`), for the reason
 * `Core\Service\DateInput` exists: it builds a `DateTime` from whatever
 * it is handed, so a stored value that is not a date raises, and Twig
 * wraps that in a `RuntimeError` which takes the WHOLE page down with a
 * 500 rather than blanking the one field. A member's `birth_date` comes
 * from the federation's roster import, not from a form this project
 * validates; one arrived as `15/09/2014` and every visit to that member's
 * health sheet answered 500.
 *
 * So each filter here reads through `DateInput::fromStorage()` and
 * answers `''` for what it refuses — the same thing they have always
 * answered for null. An empty field is one somebody can see is empty.
 *
 * **An extension rather than closures inside `TwigFactory`.** These
 * filters used to be registered inline there, which is fine for
 * production and is exactly what left 137 hand-built test environments
 * free to stub `french_date` as a passthrough and know nothing of the
 * rest: adding a filter to the real factory then broke five suites that
 * render real templates. One `addExtension(new DateFilterExtension())`
 * is now the whole of it, on both sides, so a test renders what a visitor
 * gets (issue #465 covers the environments still hand-rolled).
 */
class DateFilterExtension extends AbstractExtension
{
    private const MONTHS = [
        1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril', 5 => 'mai', 6 => 'juin',
        7 => 'juillet', 8 => 'août', 9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre',
    ];

    /**
     * @return array<int, TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            // The long form, for prose: "12 juillet 2026". No intl
            // extension required — a 12-entry lookup is not worth a
            // dependency (ARCHITECTURE.md).
            new TwigFilter('french_date', [self::class, 'frenchDate']),
            // The short forms. Two filters instead of the 30-odd
            // hand-written |date('d/m/Y…') calls that had drifted into
            // five spellings ('d/m/Y', 'd/m/Y H:i', 'd/m/Y à H:i',
            // 'd/m/Y à H\hi'): one canonical rendering each, so two
            // adjacent pages stop disagreeing about what a timestamp
            // looks like.
            new TwigFilter('date_fr', [self::class, 'dateFr']),
            new TwigFilter('datetime_fr', [self::class, 'datetimeFr']),
            // The clock alone, for the few places that print a date and
            // its time apart ("Calculé le 12/07/2026 à 09:14").
            new TwigFilter('time_fr', [self::class, 'timeFr']),
            // The machine forms, for the `value` of an `<input
            // type="date">` and an `<input type="datetime-local">`. Not
            // French, and not interchangeable with the three above: a
            // browser reads exactly `Y-m-d` and `Y-m-d\TH:i` and silently
            // shows an empty field for anything else — so `d/m/Y` in a
            // `value` renders an input that looks filled and posts
            // nothing.
            new TwigFilter('iso_date', [self::class, 'isoDate']),
            new TwigFilter('iso_datetime_local', [self::class, 'isoDatetimeLocal']),
        ];
    }

    public static function frenchDate(mixed $date): string
    {
        $dateTime = self::read($date);
        if ($dateTime === null) {
            return '';
        }

        return (int) $dateTime->format('j')
            . ' ' . self::MONTHS[(int) $dateTime->format('n')]
            . ' ' . $dateTime->format('Y');
    }

    public static function dateFr(mixed $date): string
    {
        return self::read($date)?->format('d/m/Y') ?? '';
    }

    public static function datetimeFr(mixed $date): string
    {
        return self::read($date)?->format('d/m/Y à H:i') ?? '';
    }

    public static function timeFr(mixed $date): string
    {
        return self::read($date)?->format('H:i') ?? '';
    }

    public static function isoDate(mixed $date): string
    {
        return self::read($date)?->format('Y-m-d') ?? '';
    }

    public static function isoDatetimeLocal(mixed $date): string
    {
        return self::read($date)?->format('Y-m-d\TH:i') ?? '';
    }

    /**
     * The one door. `new DateTimeImmutable($v)` throws on a malformed
     * string AND answers *now* for an empty one, which is how a missing
     * value renders as today's date and is believed; `fromStorage()`
     * answers null for both (SECURITY.md § 35).
     */
    public static function read(mixed $date): ?\DateTimeInterface
    {
        if ($date instanceof \DateTimeInterface) {
            return $date;
        }

        return DateInput::fromStorage(is_scalar($date) ? (string) $date : null);
    }
}
