<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Value\PersonLine;
use Modules\Leadership\Value\StaffFunctionRow;

/**
 * The Obligations page: the one thing that can be anticipated, and the one
 * thing Desk has already decided.
 *
 * What this service refuses to do is as much of its specification as what
 * it does. It never says a CQA or an extrait is in order, missing, valid or
 * expired — the site has no such information and never stores any. It never
 * says "en ordre" about somebody who is not flagged as a candidate either:
 * the absence of a candidate prefix at the last import says what was true
 * at the last import, which is not the same statement as "this person is in
 * order today", and displaying the second when you only know the first is
 * how a page like this becomes actively dangerous.
 */
class ObligationsService
{
    public function __construct(private CandidateDetector $candidateDetector)
    {
    }

    /**
     * Everybody in the unit's staff whose 20th birthday falls inside the
     * alert window.
     *
     * The only genuinely anticipable event on this page, and therefore the
     * main block: turning 20 is a date, known in advance, after which an
     * extrait de casier judiciaire is legally required. Everything else
     * here is Desk reporting something that has already happened.
     *
     * **Intendants are included**, and there is no `isAnimation()` filter
     * here at all. The requirement follows from being an adult in contact
     * with minors on a camp, not from carrying an animation function: an
     * intendant who turns 20 in three weeks needs the same extrait as an
     * animateur, and filtering them out made this page quietly answer a
     * narrower question than its title asks.
     *
     * The age used is the real one, computed from the birth date — never
     * the effective age, which carries a chief's scout-year offset. That
     * offset exists to place a child in the right branch; a legal threshold
     * does not move because somebody repeated a year.
     *
     * @param list<StaffFunctionRow> $staff
     * @return list<PersonLine>
     */
    public function upcomingAdultBirthdays(array $staff, \DateTimeImmutable $today): array
    {
        $today = $this->startOfDay($today);
        $windowEnd = $today->modify('+' . LeadershipRules::adultAgeAlertDays() . ' days');

        $entries = [];
        $seen = [];
        foreach ($staff as $row) {
            if (isset($seen[$row->memberId])) {
                continue;
            }

            $birthday = $this->nthBirthday($row->birthDate, LeadershipRules::ADULT_AGE);
            if ($birthday === null || $birthday < $today || $birthday > $windowEnd) {
                continue;
            }
            $seen[$row->memberId] = true;

            $days = (int) $today->diff($birthday)->days;
            $entries[] = [
                'birthday' => $birthday,
                'line' => new PersonLine(
                    memberYearId: $row->memberYearId,
                    totem: $row->totem,
                    fullName: $row->fullName(),
                    email: $row->email,
                    sectionName: $row->sectionName,
                    detail: $row->functionLabel,
                    note: $days === 0
                        ? "Atteint " . LeadershipRules::ADULT_AGE . " ans aujourd'hui."
                        : 'Atteint ' . LeadershipRules::ADULT_AGE . ' ans dans ' . $days . ' jour' . ($days > 1 ? 's' : '') . '.',
                    severity: $days <= 7 ? 'warning' : 'normal',
                    days: $days,
                    daysDirection: PersonLine::DAYS_UNTIL,
                ),
            ];
        }

        usort($entries, static fn (array $a, array $b) => $a['birthday'] <=> $b['birthday']);

        return array_map(static fn (array $e): PersonLine => $e['line'], $entries);
    }

    /**
     * How many of the unit's staff the birthday scan could say nothing
     * about, because Desk carries no usable birth date for them.
     *
     * The count is the honest footnote under a list of dates: an empty
     * block means "nobody turns 20 soon" only if every birth date is
     * known, and it silently means "we could not tell" for as many people
     * as this returns. Counted per person, on the same rule the scan
     * itself uses, so the two can never disagree.
     *
     * @param list<StaffFunctionRow> $staff
     */
    public function countWithoutBirthDate(array $staff): int
    {
        $seen = [];
        foreach ($staff as $row) {
            if (isset($seen[$row->memberId]) || $this->parseBirthDate($row->birthDate) !== null) {
                continue;
            }
            $seen[$row->memberId] = true;
        }

        return count($seen);
    }

    /**
     * Everybody Desk flagged as a candidate at the last import.
     *
     * Not a list of arrivals, and the page says so: the flag comes back on
     * its own when a CQA or an extrait expires, so somebody who has been
     * animating for fifteen years belongs here exactly as much as somebody
     * who started in September.
     *
     * One line per candidate FUNCTION, not per person: the prefix sits on a
     * function, and somebody flagged on one of their two functions is a
     * different situation from somebody flagged on both.
     *
     * @param list<StaffFunctionRow> $staff
     * @return list<PersonLine>
     */
    public function candidates(array $staff, \DateTimeImmutable $today): array
    {
        $lines = [];
        foreach ($staff as $row) {
            if (!$this->candidateDetector->isCandidateLabel($row->functionLabel)) {
                continue;
            }

            $age = $this->ageOn($row->birthDate, $today);

            $lines[] = new PersonLine(
                memberYearId: $row->memberYearId,
                totem: $row->totem,
                fullName: $row->fullName(),
                email: $row->email,
                sectionName: $row->sectionName,
                detail: $age === null
                    ? $row->functionLabel . ' — âge inconnu'
                    : $row->functionLabel . ' — ' . $age . ' ans',
                note: $this->candidateNote($age),
            );
        }

        usort($lines, static fn (PersonLine $a, PersonLine $b) => TextMatcher::compareNames($a->fullName, $b->fullName));

        return $lines;
    }

    /**
     * The message shown beside a candidate — and nothing more precise than
     * this, because nothing more precise is knowable from here.
     *
     * Under 20, "signer" is the right verb and the extrait cannot be the
     * cause, since it is not yet required: whatever the reason, it is the
     * CQA, whether that means a first signature or a renewal. Desk uses the
     * same label either way, so age is only ever a way of EXCLUDING the
     * extrait — never of identifying which of the two is actually missing.
     *
     * An unknown birth date therefore gets the wider message, not the
     * narrower one: with no age there is nothing to exclude, and guessing
     * would be exactly the estimate this module refuses to show.
     */
    private function candidateNote(?int $age): string
    {
        if ($age !== null && $age < LeadershipRules::ADULT_AGE) {
            return 'CQA à signer';
        }

        return 'CQA ou extrait — à vérifier dans Desk';
    }

    /**
     * The date somebody turns $age, from their decrypted Desk birth date.
     * Null when the date is absent or unparseable — never a guess.
     */
    private function nthBirthday(?string $birthDate, int $age): ?\DateTimeImmutable
    {
        $birth = $this->parseBirthDate($birthDate);

        return $birth?->modify('+' . $age . ' years');
    }

    /** Real age today, or null when the birth date is absent or unparseable. */
    public function ageOn(?string $birthDate, \DateTimeImmutable $today): ?int
    {
        $birth = $this->parseBirthDate($birthDate);
        if ($birth === null) {
            return null;
        }

        return (int) $birth->diff($this->startOfDay($today))->y;
    }

    /**
     * Desk exports a birth date in more than one shape depending on the
     * export, so both are accepted here — the same two
     * Core\Member\MemberYearService::extractBirthYear() already handles,
     * except that a legal threshold needs the day and the month too, which
     * that helper deliberately does not return.
     */
    private function parseBirthDate(?string $birthDate): ?\DateTimeImmutable
    {
        if ($birthDate === null || trim($birthDate) === '') {
            return null;
        }

        $birthDate = trim($birthDate);

        // A datetime column that came through as "1998-03-15 00:00:00"
        // is the same date; drop the time part before matching.
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[T ]/', $birthDate, $m) === 1) {
            $birthDate = $m[1];
        }

        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y'] as $format) {
            // '!' zeroes the time fields; the round-trip check rejects
            // PHP's overflow tolerance, which would otherwise turn an
            // impossible 31/02 into 3 March rather than into "unknown".
            $parsed = \DateTimeImmutable::createFromFormat('!' . $format, $birthDate);
            if ($parsed !== false && $parsed->format($format) === $birthDate) {
                return $parsed;
            }
        }

        return null;
    }

    /**
     * Midnight of the given day. Both public methods compare dates, not
     * instants: a birthday 41 days and 3 hours away must count as 41 days,
     * and must not fall in or out of the window depending on what time of
     * day the page happens to be opened.
     */
    private function startOfDay(\DateTimeImmutable $moment): \DateTimeImmutable
    {
        return $moment->setTime(0, 0, 0);
    }
}
