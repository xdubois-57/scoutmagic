<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Leadership\Service;

use Modules\Leadership\LeadershipRules;
use Modules\Leadership\Repository\LeadershipRepository;
use Modules\Leadership\Value\PersonLine;
use Modules\Leadership\Value\StaffFunctionRow;

/**
 * The Stewards page, which is really two pages depending on the date.
 *
 * From 1 September to 31 May, an intendant may be registered as an
 * occasional participant free of charge for a bounded number of days, so
 * the useful question is "how long has this registration been running?" and
 * the page is a countdown. From 1 June to 31 August the free window does
 * not apply — a registration costs a guest fee even for a single day — so a
 * countdown would be meaningless and the page is a reminder instead. No
 * amount is ever shown: what the fee is is the federation's business and it
 * changes.
 */
class StewardService
{
    public function __construct(
        private LeadershipRepository $repository,
        private ObligationsService $obligations
    ) {
    }

    public function isSummerRegime(\DateTimeImmutable $today): bool
    {
        return LeadershipRules::isSummerRegime($today);
    }

    /**
     * The registered stewards.
     *
     * From September to May, a countdown, longest-running first. The count
     * runs from `member_functions.start_date` — of THIS intendance
     * function. Somebody who changes from one intendance function to
     * another restarts at zero, which is accepted rather than worked
     * around: in practice a change of function is a fresh registration, and
     * stitching two periods together would mean deciding which changes
     * count as continuous, a judgement the data cannot support.
     *
     * **Under the summer regime there is no countdown at all**, and the
     * lines carry none — not even a quiet one in small print. The free
     * occasional-registration window does not apply between June and
     * August, so a number of days measured against it means nothing; a page
     * whose banner says "aucun décompte n'est affiché" while every line
     * shows one is worse than either half alone. The start date is still
     * stated, because a date is a fact rather than a countdown, and the
     * list falls back to alphabetical order.
     *
     * @param list<StaffFunctionRow> $staff
     * @return list<PersonLine>
     */
    public function registrations(array $staff, int $scoutYearId, \DateTimeImmutable $today): array
    {
        $today = $today->setTime(0, 0, 0);
        $summer = $this->isSummerRegime($today);

        $entries = [];
        foreach ($staff as $row) {
            if (!$row->isSteward()) {
                continue;
            }

            [$startDate, $isApproximate] = $this->resolveStart($row, $scoutYearId);

            if ($summer) {
                $entries[] = [
                    'days' => -1,
                    'line' => new PersonLine(
                        memberYearId: $row->memberYearId,
                        totem: $row->totem,
                        fullName: $row->fullName(),
                        sectionName: $row->sectionName,
                        detail: $row->functionLabel,
                        note: $this->summerNote($startDate, $isApproximate),
                    ),
                ];
                continue;
            }

            if ($startDate === null) {
                $entries[] = [
                    'days' => -1,
                    'line' => new PersonLine(
                        memberYearId: $row->memberYearId,
                        totem: $row->totem,
                        fullName: $row->fullName(),
                        sectionName: $row->sectionName,
                        detail: $row->functionLabel,
                        note: "Aucune date de début n'est encodée dans Desk et le site n'a rien enregistré non plus : "
                            . 'impossible de compter les jours. À vérifier dans Desk.',
                    ),
                ];
                continue;
            }

            $days = (int) $startDate->diff($today)->days;
            if ($today < $startDate) {
                $days = 0;
            }

            $entries[] = [
                'days' => $days,
                'line' => new PersonLine(
                    memberYearId: $row->memberYearId,
                    totem: $row->totem,
                    fullName: $row->fullName(),
                    sectionName: $row->sectionName,
                    detail: $row->functionLabel,
                    note: $this->countdownNote($days, $startDate, $isApproximate),
                    severity: $this->severityFor($days),
                    days: $days,
                ),
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            return ($b['days'] <=> $a['days'])
                ?: strcasecmp($a['line']->fullName, $b['line']->fullName);
        });

        return array_map(static fn (array $e): PersonLine => $e['line'], $entries);
    }

    /**
     * Stewards below the federation's minimum age, reported separately
     * rather than mixed into the countdown: it is a different question, it
     * is not about days, and burying it in a list sorted by something else
     * is how it gets missed.
     *
     * @param list<StaffFunctionRow> $staff
     * @return list<PersonLine>
     */
    public function underAgeStewards(array $staff, \DateTimeImmutable $today): array
    {
        $lines = [];
        $seen = [];
        foreach ($staff as $row) {
            if (!$row->isSteward() || isset($seen[$row->memberId])) {
                continue;
            }

            $age = $this->obligations->ageOn($row->birthDate, $today);
            if ($age === null || $age >= LeadershipRules::STEWARD_MIN_AGE) {
                continue;
            }
            $seen[$row->memberId] = true;

            $lines[] = new PersonLine(
                memberYearId: $row->memberYearId,
                totem: $row->totem,
                fullName: $row->fullName(),
                sectionName: $row->sectionName,
                detail: $row->functionLabel . ' — ' . $age . ' ans',
                note: 'En dessous de ' . LeadershipRules::STEWARD_MIN_AGE
                    . " ans, l'âge minimum attendu pour une fonction d'intendance.",
                severity: 'warning',
            );
        }

        usort($lines, static fn (PersonLine $a, PersonLine $b) => strcasecmp($a->fullName, $b->fullName));

        return $lines;
    }

    /**
     * The start date to count from, and whether it is the real one.
     *
     * Desk's own `start_date` when there is one. When there is not, the
     * earliest section period the site recorded for that member in this
     * scout year — which the Desk import writes the first time it sees
     * them, and which is therefore a **first appearance on the site**, not
     * a Desk registration date. The two are different things and the line
     * says which one it is showing, every time.
     *
     * There is deliberately no third fallback. `member_functions` is
     * overwritten wholesale on every import
     * (MemberYearRepository::replaceFunctions()), so no per-import history
     * of functions exists to reconstruct a date from; inventing one from
     * the scout year's start would produce a plausible number that is
     * simply not a fact about this person. A steward with neither date gets
     * no countdown and is told so.
     *
     * @return array{0: ?\DateTimeImmutable, 1: bool}
     */
    private function resolveStart(StaffFunctionRow $row, int $scoutYearId): array
    {
        $deskDate = $this->parseDate($row->functionStartDate);
        if ($deskDate !== null) {
            return [$deskDate, false];
        }

        $firstSeen = $this->parseDate(
            $this->repository->findEarliestSectionPeriodStart($row->memberId, $scoutYearId)
        );

        return [$firstSeen, $firstSeen !== null];
    }

    /**
     * The summer line: a date, never a duration, and never a threshold.
     * Same three cases as the countdown, minus the count.
     */
    private function summerNote(?\DateTimeImmutable $startDate, bool $isApproximate): string
    {
        if ($startDate === null) {
            return "Aucune date de début n'est encodée dans Desk.";
        }

        if ($isApproximate) {
            return "Aucune date de début n'est encodée dans Desk ; première apparition sur le site le "
                . $startDate->format('d/m/Y') . '.';
        }

        return 'Début de fonction encodé dans Desk : ' . $startDate->format('d/m/Y') . '.';
    }

    private function countdownNote(int $days, \DateTimeImmutable $startDate, bool $isApproximate): string
    {
        $plural = $days > 1 ? 's' : '';
        $sentence = 'Inscrit depuis ' . $days . ' jour' . $plural
            . ' (' . $startDate->format('d/m/Y') . ').';

        if ($days >= LeadershipRules::STEWARD_CRITICAL_DAYS) {
            $sentence .= ' Au-delà de ' . LeadershipRules::STEWARD_FREE_DAYS
                . " jours, l'inscription occasionnelle gratuite ne s'applique plus.";
        }

        if ($isApproximate) {
            $sentence .= " Aucune date de début n'est encodée dans Desk : "
                . 'cette date est celle de la première apparition de la personne sur le site, '
                . "pas une date d'inscription Desk.";
        }

        return $sentence;
    }

    private function severityFor(int $days): string
    {
        if ($days >= LeadershipRules::STEWARD_CRITICAL_DAYS) {
            return 'critical';
        }

        if ($days >= LeadershipRules::STEWARD_WARNING_DAYS) {
            return 'warning';
        }

        return 'normal';
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $value = trim($value);
        if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $value, $m) !== 1) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $m[1]);

        return $parsed === false ? null : $parsed;
    }
}
