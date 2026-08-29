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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

        $calendarRepository = new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $eventRepository = new CalendarEventRepository($this->pdo);
        $sectionService = new SectionService(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            new MemberBadgeRepository($this->pdo)
        );
        $this->calendarService = new CalendarService($calendarRepository, $eventRepository, $sectionService, new CalendarUnitFeedTokenRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))));
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

    /**
     * getViewableCalendars() deliberately excludes supplementary
     * calendars a chief cannot see — but the write paths only checked that
     * the calendar EXISTED, and calendar_id/event_id arrive in the request
     * body. A chief could therefore post an admin-only calendar's id and
     * create events in it, move events into it, or delete events out of it.
     */
    public function testAChiefCannotCreateAnEventInAnAdminOnlyCalendar(): void
    {
        $adminOnlyId = $this->createAdminOnlyCalendar();

        $this->assertNotContains(
            $adminOnlyId,
            array_map(static fn(Calendar $c) => $c->id, $this->service->getViewableCalendars(Role::CHIEF)),
            'precondition: the calendar is outside a chief\'s viewable set'
        );

        $this->expectException(CalendarException::class);
        $this->service->createEvent($adminOnlyId, 'Intrus', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF);
    }

    public function testAnAdminCanCreateAnEventInAnAdminOnlyCalendar(): void
    {
        $adminOnlyId = $this->createAdminOnlyCalendar();

        $event = $this->service->createEvent($adminOnlyId, 'Réunion CU', '2026-03-15', null, null, null, null, null, null, false, Role::ADMIN);

        $this->assertSame($adminOnlyId, $event->calendarId);
    }

    public function testAChiefCannotMoveAnEventIntoAnAdminOnlyCalendar(): void
    {
        $adminOnlyId = $this->createAdminOnlyCalendar();
        $event = $this->service->createEvent($this->calendarId, 'Sortie', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF);

        $this->expectException(CalendarException::class);
        $this->service->updateEvent($event->id, $adminOnlyId, 'Sortie', '2026-03-15', null, null, null, null, null, false, null, Role::CHIEF);
    }

    /**
     * The other end of the move: an event already living in a calendar the
     * chief may not touch must not be draggable OUT of it either.
     */
    public function testAChiefCannotMoveAnEventOutOfAnAdminOnlyCalendar(): void
    {
        $adminOnlyId = $this->createAdminOnlyCalendar();
        $event = $this->service->createEvent($adminOnlyId, 'Réunion CU', '2026-03-15', null, null, null, null, null, null, false, Role::ADMIN);

        $this->expectException(CalendarException::class);
        $this->service->updateEvent($event->id, $this->calendarId, 'Réunion CU', '2026-03-15', null, null, null, null, null, false, null, Role::CHIEF);
    }

    public function testAChiefCannotDeleteAnEventInAnAdminOnlyCalendar(): void
    {
        $adminOnlyId = $this->createAdminOnlyCalendar();
        $event = $this->service->createEvent($adminOnlyId, 'Réunion CU', '2026-03-15', null, null, null, null, null, null, false, Role::ADMIN);

        try {
            $this->service->deleteEvent($event->id, Role::CHIEF);
            $this->fail('expected the delete to be refused');
        } catch (CalendarException) {
            // expected — and the event must still be there
        }

        $this->assertNotNull((new CalendarEventRepository($this->pdo))->findById($event->id));
    }

    public function testAChiefKeepsFullAccessToCalendarsInTheirEditableSet(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Sortie', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF);
        $updated = $this->service->updateEvent($event->id, $this->calendarId, 'Sortie modifiée', '2026-03-16', null, null, null, null, null, false, null, Role::CHIEF);

        $this->assertSame('Sortie modifiée', $updated->title);

        $this->service->deleteEvent($event->id, Role::CHIEF);
        $this->assertNull((new CalendarEventRepository($this->pdo))->findById($event->id));
    }

    /**
     * The role-less "system caller" short-circuit is GONE: its one caller
     * (the SOS module's calendar sync) was replaced by virtual events
     * (§7.6), so a missing viewer role now fails closed like every other
     * way of not proving write access — a call site that forgets the
     * argument is denied, never granted.
     */
    public function testACallerWithNoViewerRoleIsDeniedEveryWrite(): void
    {
        $adminOnlyId = $this->createAdminOnlyCalendar();

        $this->expectException(CalendarException::class);
        $this->service->createEvent($adminOnlyId, 'Permanence SOS', '2026-03-15', null, null, null, null, null, null);
    }

    private function createAdminOnlyCalendar(): int
    {
        return (new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))))
            ->createSupplementaryCalendar('Direction', false, Calendar::VISIBILITY_ADMIN, 'tok-admin');
    }

    public function testCreateEventSucceeds(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Réunion', '2026-03-15', null, '14:00', '16:00', 'Local', 'Desc', 5, false, Role::CHIEF);

        $this->assertSame('Réunion', $event->title);
        $this->assertSame('2026-03-15', $event->startDate);
        $this->assertSame(5, $event->createdBy);
    }

    public function testCreateEventRejectsEmptyTitle(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->createEvent($this->calendarId, '  ', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF);
    }

    public function testCreateEventRejectsInvalidStartDate(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->createEvent($this->calendarId, 'Title', 'not-a-date', null, null, null, null, null, null, false, Role::CHIEF);
    }

    public function testCreateEventRejectsEndDateBeforeStartDate(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->createEvent($this->calendarId, 'Title', '2026-03-15', '2026-03-10', null, null, null, null, null, false, Role::CHIEF);
    }

    public function testCreateEventAcceptsEndDateEqualToStartDate(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Title', '2026-03-15', '2026-03-15', null, null, null, null, null, false, Role::CHIEF);
        $this->assertSame('2026-03-15', $event->endDate);
    }

    public function testCreateEventRejectsUnknownCalendar(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->createEvent(9999, 'Title', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF);
    }

    public function testCreateEventTreatsEmptyOptionalFieldsAsNull(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Title', '2026-03-15', '', '', '', '', '', null, false, Role::CHIEF);

        $this->assertNull($event->endDate);
        $this->assertNull($event->startTime);
        $this->assertNull($event->endTime);
        $this->assertNull($event->location);
        $this->assertNull($event->description);
    }

    public function testUpdateEventChangesFields(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Old', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF);

        $updated = $this->service->updateEvent($event->id, $this->calendarId, 'New', '2026-03-20', null, '10:00', '12:00', 'Loc', 'Desc', false, null, Role::CHIEF);

        $this->assertSame('New', $updated->title);
        $this->assertSame('2026-03-20', $updated->startDate);
        $this->assertSame(1, $updated->sequence);
    }

    public function testUpdateEventRejectsUnknownEvent(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->updateEvent(9999, $this->calendarId, 'Title', '2026-03-15', null, null, null, null, null, false, null, Role::CHIEF);
    }

    public function testDeleteEventRemovesIt(): void
    {
        $event = $this->service->createEvent($this->calendarId, 'Title', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF);

        $this->service->deleteEvent($event->id, Role::CHIEF);

        $this->expectException(CalendarException::class);
        $this->service->updateEvent($event->id, $this->calendarId, 'x', '2026-01-01', null, null, null, null, null, false, null, Role::CHIEF);
    }

    public function testDeleteEventRejectsUnknownEvent(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->deleteEvent(9999, Role::CHIEF);
    }

    public function testEverySectionCalendarStaysVIEWABLEByAnyChief(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['BAL', 'Baladins', 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['BAL01', $branchId, 'Renards']);

        $this->calendarService->ensureSectionCalendars();

        $viewable = $this->service->getViewableCalendars(Role::CHIEF);

        $sectionCalendars = array_filter($viewable, fn(Calendar $c) => $c->isSectionCalendar());
        $this->assertCount(1, $sectionCalendars);
    }

    public function testViewableExcludesAdminOnlySupplementaryCalendarsForPlainChief(): void
    {
        $this->calendarService->addCalendar('AdminOnly', Calendar::VISIBILITY_ADMIN);

        $viewable = $this->service->getViewableCalendars(Role::CHIEF);

        $names = array_map(fn(Calendar $c) => $c->name, $viewable);
        $this->assertNotContains('AdminOnly', $names);
    }

    public function testViewableIncludesAdminOnlyCalendarsForAdminRole(): void
    {
        $this->calendarService->addCalendar('AdminOnly', Calendar::VISIBILITY_ADMIN);

        $viewable = $this->service->getViewableCalendars(Role::ADMIN);

        $names = array_map(fn(Calendar $c) => $c->name, $viewable);
        $this->assertContains('AdminOnly', $names);
    }

    // --- IT-02: a section calendar is WRITTEN only by an animateur of that
    // section, while everybody keeps SEEING all of them ---

    /**
     * @return array{0: int, 1: int} [sectionId, its calendar id]
     */
    private function createSectionWithCalendar(string $deskCode, string $name, int $sortOrder): array
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode . '_BR', $name, $sortOrder]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        $sectionId = (int) $this->pdo->lastInsertId();

        $this->calendarService->ensureSectionCalendars();

        foreach ($this->calendarService->getSectionCalendars() as $calendar) {
            if ($calendar->sectionId === $sectionId) {
                return [$sectionId, $calendar->id];
            }
        }

        self::fail('no calendar was created for section ' . $deskCode);
    }

    public function testASectionCalendarIsEditableOnlyByAnAnimateurOfThatSection(): void
    {
        [$mineId, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        [, $theirsCalendar] = $this->createSectionWithCalendar('ECL01', 'Éclaireurs', 30);

        $editable = array_map(
            static fn(Calendar $c) => $c->id,
            $this->service->getEditableCalendars(Role::CHIEF, [$mineId])
        );

        $this->assertContains($mineCalendar, $editable);
        $this->assertNotContains($theirsCalendar, $editable);
    }

    public function testBothSectionCalendarsStayViewableToTheSameAnimateur(): void
    {
        [, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        [, $theirsCalendar] = $this->createSectionWithCalendar('ECL01', 'Éclaireurs', 30);

        $viewable = array_map(static fn(Calendar $c) => $c->id, $this->service->getViewableCalendars(Role::CHIEF));

        $this->assertContains($mineCalendar, $viewable);
        $this->assertContains($theirsCalendar, $viewable, 'narrowing the WRITE must never narrow the READ');
    }

    public function testASupplementaryCalendarNeedsNoSectionAtAll(): void
    {
        // $this->calendarId is the 'Animateurs' supplementary calendar the
        // fixture creates: no section, so an animateur with zero staffed
        // sections still writes in it.
        $editable = array_map(static fn(Calendar $c) => $c->id, $this->service->getEditableCalendars(Role::CHIEF, []));

        $this->assertContains($this->calendarId, $editable);
    }

    public function testCreatingInAnotherSectionsCalendarIsRefused(): void
    {
        [$mineId] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        [, $theirsCalendar] = $this->createSectionWithCalendar('ECL01', 'Éclaireurs', 30);

        $this->expectException(CalendarException::class);
        $this->service->createEvent($theirsCalendar, 'Intrus', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF, [$mineId]);
    }

    public function testAnEventCannotBeMovedOutOfAnotherSectionsCalendar(): void
    {
        [$mineId, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        [$theirsId, $theirsCalendar] = $this->createSectionWithCalendar('ECL01', 'Éclaireurs', 30);

        // Created as the other section's own animateur, legitimately.
        $event = $this->service->createEvent($theirsCalendar, 'Leur réunion', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF, [$theirsId]);

        $this->expectException(CalendarException::class);
        $this->service->updateEvent($event->id, $mineCalendar, 'Détourné', '2026-03-15', null, null, null, null, null, false, null, Role::CHIEF, [$mineId]);
    }

    public function testDeletingAnotherSectionsEventIsRefused(): void
    {
        [$mineId] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        [$theirsId, $theirsCalendar] = $this->createSectionWithCalendar('ECL01', 'Éclaireurs', 30);

        $event = $this->service->createEvent($theirsCalendar, 'Leur réunion', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF, [$theirsId]);

        $this->expectException(CalendarException::class);
        $this->service->deleteEvent($event->id, Role::CHIEF, [$mineId]);
    }

    public function testTheAnimateurOfTheSectionWritesInItNormally(): void
    {
        [$mineId, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);

        $event = $this->service->createEvent($mineCalendar, 'Ma réunion', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF, [$mineId]);

        $this->assertSame($mineCalendar, $event->calendarId);
        $this->service->deleteEvent($event->id, Role::CHIEF, [$mineId]);
    }

    // --- IT-03: edit_role_min, the OTHER half of the conjunction. Seeing
    // and writing are two questions, and this column answers the second ---

    public function testTheDefaultEditRoleReproducesTheOldBehaviourExactly(): void
    {
        [$mineId, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);

        // Nothing set it: a freshly created calendar is 'chief', which is
        // what every calendar behaved like before the column existed.
        $this->assertSame(
            Calendar::EDIT_ROLE_CHIEF,
            $this->calendarService->findById($mineCalendar)->editRoleMin
        );
        $this->assertContains(
            $mineCalendar,
            array_map(static fn(Calendar $c) => $c->id, $this->service->getEditableCalendars(Role::CHIEF, [$mineId]))
        );
    }

    public function testRaisingEditRoleToAdminKeepsTheCalendarVISIBLEToAnimateurs(): void
    {
        [$mineId, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        $this->calendarService->updateEditRoleMin($mineCalendar, Calendar::EDIT_ROLE_ADMIN);

        // The whole point of the column: still seen, no longer written.
        $this->assertContains(
            $mineCalendar,
            array_map(static fn(Calendar $c) => $c->id, $this->service->getViewableCalendars(Role::CHIEF))
        );
        $this->assertNotContains(
            $mineCalendar,
            array_map(static fn(Calendar $c) => $c->id, $this->service->getEditableCalendars(Role::CHIEF, [$mineId]))
        );
    }

    public function testAChefDUniteStillWritesInAnAdminOnlyCalendar(): void
    {
        [, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        $this->calendarService->updateEditRoleMin($mineCalendar, Calendar::EDIT_ROLE_ADMIN);

        $editable = array_map(
            static fn(Calendar $c) => $c->id,
            // admin/superadmin get every section from the authorization
            // service, which the controller passes in.
            $this->service->getEditableCalendars(Role::ADMIN, [1, 2, 3])
        );

        $this->assertContains($mineCalendar, $editable);
    }

    public function testTheWriteIsRefusedOnTheServerNotJustHiddenFromThePicker(): void
    {
        [$mineId, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        $this->calendarService->updateEditRoleMin($mineCalendar, Calendar::EDIT_ROLE_ADMIN);

        $this->expectException(CalendarException::class);
        $this->service->createEvent($mineCalendar, 'Intrus', '2026-03-15', null, null, null, null, null, null, false, Role::CHIEF, [$mineId]);
    }

    public function testBothHalvesAreRequired(): void
    {
        // Animating the section is not enough when edit_role_min says
        // admin, and clearing edit_role_min is not enough without the
        // section: the rule is a conjunction, not a fallback.
        [$mineId, $mineCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);
        [, $theirsCalendar] = $this->createSectionWithCalendar('ECL01', 'Éclaireurs', 30);
        $this->calendarService->updateEditRoleMin($mineCalendar, Calendar::EDIT_ROLE_ADMIN);

        $editable = array_map(
            static fn(Calendar $c) => $c->id,
            $this->service->getEditableCalendars(Role::CHIEF, [$mineId])
        );

        $this->assertNotContains($mineCalendar, $editable, 'right section, wrong role');
        $this->assertNotContains($theirsCalendar, $editable, 'right role, wrong section');
    }

    /**
     * The former role-less "system caller" short-circuit let a session-less
     * caller write in ANY calendar, staffed sections notwithstanding. Its
     * one caller (the SOS calendar sync) is gone — duty periods are virtual
     * events now (§7.6) — so the same call is refused everywhere, section
     * calendars included.
     */
    public function testACallerWithNoRoleIsDeniedSectionCalendarsToo(): void
    {
        [, $anyCalendar] = $this->createSectionWithCalendar('BAL01', 'Baladins', 10);

        $this->expectException(CalendarException::class);
        $this->service->createEvent($anyCalendar, 'Permanence SOS', '2026-03-15', null, null, null, null, null, null);
    }
}
