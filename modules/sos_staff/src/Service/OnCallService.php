<?php

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Scheduler\SchedulerService;
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

    public function __construct(
        private OnCallRepository $repository,
        private SchedulerService $schedulerService,
        private SosSettingsService $settingsService
    ) {
    }

    /**
     * @return array{
     *     days: array<int, array{date: string, day_number: int, day_name: string, is_today: bool, is_weekend: bool}>,
     *     states: array<string, array<int, string>>
     * }
     */
    public function getMonthGrid(int $year, int $month): array
    {
        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        $assignments = $this->repository->findForRange($firstOfMonth->format('Y-m-d'), $lastOfMonth->format('Y-m-d'));
        $states = [];
        foreach ($assignments as $assignment) {
            $states[$assignment->date][$assignment->memberId] = $assignment->state;
        }

        $dayNames = ['Lun', 'Mar', 'Mer', 'Jeu', 'Ven', 'Sam', 'Dim'];
        $today = (new \DateTimeImmutable())->format('Y-m-d');

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
     * @return array{transitions: array<int, array{date: string, member_id: ?int, previous_member_id: ?int, run_at: \DateTimeImmutable}>, today_transition: ?array{date: string, member_id: ?int, previous_member_id: ?int, run_at: \DateTimeImmutable}, today_due_now: bool}
     */
    public function saveMonth(int $year, int $month, array $cells, array $orderedStaffMemberIds, int $scoutYearId): array
    {
        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastOfMonth = $firstOfMonth->modify('last day of this month');

        $assignments = array_map(
            fn(array $cell) => new OnCallAssignment((int) $cell['member_id'], (string) $cell['date'], (string) $cell['state']),
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
     * @param int[] $orderedStaffMemberIds
     * @return array{transitions: array<int, array{date: string, member_id: ?int, previous_member_id: ?int, run_at: \DateTimeImmutable}>, today_transition: ?array{date: string, member_id: ?int, previous_member_id: ?int, run_at: \DateTimeImmutable}, today_due_now: bool}
     */
    private function computeAndScheduleTransitions(int $year, int $month, array $orderedStaffMemberIds, int $scoutYearId): array
    {
        $firstOfMonth = new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $lastOfMonth = $firstOfMonth->modify('last day of this month');
        $prevMonthLastDay = $firstOfMonth->modify('-1 day');

        $monthAssignments = $this->repository->findForRange($firstOfMonth->format('Y-m-d'), $lastOfMonth->format('Y-m-d'));
        $byDate = [];
        foreach ($monthAssignments as $assignment) {
            $byDate[$assignment->date][] = $assignment;
        }

        $previousTarget = $this->resolveTarget(
            $this->repository->findForDate($prevMonthLastDay->format('Y-m-d')),
            $orderedStaffMemberIds
        );

        $this->cancelPendingTransitionsInRange($firstOfMonth->format('Y-m-d'), $lastOfMonth->format('Y-m-d'));

        $transitionHour = $this->settingsService->getTransitionHour();
        $transitions = [];
        $cursor = $firstOfMonth;
        while ($cursor <= $lastOfMonth) {
            $dateStr = $cursor->format('Y-m-d');
            $target = $this->resolveTarget($byDate[$dateStr] ?? [], $orderedStaffMemberIds);

            if ($target !== $previousTarget) {
                $runAt = new \DateTimeImmutable("{$dateStr} {$transitionHour}:00");
                $this->schedulerService->schedule(
                    self::MODULE_ID,
                    self::TASK_KEY,
                    $runAt,
                    ['date' => $dateStr, 'member_id' => $target, 'previous_member_id' => $previousTarget, 'scout_year_id' => $scoutYearId],
                    $dateStr
                );
                $transitions[] = ['date' => $dateStr, 'member_id' => $target, 'previous_member_id' => $previousTarget, 'run_at' => $runAt];
            }

            $previousTarget = $target;
            $cursor = $cursor->modify('+1 day');
        }

        $todayStr = (new \DateTimeImmutable())->format('Y-m-d');
        $todayTransition = null;
        foreach ($transitions as $transition) {
            if ($transition['date'] === $todayStr) {
                $todayTransition = $transition;
                break;
            }
        }

        return [
            'transitions' => $transitions,
            'today_transition' => $todayTransition,
            'today_due_now' => $todayTransition !== null && new \DateTimeImmutable() >= $todayTransition['run_at'],
        ];
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
