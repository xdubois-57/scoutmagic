<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Calendar;

use Core\Member\MemberService;
use Core\Service\DateInput;
use Modules\Calendar\Api\CalendarDirectoryInterface;
use Modules\Calendar\Api\VirtualEvent;
use Modules\Calendar\Api\VirtualEventProviderInterface;
use Modules\Calendar\Api\VirtualEventViewer;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Repository\OnCallRepository;

/**
 * Publishes the SOS duty roster onto the calendar module's default
 * "Animateurs" calendar as **virtual events** (ARCHITECTURE.md §7.6,
 * module spec §5): consecutive on-call days for the same member merge into
 * one event per streak, computed live from `sos_oncall_assignments`.
 *
 * This replaces the former `Service\CalendarSyncService`, which WROTE real
 * rows into `calendar_events` through the calendar module's own services —
 * the only cross-module write path in the application, and the reason
 * `CalendarEventService` grew a role-less "system caller" bypass of its
 * per-calendar write check. With the roster as the single source of truth
 * there is no copy to rebuild on every grid save, nothing to desynchronise,
 * and no bookkeeping table (`sos_calendar_sync`) to carry event ids around.
 *
 * Duty events are consequently not editable from the calendar page — which
 * is the intended behaviour: the duty grid is where the rota changes, and a
 * calendar-side edit could never reach the telephony redirect anyway.
 *
 * Degradation needs no special case at either end (§7.6): with `calendar`
 * disabled nobody calls this provider, and while the default "Animateurs"
 * calendar has not been created yet — or the viewer is not looking at it —
 * the provider answers with an empty array.
 */
class SosVirtualEventProvider implements VirtualEventProviderInterface
{
    private const EVENT_TITLE_PREFIX = "SOS Staff d'U : ";

    /**
     * How far beyond the requested window assignments are read, so a
     * streak that starts before the window (or ends after it) still
     * carries its true start and end dates instead of being truncated at
     * the window edge. One duty streak is a human's consecutive on-call
     * run — bounded in practice by the length of a holiday, so two months
     * of margin is comfortable and still one cheap query on a sparse,
     * >1-year-purged table.
     */
    private const STREAK_MARGIN_DAYS = 62;

    public function __construct(
        private OnCallRepository $onCallRepository,
        private MemberService $memberService,
        private CalendarDirectoryInterface $calendarDirectory
    ) {
    }

    public function providerId(): string
    {
        return 'sos_staff';
    }

    /**
     * @return VirtualEvent[]
     */
    public function findVirtualEvents(
        \DateTimeImmutable $windowStart,
        \DateTimeImmutable $windowEnd,
        VirtualEventViewer $viewer
    ): array {
        $calendarId = $this->calendarDirectory->defaultCalendarId();
        if ($calendarId === null || !$viewer->seesCalendar($calendarId)) {
            return [];
        }

        // One query for the whole window (§7.6), margin included.
        $assignments = $this->onCallRepository->findForRange(
            $windowStart->modify('-' . self::STREAK_MARGIN_DAYS . ' days')->format('Y-m-d'),
            $windowEnd->modify('+' . self::STREAK_MARGIN_DAYS . ' days')->format('Y-m-d')
        );

        $onCallDatesByMember = [];
        foreach ($assignments as $assignment) {
            if ($assignment->state === OnCallAssignment::STATE_ONCALL) {
                $onCallDatesByMember[$assignment->memberId][] = $assignment->date;
            }
        }

        $from = $windowStart->format('Y-m-d');
        $to = $windowEnd->format('Y-m-d');
        $events = [];
        foreach ($onCallDatesByMember as $memberId => $dates) {
            // findForRange() already returns dates ascending, but the
            // grouping below silently mis-merges on unsorted input, so it
            // never relies on that.
            sort($dates);
            $label = null;
            foreach ($this->groupConsecutiveDates($dates) as [$start, $end]) {
                if ($start > $to || $end < $from) {
                    continue;
                }
                // The display name is resolved once per member per
                // generation (§7.6) — and not at all for members whose
                // streaks all fall outside the window.
                $label ??= $this->memberService
                    ->findProfileByMemberAndYear($memberId, $viewer->scoutYearId)
                    ?->getDisplayName() ?? 'Membre';

                $events[] = new VirtualEvent(
                    uid: self::streakUid($memberId, $start),
                    calendarId: $calendarId,
                    title: self::EVENT_TITLE_PREFIX . $label,
                    startDate: $start,
                    endDate: $end
                );
            }
        }

        return $events;
    }

    /**
     * The stable UID for one duty streak. A member has at most one streak
     * starting on a given day (assignments are unique per member and
     * date), so member + first day identifies it; a streak whose END moves
     * keeps its UID and updates in place for a subscribed client.
     */
    public static function streakUid(int $memberId, string $startDate): string
    {
        return 'sos-oncall-' . $memberId . '-' . $startDate . '@scoutmagic';
    }

    /**
     * @param string[] $sortedDates
     * @return array<int, array{0: string, 1: string}>
     */
    private function groupConsecutiveDates(array $sortedDates): array
    {
        $streaks = [];
        $start = $sortedDates[0];
        $prev = $sortedDates[0];

        foreach (array_slice($sortedDates, 1) as $date) {
            $expectedNext = DateInput::requireFromStorage($prev, 'an on-call day')
                ->modify('+1 day')
                ->format('Y-m-d');
            if ($date !== $expectedNext) {
                $streaks[] = [$start, $prev];
                $start = $date;
            }
            $prev = $date;
        }
        $streaks[] = [$start, $prev];

        return $streaks;
    }
}
