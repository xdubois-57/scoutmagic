<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Core\Config\AppClock;
use Core\Config\ScoutYearService;
use Core\Member\MemberProfile;
use Core\Member\SectionService;
use Core\Service\DateInput;
use Core\Service\TextNormalizerService;
use Modules\Calendar\Api\SectionEvent;
use Modules\Calendar\Api\SectionEventLookupInterface;
use Modules\Presences\Repository\PresenceRepository;
use Modules\Presences\Value\PresenceStatus;

/**
 * What a section's year looks like: which evenings it held, how many
 * animés turned up at each, and who is dropping out.
 *
 * **The event list is always read from the calendar, never stored here.**
 * `calendar_calendars.section_id` is what makes an evening a section's, so
 * asking the calendar is the only way for the answer to stay right when
 * an evening is added, moved or deleted after somebody has pointed it.
 *
 * **Two queries for a whole year, whatever it holds.** One call to the
 * calendar for the window, one to the repository for every row of every
 * one of those events — never one per date, which is the shape a register
 * page falls into by accident and only notices in June.
 */
class PresenceRegisterService
{
    public function __construct(
        private SectionEventLookupInterface $sectionEventLookup,
        private ScoutYearService $scoutYearService,
        private SectionService $sectionService,
        private PresenceRepository $repository
    ) {
    }

    /**
     * Every evening $sectionId held during $scoutYearId, oldest first.
     *
     * An unknown scout year answers with an empty list rather than
     * widening to every date the calendar holds — a window that cannot be
     * computed is not a window covering everything.
     *
     * @return list<SectionEvent>
     */
    public function sectionEvents(int $sectionId, int $scoutYearId): array
    {
        $window = $this->yearWindow($scoutYearId);
        if ($window === null) {
            return [];
        }

        return $this->sectionEventLookup->findSectionEventsInWindow($sectionId, $window[0], $window[1]);
    }

    /**
     * The whole register for one section and one scout year.
     *
     * Every rate here divides by evenings somebody actually POINTED. An
     * evening nobody opened is listed, so it can be reached and filled
     * in, and counted nowhere — see RegisterEvent::$pointed.
     */
    public function buildRegister(int $sectionId, int $scoutYearId): Register
    {
        $section = $this->sectionService->getSection($sectionId);
        $events = $this->sectionEvents($sectionId, $scoutYearId);
        $animes = $this->sectionService->getSectionAnimes($sectionId, $scoutYearId);
        $animeCount = count($animes);

        $records = $this->repository->findByEvents(
            array_map(static fn(SectionEvent $event): int => $event->id, $events)
        );

        $registerEvents = [];
        $presentByMember = [];
        $pointedCount = 0;
        $rateTotal = 0;

        foreach ($events as $event) {
            $rows = $records[$event->id] ?? [];
            $counts = [
                PresenceStatus::PRESENT->value => 0,
                PresenceStatus::EXCUSED->value => 0,
                PresenceStatus::ABSENT->value => 0,
            ];
            foreach ($rows as $record) {
                if (array_key_exists($record->status->value, $counts)) {
                    $counts[$record->status->value]++;
                }
                if ($record->status->countsAsAttending()) {
                    $presentByMember[$record->memberId] = ($presentByMember[$record->memberId] ?? 0) + 1;
                }
            }

            // « Pointed » is about a decision having been taken, not
            // about a row existing: a row carrying only a comment is not
            // somebody having pointed the evening.
            $decided = $counts[PresenceStatus::PRESENT->value]
                + $counts[PresenceStatus::EXCUSED->value]
                + $counts[PresenceStatus::ABSENT->value];
            $pointed = $decided > 0;

            // The denominator is the roll this evening actually concerned,
            // never « who is in the section today ». getSectionAnimes()
            // answers the second — it filters `member_years.is_active = 1`
            // — while the records are everyone who was pointed, including
            // animés a later Desk import deactivated. Divide by the second
            // and an evening where twenty-five were present, five of whom
            // have since left, reads 125 %.
            //
            // Taking whichever is larger costs no storage and cannot
            // exceed 100 %: it is the section's size while nobody has
            // left, and the evening's own roll once somebody has.
            $roll = max($animeCount, $decided);
            $rate = $pointed && $roll > 0
                ? (int) round($counts[PresenceStatus::PRESENT->value] * 100 / $roll)
                : 0;

            if ($pointed) {
                $pointedCount++;
                $rateTotal += $rate;
            }

            $registerEvents[] = new RegisterEvent(
                eventId: $event->id,
                title: $event->title,
                startDate: $event->startDate,
                pointed: $pointed,
                present: $counts[PresenceStatus::PRESENT->value],
                excused: $counts[PresenceStatus::EXCUSED->value],
                absent: $counts[PresenceStatus::ABSENT->value],
                // Non-negative by construction now, rather than by a
                // max(0, …) that hid the surplus the wrong denominator
                // produced.
                notRecorded: $roll - $decided,
                rate: $rate
            );
        }

        $registerAnimes = self::rankAnimes($animes, $presentByMember, $pointedCount);
        [$best, $worst] = self::extremes($registerEvents);

        $nextEvent = self::nextEvent($registerEvents);

        return new Register(
            sectionId: $sectionId,
            sectionLabel: PresenceSheetService::sectionLabel($section),
            sectionColor: $section !== null ? SectionService::colorForSection($section) : null,
            events: $registerEvents,
            animes: $registerAnimes,
            animeCount: $animeCount,
            pointedEventCount: $pointedCount,
            averageRate: $pointedCount > 0 ? (int) round($rateTotal / $pointedCount) : 0,
            best: $best,
            worst: $worst,
            nextEvent: $nextEvent,
            nextEventPending: $nextEvent !== null ? $nextEvent->notRecorded : 0
        );
    }

    /**
     * The unified search behind the register's one field: evenings and
     * animés at once, because somebody types what they have rather than
     * choosing a mode first.
     *
     * **An empty query already answers**, with the most recent evenings
     * and a first handful of animés — an empty panel on focus reads as
     * « you must know a syntax ».
     *
     * @return array{events: list<RegisterEvent>, animes: list<RegisterAnime>}
     */
    public function search(int $sectionId, int $scoutYearId, string $query, int $limit = 8): array
    {
        $register = $this->buildRegister($sectionId, $scoutYearId);
        $needle = TextNormalizerService::fold($query);

        if ($needle === '') {
            // Most recent first: the evening somebody is looking for is
            // almost always the last one, not September's.
            return [
                'events' => array_slice(array_reverse($register->events), 0, 3),
                'animes' => array_slice(self::byName($register->animes), 0, 3),
            ];
        }

        $events = array_values(array_filter(
            array_reverse($register->events),
            static fn(RegisterEvent $event): bool
                => str_contains(TextNormalizerService::fold($event->title), $needle)
                || str_contains(self::foldedDate($event->startDate), $needle)
        ));

        $animes = array_values(array_filter(
            self::byName($register->animes),
            static fn(RegisterAnime $anime): bool => str_contains(
                TextNormalizerService::fold($anime->firstName . ' ' . $anime->lastName . ' ' . ($anime->totem ?? '')),
                $needle
            )
        ));

        return [
            'events' => array_slice($events, 0, $limit),
            'animes' => array_slice($animes, 0, $limit),
        ];
    }

    /**
     * The scout year's own start and end dates, which are what bounds a
     * register — never « the last twelve months », which would put two
     * different Septembers in one average.
     *
     * @return array{0: \DateTimeImmutable, 1: \DateTimeImmutable}|null
     */
    public function yearWindow(int $scoutYearId): ?array
    {
        $year = $this->scoutYearService->findById($scoutYearId);
        if ($year === null) {
            return null;
        }

        // Core\Service\DateInput is the site's one reading of a stored
        // date, and the only place allowed to parse one by format: a
        // value carrying a NUL byte raises a ValueError rather than
        // answering false, so a hand-rolled `!== false` guard lets
        // exactly that input through as an uncaught exception
        // (Tests\Security\DateParsingConvergenceTest).
        $start = DateInput::fromStorage($year['start_date']);
        $end = DateInput::fromStorage($year['end_date']);
        if ($start === null || $end === null) {
            return null;
        }

        return [$start, $end];
    }

    /**
     * @param MemberProfile[] $animes
     * @param array<int, int> $presentByMember
     * @return list<RegisterAnime>
     */
    private static function rankAnimes(array $animes, array $presentByMember, int $pointedCount): array
    {
        $ranked = [];
        foreach ($animes as $profile) {
            $present = $presentByMember[$profile->memberId] ?? 0;
            $ranked[] = new RegisterAnime(
                memberId: $profile->memberId,
                lastName: $profile->lastName,
                firstName: $profile->firstName,
                totem: $profile->totem,
                present: $present,
                consideredEvents: $pointedCount,
                rate: $pointedCount > 0 ? (int) round($present * 100 / $pointedCount) : 0
            );
        }

        // Least present first — this list exists to be acted on, and the
        // action is a phone call. Ties are broken by name so the order is
        // stable between two loads of the same page.
        usort(
            $ranked,
            static fn(RegisterAnime $a, RegisterAnime $b): int
                => [$a->rate, TextNormalizerService::fold($a->lastName), TextNormalizerService::fold($a->firstName)]
                <=> [$b->rate, TextNormalizerService::fold($b->lastName), TextNormalizerService::fold($b->firstName)]
        );

        return $ranked;
    }

    /**
     * @param list<RegisterAnime> $animes
     * @return list<RegisterAnime>
     */
    private static function byName(array $animes): array
    {
        usort(
            $animes,
            static fn(RegisterAnime $a, RegisterAnime $b): int
                => [TextNormalizerService::fold($a->lastName), TextNormalizerService::fold($a->firstName)]
                <=> [TextNormalizerService::fold($b->lastName), TextNormalizerService::fold($b->firstName)]
        );

        return $animes;
    }

    /**
     * The best and the weakest POINTED evening. Both null when nothing
     * has been pointed yet — highlighting a best date among none would
     * be a claim about a year nobody has recorded.
     *
     * @param list<RegisterEvent> $events
     * @return array{0: ?RegisterEvent, 1: ?RegisterEvent}
     */
    private static function extremes(array $events): array
    {
        $best = null;
        $worst = null;

        foreach ($events as $event) {
            if (!$event->pointed) {
                continue;
            }
            if ($best === null || $event->rate > $best->rate) {
                $best = $event;
            }
            if ($worst === null || $event->rate < $worst->rate) {
                $worst = $event;
            }
        }

        return [$best, $worst];
    }

    /**
     * The evening the « Pointer » shortcut opens: the first one still to
     * come, and failing that the most recent past one that still has
     * animés nobody answered for.
     *
     * An animateur opening this page on a Saturday at 14:00 wants to
     * point, not to read yearly averages — and the day after, what they
     * want is the evening they did not finish.
     *
     * @param list<RegisterEvent> $events oldest first
     */
    private static function nextEvent(array $events): ?RegisterEvent
    {
        $today = AppClock::now()->format('Y-m-d');

        foreach ($events as $event) {
            if ($event->startDate >= $today) {
                return $event;
            }
        }

        foreach (array_reverse($events) as $event) {
            if ($event->notRecorded > 0) {
                return $event;
            }
        }

        return null;
    }

    /**
     * A date made searchable the way somebody types it — « 13/09 » and
     * « 2026-09-13 » both have to find the same evening, since one is
     * what the screen shows and the other what the column holds.
     */
    private static function foldedDate(string $storedDate): string
    {
        $date = DateInput::fromStorage($storedDate);

        return $date === null
            ? TextNormalizerService::fold($storedDate)
            : TextNormalizerService::fold($storedDate . ' ' . $date->format('d/m/Y'));
    }
}
