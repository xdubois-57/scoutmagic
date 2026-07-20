<?php

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Member\MemberService;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarException;
use Modules\Calendar\Service\CalendarService;
use Modules\SosStaff\Repository\CalendarSyncRepository;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Repository\OnCallRepository;

/**
 * Syncs duty periods into the calendar module's default "Animateurs"
 * calendar (module spec §5): consecutive oncall days for the same member
 * are merged into one event per streak. Optional dependency on the
 * calendar module — every method no-ops when it's disabled
 * ($calendarService/$calendarEventService null), per the spec's own note
 * that this module must tolerate the calendar module being absent.
 *
 * resync() is a full rebuild (delete every previously-synced event, then
 * recreate from the current sos_oncall_assignments content) rather than an
 * incremental diff — simple and correct, and implicitly self-cleaning:
 * since OnCallService::cleanupOlderThanOneYear() purges assignments older
 * than a year before this runs (see the admin controller's save
 * pipeline), a rebuild from current data never recreates events for
 * already-purged streaks.
 */
class CalendarSyncService
{
    private const EVENT_TITLE_PREFIX = "SOS Staff d'U : ";

    public function __construct(
        private CalendarSyncRepository $syncRepository,
        private OnCallRepository $onCallRepository,
        private MemberService $memberService,
        private ?CalendarService $calendarService = null,
        private ?CalendarEventService $calendarEventService = null
    ) {
    }

    public function resync(int $scoutYearId): void
    {
        if ($this->calendarService === null || $this->calendarEventService === null) {
            return;
        }

        $this->calendarService->ensureDefaultCalendar();
        $defaultCalendar = null;
        foreach ($this->calendarService->getSupplementaryCalendars() as $calendar) {
            if ($calendar->isDefault) {
                $defaultCalendar = $calendar;
                break;
            }
        }
        if ($defaultCalendar === null) {
            return;
        }

        foreach ($this->syncRepository->findAll() as $entry) {
            if ($entry->calendarEventId !== null) {
                try {
                    $this->calendarEventService->deleteEvent($entry->calendarEventId);
                } catch (CalendarException $e) {
                    // Already gone — fine, we're about to drop the bookkeeping row too.
                }
            }
        }
        $this->syncRepository->deleteAll();

        $onCallDatesByMember = [];
        foreach ($this->onCallRepository->findAll() as $assignment) {
            if ($assignment->state === OnCallAssignment::STATE_ONCALL) {
                $onCallDatesByMember[$assignment->memberId][] = $assignment->date;
            }
        }

        foreach ($onCallDatesByMember as $memberId => $dates) {
            sort($dates);
            foreach ($this->groupConsecutiveDates($dates) as [$start, $end]) {
                $this->createSyncedEvent($defaultCalendar->id, $memberId, $start, $end, $scoutYearId);
            }
        }
    }

    private function createSyncedEvent(int $calendarId, int $memberId, string $start, string $end, int $scoutYearId): void
    {
        $profile = $this->memberService->findProfileByMemberAndYear($memberId, $scoutYearId);
        $label = $profile?->getDisplayName() ?? 'Membre';

        $eventId = null;
        try {
            $event = $this->calendarEventService->createEvent(
                $calendarId,
                self::EVENT_TITLE_PREFIX . $label,
                $start,
                $end !== $start ? $end : null,
                null,
                null,
                null,
                null,
                null
            );
            $eventId = $event->id;
        } catch (CalendarException $e) {
            // Sync bookkeeping still records the streak even if the
            // calendar event itself couldn't be created — the >1 year
            // cleanup and the next resync() will still find/replace it.
        }

        $this->syncRepository->create($memberId, $start, $end, $eventId);
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
            $expectedNext = (new \DateTimeImmutable($prev))->modify('+1 day')->format('Y-m-d');
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
