<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarEventService;
use Modules\Calendar\Service\CalendarNotificationService;
use Modules\Calendar\Service\CalendarService;
use Modules\SosStaff\Repository\CalendarSyncRepository;
use Modules\SosStaff\Repository\OnCallAssignment;
use Modules\SosStaff\Repository\OnCallRepository;
use Modules\SosStaff\Service\CalendarSyncService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class CalendarSyncServiceTest extends TestCase
{
    private \PDO $pdo;
    private OnCallRepository $onCallRepository;
    private CalendarSyncRepository $syncRepository;
    private CalendarService $calendarService;
    private CalendarEventService $calendarEventService;
    private CalendarEventRepository $calendarEventRepository;
    private MemberService $memberService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        SosStaffTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $calendarRepository = new CalendarRepository($this->pdo);
        $this->calendarEventRepository = new CalendarEventRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $this->calendarService = new CalendarService(
            $calendarRepository,
            $this->calendarEventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($this->pdo)
        );
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('notify_multiday_events_enabled', '0', 'boolean', 'Rappels', 'desc', 'calendar');
        $settingService->register('notify_multiday_events_days_before', '14', 'text', 'Délai', 'desc', 'calendar');
        $notificationService = new CalendarNotificationService(
            new SchedulerService(new SchedulerRepository($this->pdo)),
            $settingService,
            $this->calendarService,
            $this->calendarEventRepository
        );
        $this->calendarEventService = new CalendarEventService($this->calendarEventRepository, $this->calendarService, $notificationService);

        $this->onCallRepository = new OnCallRepository($this->pdo);
        $this->syncRepository = new CalendarSyncRepository($this->pdo);
        $this->memberService = new MemberService(new MemberYearRepository($this->pdo), $encryption, $connection);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    private function service(bool $withCalendar = true): CalendarSyncService
    {
        return new CalendarSyncService(
            $this->syncRepository,
            $this->onCallRepository,
            $this->memberService,
            $withCalendar ? $this->calendarService : null,
            $withCalendar ? $this->calendarEventService : null
        );
    }

    /**
     * @return int member_id
     */
    private function createMember(string $totem): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $encryption->encrypt('Jean'),
            $encryption->encrypt('Dupont'),
            $encryption->encrypt($totem),
        ]);

        return $memberId;
    }

    private function defaultCalendarEvents(): array
    {
        $default = $this->calendarService->getSupplementaryCalendars();
        $defaultCalendar = array_values(array_filter($default, fn($c) => $c->isDefault))[0];
        return $this->calendarService->getAllEventsForCalendar($defaultCalendar->id);
    }

    public function testResyncNoOpsWhenCalendarServiceIsNull(): void
    {
        $memberId = $this->createMember('Akela');
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment($memberId, '2026-07-05', OnCallAssignment::STATE_ONCALL),
        ]);

        $this->service(withCalendar: false)->resync($this->scoutYearId);

        $this->assertSame([], $this->syncRepository->findAll());
    }

    public function testResyncCreatesOneEventForASingleDayStreak(): void
    {
        $memberId = $this->createMember('Akela');
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment($memberId, '2026-07-05', OnCallAssignment::STATE_ONCALL),
        ]);

        $this->service()->resync($this->scoutYearId);

        $events = $this->defaultCalendarEvents();
        $this->assertCount(1, $events);
        $this->assertSame("SOS Staff d'U : Akela", $events[0]->title);
        $this->assertSame('2026-07-05', $events[0]->startDate);
        $this->assertNull($events[0]->endDate);
    }

    public function testResyncMergesConsecutiveDaysIntoOneEvent(): void
    {
        $memberId = $this->createMember('Akela');
        $cells = [];
        foreach (range(5, 8) as $day) {
            $cells[] = new OnCallAssignment($memberId, sprintf('2026-07-%02d', $day), OnCallAssignment::STATE_ONCALL);
        }
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', $cells);

        $this->service()->resync($this->scoutYearId);

        $events = $this->defaultCalendarEvents();
        $this->assertCount(1, $events);
        $this->assertSame('2026-07-05', $events[0]->startDate);
        $this->assertSame('2026-07-08', $events[0]->endDate);
    }

    public function testResyncCreatesSeparateEventsForNonConsecutiveStreaks(): void
    {
        $memberId = $this->createMember('Akela');
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment($memberId, '2026-07-05', OnCallAssignment::STATE_ONCALL),
            new OnCallAssignment($memberId, '2026-07-15', OnCallAssignment::STATE_ONCALL),
        ]);

        $this->service()->resync($this->scoutYearId);

        $events = $this->defaultCalendarEvents();
        $this->assertCount(2, $events);
    }

    public function testResyncSkipsUnavailableDays(): void
    {
        $memberId = $this->createMember('Akela');
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment($memberId, '2026-07-05', OnCallAssignment::STATE_UNAVAILABLE),
        ]);

        $this->service()->resync($this->scoutYearId);

        $this->assertSame([], $this->defaultCalendarEvents());
    }

    public function testResyncHandlesMultipleMembersIndependently(): void
    {
        $akela = $this->createMember('Akela');
        $baloo = $this->createMember('Baloo');
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment($akela, '2026-07-05', OnCallAssignment::STATE_ONCALL),
            new OnCallAssignment($baloo, '2026-07-06', OnCallAssignment::STATE_ONCALL),
        ]);

        $this->service()->resync($this->scoutYearId);

        $titles = array_map(fn($e) => $e->title, $this->defaultCalendarEvents());
        $this->assertContains("SOS Staff d'U : Akela", $titles);
        $this->assertContains("SOS Staff d'U : Baloo", $titles);
    }

    public function testResyncDeletesPreviousEventsBeforeRecreating(): void
    {
        $memberId = $this->createMember('Akela');
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment($memberId, '2026-07-05', OnCallAssignment::STATE_ONCALL),
        ]);
        $this->service()->resync($this->scoutYearId);
        $this->assertCount(1, $this->defaultCalendarEvents());

        // Change the assignment and resync again.
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment($memberId, '2026-07-20', OnCallAssignment::STATE_ONCALL),
        ]);
        $this->service()->resync($this->scoutYearId);

        $events = $this->defaultCalendarEvents();
        $this->assertCount(1, $events);
        $this->assertSame('2026-07-20', $events[0]->startDate);
    }

    public function testResyncRecordsBookkeepingRowsWithCalendarEventId(): void
    {
        $memberId = $this->createMember('Akela');
        $this->onCallRepository->replaceRange('2026-07-01', '2026-07-31', [
            new OnCallAssignment($memberId, '2026-07-05', OnCallAssignment::STATE_ONCALL),
        ]);

        $this->service()->resync($this->scoutYearId);

        $entries = $this->syncRepository->findAll();
        $this->assertCount(1, $entries);
        $this->assertSame($memberId, $entries[0]->memberId);
        $this->assertNotNull($entries[0]->calendarEventId);
    }
}
