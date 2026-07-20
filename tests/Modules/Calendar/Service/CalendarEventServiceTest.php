<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarException;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class CalendarEventServiceTest extends TestCase
{
    private \PDO $pdo;
    private CalendarEventService $service;
    private CalendarService $calendarService;
    private int $calendarId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);

        $calendarRepository = new CalendarRepository($this->pdo);
        $eventRepository = new CalendarEventRepository($this->pdo);
        $sectionService = new SectionService(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            new MemberBadgeRepository($this->pdo)
        );
        $this->calendarService = new CalendarService($calendarRepository, $eventRepository, $sectionService, new CalendarUnitFeedTokenRepository($this->pdo));
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('notify_multiday_events_enabled', '0', 'boolean', 'Rappels', 'desc', 'calendar');
        $settingService->register('notify_multiday_events_days_before', '14', 'text', 'Délai', 'desc', 'calendar');
        $notificationService = new CalendarNotificationService(
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $settingService,
            $this->calendarService,
            $eventRepository
        );
        $this->service = new CalendarEventService($eventRepository, $this->calendarService, $notificationService);

        $this->calendarId = $calendarRepository->createSupplementaryCalendar('Animateurs', true, Calendar::VISIBILITY_PUBLIC, 'tok');
    }

    public function testCreateEventSucceeds(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Réunion', '2026-03-15', null, '14:00', '16:00', 'Local', 'Desc', 5);

        $this->assertSame('Réunion', $event->title);
        $this->assertSame('2026-03-15', $event->startDate);
        $this->assertSame(5, $event->createdBy);
    }

    public function testCreateEventRejectsEmptyTitle(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->createEvent($this->calendarId, '  ', '2026-03-15', null, null, null, null, null, null);
    }

    public function testCreateEventRejectsInvalidStartDate(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->createEvent($this->calendarId, 'Title', 'not-a-date', null, null, null, null, null, null);
    }

    public function testCreateEventRejectsEndDateBeforeStartDate(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->createEvent($this->calendarId, 'Title', '2026-03-15', '2026-03-10', null, null, null, null, null);
    }

    public function testCreateEventAcceptsEndDateEqualToStartDate(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Title', '2026-03-15', '2026-03-15', null, null, null, null, null);
        $this->assertSame('2026-03-15', $event->endDate);
    }

    public function testCreateEventRejectsUnknownCalendar(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->createEvent(9999, 'Title', '2026-03-15', null, null, null, null, null, null);
    }

    public function testCreateEventTreatsEmptyOptionalFieldsAsNull(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Title', '2026-03-15', '', '', '', '', '', null);

        $this->assertNull($event->endDate);
        $this->assertNull($event->startTime);
        $this->assertNull($event->endTime);
        $this->assertNull($event->location);
        $this->assertNull($event->description);
    }

    public function testUpdateEventChangesFields(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Old', '2026-03-15', null, null, null, null, null, null);

        $updated = $this->service->updateEvent($event->id, $this->calendarId, 'New', '2026-03-20', null, '10:00', '12:00', 'Loc', 'Desc');

        $this->assertSame('New', $updated->title);
        $this->assertSame('2026-03-20', $updated->startDate);
        $this->assertSame(1, $updated->sequence);
    }

    public function testUpdateEventRejectsUnknownEvent(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->updateEvent(9999, $this->calendarId, 'Title', '2026-03-15', null, null, null, null, null);
    }

    public function testDeleteEventRemovesIt(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Title', '2026-03-15', null, null, null, null, null, null);

        $this->service->deleteEvent($event->id);

        $this->expectException(CalendarException::class);
        $this->service->updateEvent($event->id, $this->calendarId, 'x', '2026-01-01', null, null, null, null, null);
    }

    public function testDeleteEventRejectsUnknownEvent(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->deleteEvent(9999);
    }

    public function testGetEditableCalendarsForChiefIncludesAllSectionCalendars(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['BAL', 'Baladins', 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['BAL01', $branchId, 'Renards']);

        $this->calendarService->ensureSectionCalendars();

        $editable = $this->service->getEditableCalendarsForChief(Role::CHIEF);

        $sectionCalendars = array_filter($editable, fn(Calendar $c) => $c->isSectionCalendar());
        $this->assertCount(1, $sectionCalendars);
    }

    public function testGetEditableCalendarsForChiefExcludesAdminOnlySupplementaryCalendarsForPlainChief(): void
    {
        $this->calendarService->addCalendar('AdminOnly', Calendar::VISIBILITY_ADMIN);

        $editable = $this->service->getEditableCalendarsForChief(Role::CHIEF);

        $names = array_map(fn(Calendar $c) => $c->name, $editable);
        $this->assertNotContains('AdminOnly', $names);
    }

    public function testGetEditableCalendarsForChiefIncludesAdminOnlyCalendarsForAdminRole(): void
    {
        $this->calendarService->addCalendar('AdminOnly', Calendar::VISIBILITY_ADMIN);

        $editable = $this->service->getEditableCalendarsForChief(Role::ADMIN);

        $names = array_map(fn(Calendar $c) => $c->name, $editable);
        $this->assertContains('AdminOnly', $names);
    }
}
