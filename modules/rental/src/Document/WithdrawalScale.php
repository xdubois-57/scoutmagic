<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Core\Service\DateInput;

/**
 * What a renter owes for withdrawing, as one contract states it (issue
 * #977, annex 8.1 of the Walloon tourism code, point 8c).
 *
 * **Worked out in dates, never in phrases a reader has to reconcile.** The
 * scale has three stretches, each a run of days that follows the previous
 * one without overlapping it or leaving a gap:
 *
 * 1. up to the free-withdrawal deadline (`freeWithdrawalDays` before the
 *    arrival) — nothing owed;
 * 2. from the day after it until one month before the stay — fifty per cent
 *    of the price;
 * 3. the last month — seventy-five per cent.
 *
 * A deadline later than one month before the stay simply leaves the second
 * stretch empty, and the third starts the day after the deadline: a scale
 * written as fixed phrases (« plus de quatre mois avant… ») could not say
 * that without contradicting the free period it follows.
 *
 * **A deadline already gone is never offered.** A booking made, or a
 * contract generated, after the free-withdrawal deadline gets a contract
 * that says the free period does not apply to it — not a past date dressed
 * up as an option the renter can no longer take.
 */
final class WithdrawalScale
{
    private function __construct(
        /** The last free day, `Y-m-d`, or null when it had passed. */
        public readonly ?string $freeUntil,
        public readonly int $freeDays,
        /**
         * The fifty-per-cent stretch — its first day (null: from today) and
         * its last — or null when it is empty.
         *
         * @var array{0: ?string, 1: string}|null
         */
        private readonly ?array $half,
        /**
         * The first day of the seventy-five-per-cent stretch, null when it
         * applies from today, false when nothing is ever owed.
         */
        private readonly string|false|null $threeQuartersFrom
    ) {
    }

    /**
     * The scale of a stay arriving on $arrivalDate, as of $today — the day
     * the contract is generated. Null for an unreadable arrival.
     */
    public static function of(string $arrivalDate, int $freeDays, \DateTimeImmutable $today): ?self
    {
        $arrival = DateInput::iso($arrivalDate);
        if ($arrival === null) {
            return null;
        }

        $freeDays = max(0, $freeDays);
        $today = $today->setTime(0, 0);
        $deadline = $arrival->modify('-' . $freeDays . ' days');
        $free = $deadline >= $today ? $deadline : null;

        // The first day an indemnity can be owed: the day after the free
        // period, or — without one — today.
        $owedFrom = $free !== null ? $free->modify('+1 day') : $today;
        $lastHalfDay = self::oneMonthBefore($arrival);

        $half = $owedFrom <= $lastHalfDay
            ? [$free !== null ? $owedFrom->format('Y-m-d') : null, $lastHalfDay->format('Y-m-d')]
            : null;

        $threeQuartersStart = max($owedFrom, $lastHalfDay->modify('+1 day'));
        $threeQuartersFrom = match (true) {
            $threeQuartersStart > $arrival => false,
            $half === null && $free === null => null,
            default => $threeQuartersStart->format('Y-m-d'),
        };

        return new self($free?->format('Y-m-d'), $freeDays, $half, $threeQuartersFrom);
    }

    /** The sentence on the free period: its last day, or that it does not apply. */
    public function freeSentence(): string
    {
        if ($this->freeUntil === null) {
            return 'La période de désistement sans indemnité ne s\'applique pas à cette réservation : '
                . 'sa date limite, ' . ($this->freeDays === 1 ? '1 jour' : $this->freeDays . ' jours')
                . ' avant l\'arrivée, était passée quand le présent contrat a été établi.';
        }

        return 'Jusqu\'au ' . self::french($this->freeUntil)
            . ' inclus, il le fait sans indemnité et les sommes versées lui sont remboursées.';
    }

    /** The stretches an indemnity is owed over, with their dates. */
    public function scaleSentence(): string
    {
        if ($this->threeQuartersFrom === false) {
            return 'aucune, le désistement restant sans indemnité jusqu\'au début du séjour.';
        }

        $parts = [];
        if ($this->half !== null) {
            [$from, $to] = $this->half;
            $parts[] = ($from !== null ? 'du ' . self::french($from) . ' au ' : 'jusqu\'au ')
                . self::french($to) . ' inclus, cinquante pour cent du prix de la location';
        }
        $parts[] = ($this->threeQuartersFrom !== null
                ? 'à partir du ' . self::french($this->threeQuartersFrom) . ', '
                : '')
            . 'soixante-quinze pour cent du prix de la location';

        return implode(' ; ', $parts) . '.';
    }

    /**
     * The same day of the previous month, clamped to that month's last day:
     * `modify('-1 month')` would turn 31 March into 3 March.
     */
    private static function oneMonthBefore(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $previous = $date->modify('first day of last month');
        $day = min((int) $date->format('j'), (int) $previous->format('t'));

        return $previous->setDate((int) $previous->format('Y'), (int) $previous->format('n'), $day);
    }

    private static function french(string $isoDate): string
    {
        return (string) DateInput::iso($isoDate)?->format('d/m/Y');
    }
}
