<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Calendar\Api\ImportedEvent;
use Modules\Calendar\Api\SelectableCalendar;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEvent;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\AccountCalendarRights;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarImportWriter;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * Api\CalendarImportWriterInterface — the data migration module's only way
 * into the calendar: an event created with the importing account's rights
 * re-checked, and without the broadcast of a chief creating it.
 *
 * The account's rights are a stub here (AccountCalendarRights reads Desk
 * functions; IcsCalendarImportOnMysqlTest builds the real one): what is
 * under test is that the writer asks them, and asks them every time.
 */
#[Group('database')]
final class CalendarImportWriterTest extends TestCase
{
    private const ACCOUNT = 4;

    private \PDO $pdo;
    private CalendarService $calendarService;
    private CalendarRepository $calendarRepository;
    private SchedulerService $scheduler;
    /** @var list<string> */
    private array $dispatched = [];
    /** @var array<int, array{role: Role, sections: list<int>}|null> */
    private array $rights = [];
    private int $rightsAsked = 0;
    private CalendarImportWriter $writer;
    private int $supplementaryId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->calendarRepository = new CalendarRepository($this->pdo, $encryption);
        $eventRepository = new CalendarEventRepository($this->pdo);
        $sectionService = new SectionService(
            new SectionRepository(Connection::withPdo($this->pdo)),
            new MemberProfileRepository(Connection::withPdo($this->pdo), $encryption, new MemberBadgeRepository($this->pdo))
        );
        $this->calendarService = new CalendarService(
            $this->calendarRepository,
            $eventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($this->pdo, $encryption)
        );
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register('notify_multiday_events_enabled', '1', 'boolean', 'Rappels', 'desc', 'calendar');
        $settings->register('notify_multiday_events_days_before', '14', 'text', 'Délai', 'desc', 'calendar');
        $this->scheduler = new SchedulerService(new SchedulerRepository($this->pdo));

        $dispatched = &$this->dispatched;
        $notifications = new class ($this->scheduler, $settings, $this->calendarService, $eventRepository, $dispatched) extends CalendarNotificationService {
            /** @param list<string> $dispatched */
            public function __construct(
                SchedulerService $scheduler,
                SettingService $settings,
                CalendarService $calendars,
                CalendarEventRepository $events,
                private array &$dispatched
            ) {
                parent::__construct($scheduler, $settings, $calendars, $events);
            }

            public function dispatchEventPublished(CalendarEvent $event, ?int $actorUserAccountId): void
            {
                $this->dispatched[] = 'published:' . $event->id;
            }

            public function dispatchEventChanged(CalendarEvent $event, ?int $actorUserAccountId): void
            {
                $this->dispatched[] = 'changed:' . $event->id;
            }
        };

        $rights = &$this->rights;
        $asked = &$this->rightsAsked;
        $accountRights = new class ($rights, $asked) extends AccountCalendarRights {
            /**
             * @param array<int, array{role: Role, sections: list<int>}|null> $rights
             */
            public function __construct(private array &$rights, private int &$asked)
            {
            }

            public function of(int $userAccountId): ?array
            {
                $this->asked++;

                return $this->rights[$userAccountId] ?? null;
            }
        };

        $this->writer = new CalendarImportWriter(
            new CalendarEventService($eventRepository, $this->calendarService, $notifications),
            $this->calendarService,
            $accountRights
        );
        $this->supplementaryId = $this->calendarRepository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok');
        $this->rights[self::ACCOUNT] = ['role' => Role::ADMIN, 'sections' => []];
    }

    public function testAnImportedEventIsCreatedWithoutAnyNotification(): void
    {
        $id = $this->writer->createImportedEvent(self::ACCOUNT, $this->supplementaryId, new ImportedEvent(
            'Réunion du samedi',
            '2025-03-15',
            null,
            '14:00',
            '17:30',
            'Local',
            'Apporter un pique-nique.'
        ));

        $event = (new CalendarEventRepository($this->pdo))->findById($id) ?? $this->fail('Not created.');
        $this->assertSame(
            ['Réunion du samedi', '2025-03-15', null, '14:00', '17:30', 'Local', 'Apporter un pique-nique.', self::ACCOUNT, false],
            [$event->title, $event->startDate, $event->endDate, substr((string) $event->startTime, 0, 5), substr((string) $event->endTime, 0, 5), $event->location, $event->description, $event->createdBy, $event->autoCreateRetro]
        );
        $this->assertSame([], $this->dispatched, 'An imported event was announced to the members.');
    }

    public function testAnAllDayEventKeepsNoTime(): void
    {
        $id = $this->writer->createImportedEvent(self::ACCOUNT, $this->supplementaryId, new ImportedEvent('Journée', '2025-09-13', null, null, '17:00'));

        $event = (new CalendarEventRepository($this->pdo))->findById($id) ?? $this->fail('Not created.');
        $this->assertTrue($event->isAllDay());
        $this->assertNull($event->endTime);
    }

    /**
     * A past event gets no reminder; an upcoming one gets the reminder any
     * event of its calendar gets — the module's normal life from now on.
     */
    public function testRemindersOnlyForAnEventStillToCome(): void
    {
        $sectionCalendar = $this->sectionCalendar();
        $this->rights[self::ACCOUNT] = ['role' => Role::ADMIN, 'sections' => [$this->sectionOf($sectionCalendar)]];

        $past = $this->writer->createImportedEvent(self::ACCOUNT, $sectionCalendar, new ImportedEvent('Camp passé', '2024-07-20', '2024-07-30'));
        $future = $this->writer->createImportedEvent(
            self::ACCOUNT,
            $sectionCalendar,
            new ImportedEvent('Camp à venir', (new \DateTimeImmutable('+2 months'))->format('Y-m-d'), (new \DateTimeImmutable('+2 months +10 days'))->format('Y-m-d'))
        );

        $this->assertNull($this->scheduler->find(CalendarNotificationService::MODULE_ID, CalendarNotificationService::TASK_KEY, 'event-' . $past));
        $this->assertNotNull($this->scheduler->find(CalendarNotificationService::MODULE_ID, CalendarNotificationService::TASK_KEY, 'event-' . $future));
        $this->assertSame([], $this->dispatched);
    }

    public function testTheWritableCalendarsAreTheChiefPagesEditableSet(): void
    {
        $adminOnly = $this->calendarRepository->createSupplementaryCalendar('Direction', false, Calendar::VISIBILITY_ADMIN, 'tok-admin');
        $section = $this->sectionCalendar();

        $this->rights[self::ACCOUNT] = ['role' => Role::CHIEF, 'sections' => []];
        $ids = array_map(static fn (SelectableCalendar $c): int => $c->id, $this->writer->writableCalendars(self::ACCOUNT));
        $this->assertContains($this->supplementaryId, $ids);
        $this->assertNotContains($adminOnly, $ids, 'A chief may not write to an admin-only calendar.');
        $this->assertNotContains($section, $ids, 'A chief may not write to a section he does not staff.');

        $this->rights[self::ACCOUNT] = ['role' => Role::ADMIN, 'sections' => [$this->sectionOf($section)]];
        $ids = array_map(static fn (SelectableCalendar $c): int => $c->id, $this->writer->writableCalendars(self::ACCOUNT));
        $this->assertContains($adminOnly, $ids);
        $this->assertContains($section, $ids);
        $this->assertTrue($this->writer->canWriteTo(self::ACCOUNT, $adminOnly));
    }

    /**
     * Checked at the moment of writing, not when the import began: an
     * account that lost the calendar since writes nothing more there.
     */
    public function testTheRightIsCheckedAgainAtEveryWrite(): void
    {
        $adminOnly = $this->calendarRepository->createSupplementaryCalendar('Direction', false, Calendar::VISIBILITY_ADMIN, 'tok-admin');
        $this->writer->createImportedEvent(self::ACCOUNT, $adminOnly, new ImportedEvent('Avant', '2025-01-01'));
        $asked = $this->rightsAsked;

        $this->rights[self::ACCOUNT] = ['role' => Role::CHIEF, 'sections' => []];
        try {
            $this->writer->createImportedEvent(self::ACCOUNT, $adminOnly, new ImportedEvent('Après', '2025-01-02'));
            $this->fail('The write was not refused.');
        } catch (\RuntimeException) {
        }

        $this->assertGreaterThan($asked, $this->rightsAsked);
        $this->assertFalse($this->writer->canWriteTo(self::ACCOUNT, $adminOnly));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn());
    }

    public function testAnAccountWithoutRightsWritesNowhere(): void
    {
        $this->assertSame([], $this->writer->writableCalendars(99));
        $this->assertFalse($this->writer->canWriteTo(99, $this->supplementaryId));

        $this->expectException(\RuntimeException::class);
        $this->writer->createImportedEvent(99, $this->supplementaryId, new ImportedEvent('Intrus', '2025-01-01'));
    }

    /**
     * @return array<string, array{0: ImportedEvent}>
     */
    public static function invalidEvents(): array
    {
        return [
            'no title' => [new ImportedEvent('  ', '2025-01-01')],
            'a title over 200 characters' => [new ImportedEvent(str_repeat('a', 201), '2025-01-01')],
            'a place over 255 characters' => [new ImportedEvent('Titre', '2025-01-01', null, null, null, str_repeat('a', 256))],
            'no date' => [new ImportedEvent('Titre', '')],
            'an end before the start' => [new ImportedEvent('Titre', '2025-01-02', '2025-01-01')],
            'an impossible hour' => [new ImportedEvent('Titre', '2025-01-01', null, '25:00')],
            'an hour that is not one' => [new ImportedEvent('Titre', '2025-01-01', null, '14h')],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('invalidEvents')]
    public function testAnEventTheCalendarCannotHoldIsRefused(ImportedEvent $event): void
    {
        try {
            $this->writer->createImportedEvent(self::ACCOUNT, $this->supplementaryId, $event);
            $this->fail('The event was accepted.');
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM calendar_events')->fetchColumn());
    }

    private function sectionCalendar(): int
    {
        $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)')->execute(['LOU', 'Louveteaux', 20]);
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)')->execute(['LOU01', $branchId, 'Meute']);
        $sectionId = (int) $this->pdo->lastInsertId();

        return $this->calendarRepository->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);
    }

    private function sectionOf(int $calendarId): int
    {
        return (int) ($this->calendarService->findById($calendarId)?->sectionId ?? 0);
    }
}
