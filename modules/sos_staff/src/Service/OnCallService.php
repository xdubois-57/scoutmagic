<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Scheduler\SchedulerService;
use Core\Service\DateInput;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Repository\OnCallRepository;

/**
 * The duty-roster grid (module spec §2.6) and the transition computation
 * that drives redirect scheduling (§3). Sparse storage: a member with no
 * row for a day is "available" (unmarked, the default state).
 */
class OnCallService
{
    public const MODULE_ID = 'sos_staff';
    public const TASK_KEY = 'apply_redirect';

    /**
     * @param (\Closure(): \DateTimeImmutable)|null $clock « now », for the
     *        line between a transition still to schedule and one already
     *        due (tests pin it; production reads the wall clock)
     */
    public function __construct(
        private OnCallRepository $repository,
        private SchedulerService $schedulerService,
        private SosSettingsService $settingsService,
        private ?\Closure $clock = null
    ) {
    }

    /**
     * @return array{
     *     days: array<int, array{date: string, day_number: int, day_name: string, is_today: bool, is_weekend: bool}>,
     *     states: array<string, array<int, string>>
     * }
     */
    public function getMonthGrid(int $year, int $month, ?\DateTimeImmutable $at = null): array
    {
        $firstOfMonth = DateInput::firstOfMonth($year, $month);
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        $assignments = $this->repository->findForRange($firstOfMonth->format('Y-m-d'), $lastOfMonth->format('Y-m-d'));
        $states = [];
        foreach ($assignments as $assignment) {
            $states[$assignment->date][$assignment->memberId] = $assignment->state;
        }

        $dayNames = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        $today = ($at ?? new \DateTimeImmutable())->format('Y-m-d');

        $days = [];
        $cursor = $firstOfMonth;
        while ($cursor <= $lastOfMonth) {
            $dateStr = $cursor->format('Y-m-d');
            $isoWeekday = (int) $cursor->format('N'); // 1 (Mon) .. 7 (Sun)
            $days[] = [
                'date' => $dateStr,
                'day_number' => (int) $cursor->format('j'),
                'day_name' => $dayNames[$isoWeekday - 1],
                'is_today' => $dateStr === $today,
                'is_weekend' => $isoWeekday >= 6,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return ['days' => $days, 'states' => $states];
    }

    /**
     * Save the complete month state (module spec §2.6: every click posts
     * the full month, not a per-cell diff) and recompute the redirect
     * transitions it implies (§3).
     *
     * @param array<int, array{member_id: int, date: string, state: string}> $cells
     * @param int[] $orderedStaffMemberIds roster order — decides who "wins"
     *              on a day with multiple people on call (§2.6 "unicité de
     *              garde": only the first is used for the redirect)
     * @param int $scoutYearId stored in each scheduled transition's payload
     *            so Task\ApplyRedirectHandler can resolve member profiles
     *            without having to re-derive the scout year from a date
     * @return array{
     *     transitions: array<
     *         int,
     *         array{date: string, member_id: ?int, previous_member_id: ?int, run_at: \DateTimeImmutable}
     *     >
     * }
     *
     * **What is due NOW is not this method's job** (issue #1102). It only
     * schedules what is still to come; putting the line where the planning
     * says it should be at this moment is RedirectReconciler's, which reads
     * where the line really is. The caller runs it right after this.
     */
    public function saveMonth(
        int $year,
        int $month,
        array $cells,
        array $orderedStaffMemberIds,
        int $scoutYearId
    ): array
    {
        $firstOfMonth = DateInput::firstOfMonth($year, $month);
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        $assignments = array_map(
            fn(array $cell) => new OnCallAssignment(
                (int) $cell['member_id'],
                (string) $cell['date'],
                (string) $cell['state']
            ),
            $cells
        );
        $this->repository->replaceRange($firstOfMonth->format('Y-m-d'), $lastOfMonth->format('Y-m-d'), $assignments);

        return $this->computeAndScheduleTransitions($year, $month, $orderedStaffMemberIds, $scoutYearId);
    }

    /**
     * The live target for a single date (null = default number governs) —
     * used by the admin controller to decide whether changing the default
     * number must be applied immediately (module spec §2.3: "si le jour
     * courant est concerné, la redirection est appliquée immédiatement").
     *
     * @param int[] $orderedStaffMemberIds
     */
    public function resolveTargetForDate(string $date, array $orderedStaffMemberIds): ?int
    {
        return $this->resolveTarget($this->repository->findForDate($date), $orderedStaffMemberIds);
    }

    /**
     * Who the planning says should be receiving the calls AT $at — the
     * "attendu" the dashboard compares the provider's real state with
     * (issue #1026).
     *
     * **Not simply « today's target ».** A day's duty starts at the
     * changeover hour, not at midnight: computeAndScheduleTransitions()
     * schedules each change at `{date} {hour}`. Before that hour the line
     * is still the previous day's, so answering with today's target would
     * flag a perfectly healthy redirection every morning of a handover, and
     * the correction would run the handover ahead of its own scheduled
     * transition (and notify early). `previous_member_id` is the holder
     * before that one — the same pair a scheduled transition carries.
     *
     * @param int[] $orderedStaffMemberIds
     * @return array{date: string, member_id: ?int, previous_member_id: ?int}
     */
    public function resolveExpectedTargetAt(\DateTimeImmutable $at, array $orderedStaffMemberIds): array
    {
        $today = $at->format('Y-m-d');
        // Built exactly as the scheduler builds a transition's run_at, so
        // the two cannot disagree about which side of the hour a moment is.
        $changeover = DateInput::requireFromStorage(
            $today . ' ' . $this->settingsService->getTransitionHour() . ':00',
            'sos_staff transition_hour'
        );
        $effective = $at >= $changeover ? $at : $at->modify('-1 day');
        $date = $effective->format('Y-m-d');
        $previousDate = $effective->modify('-1 day')->format('Y-m-d');

        return [
            'date' => $date,
            'member_id' => $this->resolveTargetForDate($date, $orderedStaffMemberIds),
            'previous_member_id' => $this->resolveTargetForDate($previousDate, $orderedStaffMemberIds),
        ];
    }

    /**
     * Who actually receives the calls, for every day of a month, plus how
     * many people are marked on call that day.
     *
     * The "single on-call person" rule (module spec §2.6) is one line of
     * roster-order arithmetic and it is the ONE thing a duty planning
     * screen has to get right — so it is resolved here, once, and handed
     * to the page ready to render. A template recomputing it from the
     * states grid would be a second implementation of the rule that
     * decides where the unit's emergency calls land, free to drift from
     * the one computeAndScheduleTransitions() actually schedules against.
     *
     * `oncall_count` is what lets the page flag a day where several people
     * are marked and only the first is used — the honest way to show that
     * the extra marks change nothing.
     *
     * @param int[] $orderedStaffMemberIds roster order, as saveMonth() takes it
     * @return array<string, array{member_id: ?int, oncall_count: int}> keyed by ISO date
     */
    public function resolveTargetsForMonth(int $year, int $month, array $orderedStaffMemberIds): array
    {
        $firstOfMonth = DateInput::firstOfMonth($year, $month);
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        $byDate = [];
        $assignmentsInRange = $this->repository->findForRange(
            $firstOfMonth->format('Y-m-d'),
            $lastOfMonth->format('Y-m-d')
        );
        foreach ($assignmentsInRange as $assignment) {
            $byDate[$assignment->date][] = $assignment;
        }

        $targets = [];
        $cursor = $firstOfMonth;
        while ($cursor <= $lastOfMonth) {
            $dateStr = $cursor->format('Y-m-d');
            $dayAssignments = $byDate[$dateStr] ?? [];

            $onCallCount = 0;
            foreach ($dayAssignments as $assignment) {
                if ($assignment->state === OnCallAssignment::STATE_ONCALL) {
                    $onCallCount++;
                }
            }

            $targets[$dateStr] = [
                'member_id' => $this->resolveTarget($dayAssignments, $orderedStaffMemberIds),
                'oncall_count' => $onCallCount,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $targets;
    }

    /**
     * Purge duty data older than one year (module spec §6): assignment
     * rows and the scheduled_actions rows this module created for them.
     * Calendar-sync cleanup is a separate concern (CalendarSyncService).
     */
    public function cleanupOlderThanOneYear(): int
    {
        $cutoff = new \DateTimeImmutable('-1 year');
        $deleted = $this->repository->deleteOlderThan($cutoff->format('Y-m-d'));
        $this->schedulerService->deleteOlderThan(self::MODULE_ID, self::TASK_KEY, $cutoff);
        return $deleted;
    }

    /**
     * The transitions the planning implies, from the first day of the saved
     * month to the last day of the NEXT one (issue #1102).
     *
     * **Why the next month too.** Its 1st is a change or not depending on
     * the last day of this one: Alice on the 31st and Alice on the 1st is no
     * transition, Bob on the 31st and Alice on the 1st is one. Saving only
     * the month that was edited left the 1st as it was computed when the
     * next month was saved — so correcting the 31st after August was
     * planned handed the whole camp to Bob. A month nobody planned at all
     * is covered by the same pass: whoever is on call on the 31st must
     * still hand back to the default number on the 1st. The month after
     * that does not depend on anything this save changed.
     *
     * **Only transitions still to come are scheduled.** A change whose
     * moment has passed is not replayed: SchedulerRunner would run every
     * one of them as overdue, in a row, each one notifying its pair, only
     * to end where the line should be now — which RedirectReconciler puts
     * it, once, after reading where it actually is.
     *
     * @param int[] $orderedStaffMemberIds
     * @return array{
     *     transitions: array<
     *         int,
     *         array{date: string, member_id: ?int, previous_member_id: ?int, run_at: \DateTimeImmutable}
     *     >
     * }
     */
    private function computeAndScheduleTransitions(
        int $year,
        int $month,
        array $orderedStaffMemberIds,
        int $scoutYearId
    ): array
    {
        $firstOfMonth = DateInput::firstOfMonth($year, $month);
        $lastDay = $firstOfMonth->modify('first day of next month')->modify('last day of this month');
        $dayBefore = $firstOfMonth->modify('-1 day');

        $assignments = $this->repository->findForRange(
            $firstOfMonth->format('Y-m-d'),
            $lastDay->format('Y-m-d')
        );
        $byDate = [];
        foreach ($assignments as $assignment) {
            $byDate[$assignment->date][] = $assignment;
        }

        $previousTarget = $this->resolveTarget(
            $this->repository->findForDate($dayBefore->format('Y-m-d')),
            $orderedStaffMemberIds
        );

        $this->cancelPendingTransitionsInRange($firstOfMonth->format('Y-m-d'), $lastDay->format('Y-m-d'));

        $now = $this->now();
        $transitionHour = $this->settingsService->getTransitionHour();
        $transitions = [];
        $cursor = $firstOfMonth;
        while ($cursor <= $lastDay) {
            $dateStr = $cursor->format('Y-m-d');
            $target = $this->resolveTarget($byDate[$dateStr] ?? [], $orderedStaffMemberIds);

            if ($target !== $previousTarget) {
                $runAt = new \DateTimeImmutable("{$dateStr} {$transitionHour}:00");
                if ($runAt > $now) {
                    $this->schedulerService->schedule(
                        self::MODULE_ID,
                        self::TASK_KEY,
                        $runAt,
                        [
                            'date' => $dateStr,
                            'member_id' => $target,
                            'previous_member_id' => $previousTarget,
                            'scout_year_id' => $scoutYearId
                        ],
                        $dateStr
                    );
                    $transitions[] = [
                        'date' => $dateStr,
                        'member_id' => $target,
                        'previous_member_id' => $previousTarget,
                        'run_at' => $runAt
                    ];
                }
            }

            $previousTarget = $target;
            $cursor = $cursor->modify('+1 day');
        }

        return ['transitions' => $transitions];
    }

    /**
     * The next transition still to run, as saved — what the planning page
     * announces after a save (« prochaine bascule »). Null when none is
     * pending.
     *
     * @return array{date: string, member_id: ?int, run_at: \DateTimeImmutable}|null
     */
    public function nextPendingTransition(): ?array
    {
        $now = $this->now();
        $next = null;
        foreach ($this->schedulerService->findAllForTask(self::MODULE_ID, self::TASK_KEY, 1000) as $row) {
            if ($row['status'] !== 'pending') {
                continue;
            }
            $runAt = DateInput::requireFromStorage((string) $row['run_at'], 'run_at');
            if ($runAt <= $now || ($next !== null && $runAt >= $next['run_at'])) {
                continue;
            }
            $payload = json_decode((string) $row['payload'], true);
            $memberId = is_array($payload) && isset($payload['member_id']) ? (int) $payload['member_id'] : null;
            $next = ['date' => (string) $row['reference'], 'member_id' => $memberId, 'run_at' => $runAt];
        }

        return $next;
    }

    private function now(): \DateTimeImmutable
    {
        return $this->clock !== null ? ($this->clock)() : new \DateTimeImmutable();
    }

    /**
     * First (by roster order) member marked "oncall" that day, or null —
     * null means "no one on call, use the default number" (module spec
     * §2.6/§3), not "no data".
     *
     * @param OnCallAssignment[] $dayAssignments
     * @param int[] $orderedStaffMemberIds
     */
    private function resolveTarget(array $dayAssignments, array $orderedStaffMemberIds): ?int
    {
        $onCallMemberIds = [];
        foreach ($dayAssignments as $assignment) {
            if ($assignment->state === OnCallAssignment::STATE_ONCALL) {
                $onCallMemberIds[$assignment->memberId] = true;
            }
        }

        foreach ($orderedStaffMemberIds as $memberId) {
            if (isset($onCallMemberIds[$memberId])) {
                return $memberId;
            }
        }

        return null;
    }

    private function cancelPendingTransitionsInRange(string $fromDate, string $toDate): void
    {
        foreach ($this->schedulerService->findAllForTask(self::MODULE_ID, self::TASK_KEY, 1000) as $row) {
            if ($row['status'] !== 'pending') {
                continue;
            }
            $reference = $row['reference'] ?? null;
            if ($reference !== null && $reference >= $fromDate && $reference <= $toDate) {
                $this->schedulerService->cancel((int) $row['id']);
            }
        }
    }
}
