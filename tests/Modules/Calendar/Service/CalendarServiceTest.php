<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarException;
use Modules\Calendar\Service\CalendarService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class CalendarServiceTest extends TestCase
{
    private \PDO $pdo;
    private CalendarService $service;
    private CalendarRepository $calendarRepository;
    private CalendarEventRepository $eventRepository;
    private UnitStaffSectionService $unitStaffSectionService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);

        $this->calendarRepository = new CalendarRepository($this->pdo);
        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $sectionService = new SectionService(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            new MemberBadgeRepository($this->pdo)
        );
        $this->unitStaffSectionService = new UnitStaffSectionService($this->pdo);
        $this->service = new CalendarService($this->calendarRepository, $this->eventRepository, $sectionService, new CalendarUnitFeedTokenRepository($this->pdo));
    }

    private function createRegularSection(string $deskCode, string $name, int $branchSortOrder = 10): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $deskCode, $branchSortOrder]);
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testEnsureSectionCalendarsCreatesOneCalendarPerActiveSection(): void
    {
        $this->createRegularSection('BAL01', 'Renards');
        $this->createRegularSection('LOU01', 'Meute');

        $this->service->ensureSectionCalendars();

        $this->assertCount(2, $this->calendarRepository->findSectionCalendars());
    }

    public function testEnsureSectionCalendarsIsIdempotent(): void
    {
        $this->createRegularSection('BAL01', 'Renards');

        $this->service->ensureSectionCalendars();
        $this->service->ensureSectionCalendars();

        $this->assertCount(1, $this->calendarRepository->findSectionCalendars());
    }

    public function testEnsureSectionCalendarsDefaultsToPublicVisibility(): void
    {
        $this->createRegularSection('BAL01', 'Renards');

        $this->service->ensureSectionCalendars();

        $calendar = $this->calendarRepository->findSectionCalendars()[0];
        $this->assertSame(Calendar::VISIBILITY_PUBLIC, $calendar->visibility);
    }

    public function testEnsureSectionCalendarsDefaultsStaffduToChiefVisibility(): void
    {
        $this->unitStaffSectionService->ensureSection();

        $this->service->ensureSectionCalendars();

        $calendars = $this->calendarRepository->findSectionCalendars();
        $this->assertCount(1, $calendars);
        $this->assertSame(Calendar::VISIBILITY_CHIEF, $calendars[0]->visibility);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function coreBranchSortOrderProvider(): array
    {
        return [
            'Baladins' => [10],
            'Louveteaux' => [20],
            'Éclaireurs' => [30],
            'Pionniers' => [40],
        ];
    }

    /**
     * @dataProvider coreBranchSortOrderProvider
     */
    public function testEnsureSectionCalendarsDefaultsCoreBranchesToPublicVisibility(int $branchSortOrder): void
    {
        $this->createRegularSection('SEC01', 'Section', $branchSortOrder);

        $this->service->ensureSectionCalendars();

        $calendar = $this->calendarRepository->findSectionCalendars()[0];
        $this->assertSame(Calendar::VISIBILITY_PUBLIC, $calendar->visibility);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function nonCoreBranchSortOrderProvider(): array
    {
        return [
            'Route' => [60],
            'Iama' => [70],
            'Unknown' => [99],
        ];
    }

    /**
     * @dataProvider nonCoreBranchSortOrderProvider
     */
    public function testEnsureSectionCalendarsDefaultsNonCoreBranchesToChiefVisibility(int $branchSortOrder): void
    {
        $this->createRegularSection('SEC01', 'Section', $branchSortOrder);

        $this->service->ensureSectionCalendars();

        $calendar = $this->calendarRepository->findSectionCalendars()[0];
        $this->assertSame(Calendar::VISIBILITY_CHIEF, $calendar->visibility);
    }

    public function testEnsureDefaultCalendarCreatesAnimateurs(): void
    {
        $this->service->ensureDefaultCalendar();

        $default = $this->calendarRepository->findDefaultCalendar();
        $this->assertNotNull($default);
        $this->assertSame('Animateurs', $default->name);
        $this->assertNotNull($default->icsToken);
    }

    public function testEnsureDefaultCalendarDefaultsToChiefVisibility(): void
    {
        $this->service->ensureDefaultCalendar();

        $default = $this->calendarRepository->findDefaultCalendar();
        $this->assertSame(Calendar::VISIBILITY_CHIEF, $default->visibility);
    }

    public function testEnsureDefaultCalendarIsIdempotent(): void
    {
        $this->service->ensureDefaultCalendar();
        $this->service->ensureDefaultCalendar();

        $this->assertCount(1, $this->calendarRepository->findSupplementaryCalendars());
    }

    public function testAddCalendarCreatesCustomSupplementaryCalendar(): void
    {
        $calendar = $this->service->addCalendar('Anniversaires', Calendar::VISIBILITY_PUBLIC);

        $this->assertFalse($calendar->isDefault);
        $this->assertNotNull($calendar->icsToken);
        $this->assertSame('Anniversaires', $calendar->name);
    }

    public function testAddCalendarRejectsEmptyName(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->addCalendar('   ', Calendar::VISIBILITY_PUBLIC);
    }

    public function testAddCalendarRejectsInvalidVisibility(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->addCalendar('Test', 'bogus');
    }

    public function testAddCalendarRejectsDuplicateName(): void
    {
        $this->service->addCalendar('Anniversaires', Calendar::VISIBILITY_PUBLIC);

        $this->expectException(CalendarException::class);
        $this->service->addCalendar('Anniversaires', Calendar::VISIBILITY_PUBLIC);
    }

    public function testUpdateVisibilityChangesValue(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);

        $this->service->updateVisibility($calendar->id, Calendar::VISIBILITY_ADMIN);

        $this->assertSame(Calendar::VISIBILITY_ADMIN, $this->service->findById($calendar->id)->visibility);
    }

    public function testUpdateVisibilityRejectsUnknownCalendar(): void
    {
        $this->expectException(CalendarException::class);
        $this->service->updateVisibility(9999, Calendar::VISIBILITY_PUBLIC);
    }

    public function testRegenerateTokenChangesTokenForSupplementaryCalendar(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);
        $oldToken = $calendar->icsToken;

        $updated = $this->service->regenerateToken($calendar->id);

        $this->assertNotSame($oldToken, $updated->icsToken);
        $this->assertNull($this->calendarRepository->findByIcsToken($oldToken));
    }

    public function testRegenerateTokenRejectsSectionCalendar(): void
    {
        $sectionId = $this->createRegularSection('BAL01', 'Renards');
        $calendarId = $this->calendarRepository->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);

        $this->expectException(CalendarException::class);
        $this->service->regenerateToken($calendarId);
    }

    public function testDeleteRemovesUnusedCustomCalendar(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);

        $this->service->delete($calendar->id);

        $this->assertNull($this->service->findById($calendar->id));
    }

    public function testDeleteRejectsDefaultCalendar(): void
    {
        $this->service->ensureDefaultCalendar();
        $default = $this->calendarRepository->findDefaultCalendar();

        $this->expectException(CalendarException::class);
        $this->service->delete($default->id);
    }

    public function testDeleteRejectsSectionCalendar(): void
    {
        $sectionId = $this->createRegularSection('BAL01', 'Renards');
        $calendarId = $this->calendarRepository->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);

        $this->expectException(CalendarException::class);
        $this->service->delete($calendarId);
    }

    public function testDeleteRejectsCalendarWithEvents(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);
        $this->eventRepository->create($calendar->id, 'Event', '2026-01-01', null, null, null, null, null, null);

        $this->expectException(CalendarException::class);
        $this->service->delete($calendar->id);
    }

    public function testGetCalendarIdsWithEventsOnlyReturnsOnesWithEvents(): void
    {
        $withEvents = $this->service->addCalendar('A', Calendar::VISIBILITY_PUBLIC);
        $withoutEvents = $this->service->addCalendar('B', Calendar::VISIBILITY_PUBLIC);
        $this->eventRepository->create($withEvents->id, 'Event', '2026-01-01', null, null, null, null, null, null);

        $ids = $this->service->getCalendarIdsWithEvents();

        $this->assertContains($withEvents->id, $ids);
        $this->assertNotContains($withoutEvents->id, $ids);
    }

    public function testIsVisibleToRolePublicIsAlwaysVisible(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);

        $this->assertTrue($this->service->isVisibleToRole($calendar, Role::PUBLIC));
    }

    public function testIsVisibleToRoleChiefRequiresChiefOrAbove(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_CHIEF);

        $this->assertFalse($this->service->isVisibleToRole($calendar, Role::IDENTIFIED));
        $this->assertTrue($this->service->isVisibleToRole($calendar, Role::CHIEF));
        $this->assertTrue($this->service->isVisibleToRole($calendar, Role::ADMIN));
    }

    public function testIsVisibleToRoleAdminRequiresAdminOrAbove(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_ADMIN);

        $this->assertFalse($this->service->isVisibleToRole($calendar, Role::CHIEF));
        $this->assertTrue($this->service->isVisibleToRole($calendar, Role::ADMIN));
    }

    public function testGetVisibleCalendarsFiltersOutInvisibleOnes(): void
    {
        $this->service->addCalendar('Public', Calendar::VISIBILITY_PUBLIC);
        $this->service->addCalendar('ChiefOnly', Calendar::VISIBILITY_CHIEF);

        $visible = $this->service->getVisibleCalendars(Role::PUBLIC);

        $names = array_map(fn(Calendar $c) => $c->name, $visible);
        $this->assertContains('Public', $names);
        $this->assertNotContains('ChiefOnly', $names);
    }

    public function testResolveCalendarIdsForPublicPageWithNoSectionReturnsAllVisible(): void
    {
        $sectionId = $this->createRegularSection('BAL01', 'Renards');
        $this->service->ensureSectionCalendars();
        $this->service->addCalendar('Custom', Calendar::VISIBILITY_PUBLIC);

        $ids = $this->service->resolveCalendarIdsForPublicPage(null, Role::PUBLIC);

        $this->assertCount(2, $ids);
    }

    public function testResolveCalendarIdsForPublicPageWithSectionReturnsOnlyThatSection(): void
    {
        $sectionId = $this->createRegularSection('BAL01', 'Renards');
        $this->service->ensureSectionCalendars();
        $this->service->addCalendar('Custom', Calendar::VISIBILITY_PUBLIC);

        $ids = $this->service->resolveCalendarIdsForPublicPage($sectionId, Role::PUBLIC);

        $sectionCalendar = $this->calendarRepository->findBySectionId($sectionId);
        $this->assertSame([$sectionCalendar->id], $ids);
    }

    public function testGenerateTokenProducesUniqueUnguessableTokens(): void
    {
        $a = $this->service->generateToken();
        $b = $this->service->generateToken();

        $this->assertNotSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function testGetAllEventsForCalendarReturnsEveryEventRegardlessOfDate(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);
        $this->eventRepository->create($calendar->id, 'Past', '2020-01-01', null, null, null, null, null, null);
        $this->eventRepository->create($calendar->id, 'Future', '2099-01-01', null, null, null, null, null, null);

        $events = $this->service->getAllEventsForCalendar($calendar->id);

        $this->assertCount(2, $events);
    }

    public function testGetOrCreateUnitFeedTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        $first = $this->service->getOrCreateUnitFeedToken();
        $second = $this->service->getOrCreateUnitFeedToken();

        $this->assertSame($first, $second);
    }

    public function testRegenerateUnitFeedTokenInvalidatesThePreviousOne(): void
    {
        $old = $this->service->getOrCreateUnitFeedToken();

        $new = $this->service->regenerateUnitFeedToken();

        $this->assertNotSame($old, $new);
        $this->assertFalse($this->service->isValidUnitFeedToken($old));
        $this->assertTrue($this->service->isValidUnitFeedToken($new));
    }

    public function testIsValidUnitFeedTokenRejectsUnknownToken(): void
    {
        $this->assertFalse($this->service->isValidUnitFeedToken('nope'));
    }

    public function testIsValidUnitFeedTokenRejectsEmptyString(): void
    {
        $this->assertFalse($this->service->isValidUnitFeedToken(''));
    }

    public function testGetEventsForGridReturnsEventsWithinTheFullGridSpan(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);
        // 2026-02-23 is in March 2026's grid (Monday before the 1st) even
        // though it's not "in" March itself.
        $this->eventRepository->create($calendar->id, 'Padding day event', '2026-02-23', null, null, null, null, null, null);
        $this->eventRepository->create($calendar->id, 'Outside grid', '2026-01-01', null, null, null, null, null, null);

        $events = $this->service->getEventsForGrid(2026, 3, [$calendar->id]);

        $titles = array_map(fn($e) => $e->title, $events);
        $this->assertContains('Padding day event', $titles);
        $this->assertNotContains('Outside grid', $titles);
    }

    public function testGetEventsForGridReturnsEmptyArrayForNoCalendarIds(): void
    {
        $this->assertSame([], $this->service->getEventsForGrid(2026, 3, []));
    }

    public function testToGridEventsMapsFieldsAndBuildsTooltip(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);
        $id = $this->eventRepository->create($calendar->id, 'Réunion', '2026-03-11', '2026-03-12', '14:00:00', '16:00:00', 'Local scout', 'Desc', null);
        $event = $this->eventRepository->findById($id);

        $gridEvents = $this->service->toGridEvents([$event]);

        $this->assertCount(1, $gridEvents);
        $gridEvent = $gridEvents[0];
        $this->assertSame((string) $id, $gridEvent->id);
        $this->assertSame('2026-03-11', $gridEvent->startDate);
        $this->assertSame('2026-03-12', $gridEvent->endDate);
        $this->assertSame('Réunion', $gridEvent->label);
        $this->assertSame('Réunion — 14:00 — Local scout (Test)', $gridEvent->tooltip);
        $this->assertSame('#800020', $gridEvent->color);
        $this->assertSame((string) $calendar->id, $gridEvent->data['calendar-id']);
        $this->assertSame('Test', $gridEvent->data['calendar-label']);
        $this->assertSame('14:00', $gridEvent->data['start-time']);
        $this->assertSame('Local scout', $gridEvent->data['location']);
    }

    public function testToGridEventsOmitsTimeAndLocationFromTooltipWhenAbsent(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);
        $id = $this->eventRepository->create($calendar->id, 'Réunion', '2026-03-11', null, null, null, null, null, null);
        $event = $this->eventRepository->findById($id);

        $gridEvents = $this->service->toGridEvents([$event]);

        $this->assertSame('Réunion (Test)', $gridEvents[0]->tooltip);
        $this->assertSame('', $gridEvents[0]->data['start-time']);
        $this->assertSame('', $gridEvents[0]->data['location']);
    }

    public function testColorsByCalendarIdUsesBranchColorForSectionCalendars(): void
    {
        $sectionId = $this->createRegularSection('BAL01', 'Renards');
        $this->service->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);

        $colors = $this->service->colorsByCalendarId();

        $this->assertArrayHasKey($calendar->id, $colors);
        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $colors[$calendar->id]);
    }

    public function testColorsByCalendarIdUsesFixedAccentForSupplementaryCalendars(): void
    {
        $calendar = $this->service->addCalendar('Test', Calendar::VISIBILITY_PUBLIC);

        $colors = $this->service->colorsByCalendarId();

        $this->assertSame('#800020', $colors[$calendar->id]);
    }

    public function testLabelsByCalendarIdUsesSectionNameForSectionCalendars(): void
    {
        $sectionId = $this->createRegularSection('BAL01', 'Renards');
        $this->service->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);

        $labels = $this->service->labelsByCalendarId();

        $this->assertSame('Renards', $labels[$calendar->id]);
    }

    public function testLabelsByCalendarIdUsesOwnNameForSupplementaryCalendars(): void
    {
        $calendar = $this->service->addCalendar('Anniversaires', Calendar::VISIBILITY_PUBLIC);

        $labels = $this->service->labelsByCalendarId();

        $this->assertSame('Anniversaires', $labels[$calendar->id]);
    }
}
