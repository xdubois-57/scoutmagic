<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;

/**
 * Turns CalendarBlueprint's rules into the events of three scout years.
 *
 * **This is the applier half of the "declarative describes, applier applies"
 * split.** The blueprint says "Saturdays, mid-September to the end of June,
 * minus the school holidays and three more"; this class is the only thing
 * that knows how to walk a season and skip a window. Nothing here decides
 * WHAT the unit does — every number it uses comes from the table next door —
 * and nothing in the table knows what a date is.
 *
 * Every event goes through Modules\Calendar\Service\CalendarEventService, the
 * same call the chiefs' calendar page makes: the reminder and the activity
 * reminder are scheduled, the "event published" notification is dispatched,
 * and the write is authorised against the calendar's own edit role. Writing
 * `calendar_events` rows by hand would have skipped all three, and would have
 * frozen a schema this dataset promises to keep absorbing.
 */
final class CalendarSeeder
{
    private readonly CalendarRepository $calendarRepository;

    private readonly CalendarService $calendarService;

    private readonly CalendarEventService $eventService;

    /**
     * The scout years are not passed in: a calendar event carries a DATE,
     * never a scout year id, and UnitBlueprint::YEARS plus
     * ExtrasBlueprint::dateIn() are all this needs to place one.
     *
     * @param array<string, int> $sectionIds section handle => sections.id
     */
    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
        SectionService $sectionService,
        private readonly array $sectionIds,
        private readonly ?int $actorId,
    ) {
        $this->calendarRepository = new CalendarRepository($pdo, $encryption);
        $eventRepository = new CalendarEventRepository($pdo);
        $settingService = new SettingService(new SettingRepository($pdo));

        $this->calendarService = new CalendarService(
            $this->calendarRepository,
            $eventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($pdo, $encryption),
        );

        $this->eventService = new CalendarEventService(
            $eventRepository,
            $this->calendarService,
            // A build has nobody to notify: the notification service is wired
            // for real, and simply finds no recipient — the same degradation
            // the site applies when Web Push is not configured.
            new CalendarNotificationService(
                new SchedulerService(new SchedulerRepository($pdo)),
                $settingService,
                $this->calendarService,
                $eventRepository,
            ),
        );
    }

    /**
     * Creates every calendar the dataset needs, then fills them.
     *
     * @return int the number of events created
     */
    public function seed(): int
    {
        // Both idempotent, and both what a first load of the calendar page
        // does: the « Animateurs » calendar and one calendar per section.
        $this->calendarService->ensureDefaultCalendar();
        $this->calendarService->ensureSectionCalendars();

        $created = 0;
        foreach (UnitBlueprint::YEARS as $year) {
            $created += $this->seedUnitYear($year);
            foreach (UnitBlueprint::sectionsIn($year) as $handle) {
                $created += $this->seedSectionYear($handle, $year);
            }
        }

        return $created;
    }

    /**
     * The « Animateurs » calendar for one year: the Temps d'unité weekend,
     * the Conseils d'unité and the unit's two gatherings.
     */
    private function seedUnitYear(string $year): int
    {
        $calendarId = $this->calendarRepository->findDefaultCalendar()?->id;
        if ($calendarId === null) {
            return 0;
        }

        $created = 0;
        foreach (CalendarBlueprint::UNIT_EVENTS as $event) {
            $created += $this->create($calendarId, $year, $this->dayOf($year, $event['day'], $event['weekday']), $event) ? 1 : 0;
        }

        return $created;
    }

    /**
     * One section's year: its four highlights, its camp, and every meeting
     * the rule produces that no highlight already occupies.
     */
    private function seedSectionYear(string $handle, string $year): int
    {
        if (in_array($handle, CalendarBlueprint::SECTIONS_WITHOUT_RHYTHM, true)) {
            return 0;
        }

        $sectionId = $this->sectionIds[$handle] ?? null;
        $calendarId = $sectionId !== null ? $this->calendarRepository->findBySectionId($sectionId)?->id : null;
        if ($calendarId === null) {
            return 0;
        }

        $branch = UnitBlueprint::SECTIONS[$handle]['branch'];
        $rule = CalendarBlueprint::MEETING_RULE[$branch] ?? null;
        if ($rule === null) {
            return 0;
        }

        $created = 0;
        /** @var list<int> $busy days already carrying an event of this section */
        $busy = [];

        foreach (CalendarBlueprint::SECTION_HIGHLIGHTS[$branch] ?? [] as $highlight) {
            $day = $this->dayOf($year, $highlight['day'], $highlight['weekday']);
            $created += $this->create($calendarId, $year, $day, $highlight) ? 1 : 0;
            for ($offset = 0; $offset <= $highlight['duration']; $offset++) {
                $busy[] = $day + $offset;
            }
        }

        $camp = CalendarBlueprint::CAMPS[$branch] ?? null;
        if ($camp !== null) {
            $campDay = $camp['day'] + CalendarBlueprint::YEAR_SHIFT[$year];
            $created += $this->create($calendarId, $year, $campDay, [
                'duration' => $camp['duration'],
                'title' => $camp['title'],
                'startTime' => null,
                'endTime' => null,
                'place' => CalendarBlueprint::CAMP_PLACES[$handle] ?? null,
            ]) ? 1 : 0;
        }

        foreach (self::occurrencesOf($year, $rule) as $day) {
            if (in_array($day, $busy, true)) {
                // The section is already out that Saturday. A "Réunion" on
                // the same day as its own weekend is the kind of duplicate
                // that makes a generated calendar unreadable.
                continue;
            }
            $created += $this->create($calendarId, $year, $day, [
                'duration' => 0,
                'title' => $rule['title'],
                'startTime' => $rule['startTime'],
                'endTime' => $rule['endTime'],
                'place' => $rule['place'],
            ]) ? 1 : 0;
        }

        return $created;
    }

    /**
     * Every day the weekly rule fires on, as offsets from 1 September.
     *
     * The three exclusions applied here, in order: the window's own bounds,
     * the school holidays, and the rule's own extra skips — which are counted
     * on what is LEFT after the holidays, because "the third Saturday we do
     * not meet" is how a staff actually talks about it.
     *
     * Public and static so the rule can be checked without a database: it is
     * the one piece of arithmetic in this file that could be silently wrong.
     *
     * @param array{weekday: int, from: int, to: int, title: string, startTime: string, endTime: string, place: string, skips: list<int>} $rule
     * @return list<int>
     */
    public static function occurrencesOf(string $yearLabel, array $rule): array
    {
        $start = new \DateTimeImmutable(sprintf('%04d-09-01', UnitBlueprint::referenceYear($yearLabel)));

        $eligible = [];
        for ($day = $rule['from']; $day <= $rule['to']; $day++) {
            if ((int) $start->modify('+' . $day . ' days')->format('N') !== $rule['weekday']) {
                continue;
            }
            if (self::isSchoolHoliday($day)) {
                continue;
            }
            $eligible[] = $day;
        }

        return array_values(array_filter(
            $eligible,
            static fn (int $day, int $index): bool => !in_array($index, $rule['skips'], true),
            ARRAY_FILTER_USE_BOTH,
        ));
    }

    /** Whether an offset falls inside one of the declared holiday windows. */
    public static function isSchoolHoliday(int $day): bool
    {
        foreach (CalendarBlueprint::SCHOOL_HOLIDAYS as $window) {
            if ($day >= $window['from'] && $day <= $window['to']) {
                return true;
            }
        }

        return false;
    }

    /**
     * A punctual event's offset: the declared day, shifted by the year's own
     * shift, then moved forward to the weekday the entry asks for.
     */
    private function dayOf(string $yearLabel, int $day, int $weekday): int
    {
        $day += CalendarBlueprint::YEAR_SHIFT[$yearLabel] ?? 0;
        $start = new \DateTimeImmutable(sprintf('%04d-09-01', UnitBlueprint::referenceYear($yearLabel)));

        for ($shift = 0; $shift < 7; $shift++) {
            if ((int) $start->modify('+' . ($day + $shift) . ' days')->format('N') === $weekday) {
                return $day + $shift;
            }
        }

        return $day;
    }

    /**
     * @param array{duration: int, title: string, startTime: ?string, endTime: ?string, place: ?string} $event
     */
    private function create(int $calendarId, string $yearLabel, int $day, array $event): bool
    {
        $start = ExtrasBlueprint::dateIn($yearLabel, $day);
        $end = $event['duration'] > 0 ? ExtrasBlueprint::dateIn($yearLabel, $day + $event['duration']) : null;

        $this->eventService->createEvent(
            $calendarId,
            $event['title'],
            $start,
            $end,
            $event['startTime'],
            $event['endTime'],
            $event['place'],
            null,
            $this->actorId,
            false,
            // The builder writes for the installation, not for a person: the
            // write check has no role-less path any more, so it is handed the
            // role a chef d'unité would carry, and every section it staffs.
            Role::SUPERADMIN,
            array_values($this->sectionIds),
        );

        return true;
    }
}
