<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Value\PresenceStatus;

/**
 * The page one reads before phoning a family: an animé's year, their rate
 * beside the section's, the month-by-month slope, and the evenings with
 * what was written about them.
 *
 * **Which section an animé belongs to is answered by intersection, never
 * by a lookup.** The account's staffed sections are enumerated and the
 * animé is looked for among each one's animés: an animé of a section
 * nobody staffs is found in none of them, so the page refuses without a
 * separate authorization step to forget. The reverse shape — resolve the
 * section from the member, then ask whether it is allowed — is one
 * refactor away from returning the profile before the question is asked.
 */
class PresenceAnimeService
{
    /**
     * How many evenings the history shows. The page is read before a
     * phone call, where the last few weeks are the subject; the whole
     * year is what the export is for, and the page says so.
     */
    public const HISTORY_LIMIT = 10;

    public function __construct(
        private PresenceAuthorizationService $authorization,
        private PresenceSheetService $sheetService,
        private PresenceRegisterService $registerService,
        private PresenceRepository $repository
    ) {
    }

    /**
     * The animé's page, or null when this account staffs no section that
     * holds them — which is also the answer for a member id that is
     * nobody, an animateur, or somebody from another unit.
     */
    public function buildProfile(int $memberId, string $email, string $role, int $scoutYearId): ?AnimeProfile
    {
        $sectionId = $this->resolveSection($memberId, $email, $role, $scoutYearId);
        if ($sectionId === null) {
            return null;
        }

        $register = $this->registerService->buildRegister($sectionId, $scoutYearId);
        $line = null;
        foreach ($register->animes as $anime) {
            if ($anime->memberId === $memberId) {
                $line = $anime;
                break;
            }
        }
        if ($line === null) {
            return null;
        }

        $events = $this->registerService->sectionEvents($sectionId, $scoutYearId);
        $records = $this->repository->findByMemberAndEvents($memberId, array_map(
            static fn(\Modules\Calendar\Api\SectionEvent $event): int => $event->id,
            $events
        ));

        // Only POINTED evenings feed the months, for the same reason they
        // are the only ones in the register's graph: a Saturday nobody
        // opened is not a Saturday this animé missed.
        $pointedByEvent = [];
        foreach ($register->events as $registerEvent) {
            if ($registerEvent->pointed) {
                $pointedByEvent[$registerEvent->eventId] = $registerEvent;
            }
        }

        return new AnimeProfile(
            memberId: $memberId,
            lastName: $line->lastName,
            firstName: $line->firstName,
            totem: $line->totem,
            sectionId: $sectionId,
            sectionLabel: $register->sectionLabel,
            sectionColor: $register->sectionColor,
            rate: $line->rate,
            sectionAverageRate: $register->averageRate,
            present: $line->present,
            pointedEvents: $line->consideredEvents,
            months: self::months($pointedByEvent, $records),
            history: self::history($events, $records),
            historyTruncated: count($events) > self::HISTORY_LIMIT
        );
    }

    /**
     * The section this animé belongs to among the ones this account
     * staffs, or null. See the class docblock for why it is an
     * intersection rather than a lookup.
     */
    private function resolveSection(int $memberId, string $email, string $role, int $scoutYearId): ?int
    {
        foreach ($this->authorization->staffedSectionIds($email, $role, $scoutYearId) as $sectionId) {
            if (in_array($memberId, $this->sheetService->animeMemberIds($sectionId, $scoutYearId), true)) {
                return $sectionId;
            }
        }

        return null;
    }

    /**
     * @param array<int, RegisterEvent> $pointedByEvent
     * @param array<int, \Modules\Presences\Repository\PresenceRecord> $records keyed by event id
     * @return list<AnimeMonth>
     */
    private static function months(array $pointedByEvent, array $records): array
    {
        $byMonth = [];
        foreach ($pointedByEvent as $eventId => $event) {
            $month = substr($event->startDate, 0, 7);
            $byMonth[$month] ??= ['present' => 0, 'events' => 0];
            $byMonth[$month]['events']++;
            $record = $records[$eventId] ?? null;
            if ($record !== null && $record->status->countsAsAttending()) {
                $byMonth[$month]['present']++;
            }
        }

        ksort($byMonth);

        $months = [];
        foreach ($byMonth as $month => $totals) {
            $months[] = new AnimeMonth(
                month: (string) $month,
                label: AnimeMonth::labelFor((string) $month),
                present: $totals['present'],
                pointedEvents: $totals['events'],
                // A month only exists here because at least one of its
                // evenings was pointed, so the divisor is never zero.
                rate: (int) round($totals['present'] * 100 / $totals['events'])
            );
        }

        return $months;
    }

    /**
     * The most recent evenings first, capped — including the ones nobody
     * pointed, which show as « non renseigné » rather than being hidden:
     * a gap in the history is a fact about the section, and hiding it
     * would make a run of missing Saturdays look like a run of absences.
     *
     * @param list<\Modules\Calendar\Api\SectionEvent> $events oldest first
     * @param array<int, \Modules\Presences\Repository\PresenceRecord> $records keyed by event id
     * @return list<AnimeHistoryEntry>
     */
    private static function history(array $events, array $records): array
    {
        $entries = [];
        foreach (array_reverse($events) as $event) {
            $record = $records[$event->id] ?? null;
            $entries[] = new AnimeHistoryEntry(
                eventId: $event->id,
                title: $event->title,
                startDate: $event->startDate,
                status: $record !== null ? $record->status : PresenceStatus::UNSET,
                comment: $record?->comment
            );

            if (count($entries) >= self::HISTORY_LIMIT) {
                break;
            }
        }

        return $entries;
    }
}
