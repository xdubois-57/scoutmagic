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
#[\PHPUnit\Framework\Attributes\Group('database')]
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

        $this->calendarRepository = new CalendarRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));
        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $sectionService = new SectionService(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            new MemberBadgeRepository($this->pdo)
        );
        $this->unitStaffSectionService = new UnitStaffSectionService($this->pdo);
        $this->service = new CalendarService($this->calendarRepository, $this->eventRepository, $sectionService, new CalendarUnitFeedTokenRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))));
    }

    public function testASelectableCalendarAlwaysCarriesALabelAHumanCanRead(): void
    {
        // A section calendar has no name of its own — `calendar_calendars
        // .name` is null for it and the label belongs to the section. A
        // consumer rendering `calendar.name` therefore got a list of blank
        // options with only the supplementary "Animateurs" readable, which
        // is exactly what this method exists to stop happening again.
        $sectionId = $this->createRegularSection('LOUV', 'Louveteaux');
        $this->calendarRepository->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);
        $this->service->ensureDefaultCalendar();

        $labels = array_column($this->service->listSelectableCalendars(), 'label');

        $this->assertContains('Louveteaux', $labels);
        $this->assertContains('Animateurs', $labels);
        foreach ($labels as $label) {
            $this->assertNotSame('', trim($label));
        }
    }

    public function testAnUnnamedSectionFallsBackToItsDeskCodeRatherThanToNothing(): void
    {
        // A unit that has not finished configuring its sections still needs
        // a list it can tell apart.
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute(['PION', 'PION', 40]);
        $branchId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute(['PION-1', $branchId, null]);
        $sectionId = (int) $this->pdo->lastInsertId();

        $this->calendarRepository->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);

        $this->assertContains('PION-1', array_column($this->service->listSelectableCalendars(), 'label'));
    }

    public function testSelectableCalendarsPublishesTheSameListAsApiValueObjects(): void
    {
        // The Api\CalendarDirectoryInterface half (chantier IT-06): a
        // consuming module (rental's publication picker) gets the same
        // list as public DTOs, without touching this module's internals.
        $sectionId = $this->createRegularSection('BALA', 'Baladins');
        $this->calendarRepository->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);
        $this->service->ensureDefaultCalendar();

        $views = $this->service->selectableCalendars();

        $this->assertContainsOnlyInstancesOf(\Modules\Calendar\Api\SelectableCalendar::class, $views);
        $byLabel = [];
        foreach ($views as $view) {
            $byLabel[$view->label] = $view;
        }
        $this->assertTrue($byLabel['Baladins']->isSection);
        $this->assertFalse($byLabel['Animateurs']->isSection);
        $this->assertGreaterThan(0, $byLabel['Baladins']->id);
    }

    public function testASectionCalendarIsMarkedAsOneSoAConsumerCanGroupThem(): void
    {
        $sectionId = $this->createRegularSection('BALA', 'Baladins');
        $this->calendarRepository->createSectionCalendar($sectionId, Calendar::VISIBILITY_PUBLIC);
        $this->service->ensureDefaultCalendar();

        $byLabel = [];
        foreach ($this->service->listSelectableCalendars() as $calendar) {
            $byLabel[$calendar['label']] = $calendar['is_section'];
        }

        $this->assertTrue($byLabel['Baladins']);
        $this->assertFalse($byLabel['Animateurs']);
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
    #[\PHPUnit\Framework\Attributes\DataProvider('coreBranchSortOrderProvider')]
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
    #[\PHPUnit\Framework\Attributes\DataProvider('nonCoreBranchSortOrderProvider')]
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

    public function testDefaultCalendarIdIsNullUntilTheDefaultCalendarExists(): void
    {
        // Read-only on purpose (Api\CalendarDirectoryInterface): a §7.6
        // provider asking for the default calendar must get "not yet", not
        // a side-effect creation.
        $this->assertNull($this->service->defaultCalendarId());

        $this->service->ensureDefaultCalendar();

        $default = $this->calendarRepository->findDefaultCalendar();
        $this->assertNotNull($default);
        $this->assertSame($default->id, $this->service->defaultCalendarId());
    }

    public function testSectionActivityForMonthMarksEveryDayAMultiDayEventCovers(): void
    {
        $sectionId = $this->createRegularSection('BAL', 'Baladins');
        $this->service->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);
        $this->assertNotNull($calendar);
        $this->eventRepository->create($calendar->id, 'Week-end', '2026-03-14', '2026-03-15', null, null, null, null, null);

        $activity = $this->service->sectionActivityForMonth(2026, 3, \Core\Security\Role::ADMIN);

        $this->assertArrayHasKey($sectionId, $activity);
        $this->assertSame($sectionId, $activity[$sectionId]->sectionId);
        $this->assertNotSame('', $activity[$sectionId]->color);
        $this->assertSame(
            ['2026-03-14' => ['Week-end'], '2026-03-15' => ['Week-end']],
            $activity[$sectionId]->eventTitlesByDay
        );
    }

    public function testSectionActivityForMonthClampsBoundaryCrossingEventsToTheMonth(): void
    {
        $sectionId = $this->createRegularSection('LOU', 'Louveteaux');
        $this->service->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);
        $this->eventRepository->create($calendar->id, 'Camp', '2026-03-30', '2026-04-02', null, null, null, null, null);

        $activity = $this->service->sectionActivityForMonth(2026, 3, \Core\Security\Role::ADMIN);

        $this->assertSame(
            ['2026-03-30' => ['Camp'], '2026-03-31' => ['Camp']],
            $activity[$sectionId]->eventTitlesByDay
        );
    }

    public function testSectionActivityForMonthHidesCalendarsTheRoleMayNotSee(): void
    {
        // A Staff d'U-branch section calendar defaults to chief-only
        // visibility — an identified (non-chief) viewer must not even see
        // that the section has activity.
        $sectionId = $this->createRegularSection('STAFF', "Staff d'U", 90);
        $this->service->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);
        $this->eventRepository->create($calendar->id, 'Conseil', '2026-03-14', null, null, null, null, null, null);

        $this->assertArrayHasKey($sectionId, $this->service->sectionActivityForMonth(2026, 3, \Core\Security\Role::CHIEF));
        $this->assertArrayNotHasKey($sectionId, $this->service->sectionActivityForMonth(2026, 3, \Core\Security\Role::IDENTIFIED));
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

    // --- IT-03: the pair "who sees" / "who writes" must never contradict
    // itself, and the invariant is kept in the Service, not in the form ---

    public function testNarrowingVisibilityToAdminRaisesTheWriteRoleWithIt(): void
    {
        $id = $this->service->addCalendar('Animateurs', Calendar::VISIBILITY_CHIEF)->id;
        self::assertSame(Calendar::EDIT_ROLE_CHIEF, $this->service->findById($id)->editRoleMin);

        $this->service->updateVisibility($id, Calendar::VISIBILITY_ADMIN);

        // Implied, not refused: a calendar only the chefs d'unité can see
        // cannot be left claiming the animateurs may edit it. Refusing
        // would make this state unreachable in one step, since every
        // calendar starts at 'chief'.
        self::assertSame(Calendar::EDIT_ROLE_ADMIN, $this->service->findById($id)->editRoleMin);
    }

    public function testTheWriteRoleCannotBeWidenedBeyondWhoCanSeeTheCalendar(): void
    {
        $id = $this->service->addCalendar('Animateurs', Calendar::VISIBILITY_ADMIN)->id;

        // The other direction is a request for something impossible, and
        // granting it would silently widen the audience.
        $this->expectException(CalendarException::class);
        $this->service->updateEditRoleMin($id, Calendar::EDIT_ROLE_CHIEF);
    }

    public function testTheWriteRoleIsUnconstrainedOnAVisibleCalendar(): void
    {
        foreach ([Calendar::VISIBILITY_PUBLIC, Calendar::VISIBILITY_CHIEF] as $visibility) {
            $id = $this->service->addCalendar('Cal ' . $visibility, $visibility)->id;

            $this->service->updateEditRoleMin($id, Calendar::EDIT_ROLE_ADMIN);
            self::assertSame(Calendar::EDIT_ROLE_ADMIN, $this->service->findById($id)->editRoleMin);

            $this->service->updateEditRoleMin($id, Calendar::EDIT_ROLE_CHIEF);
            self::assertSame(Calendar::EDIT_ROLE_CHIEF, $this->service->findById($id)->editRoleMin);
        }
    }

    public function testTheWriteRoleOfAnUnknownCalendarIsRefused(): void
    {
        // The id arrives in a request body, so "no such calendar" has to be
        // a refusal here and not a silent no-op UPDATE.
        $this->expectException(CalendarException::class);
        $this->service->updateEditRoleMin(999999, Calendar::EDIT_ROLE_ADMIN);
    }

    public function testAnInvalidWriteRoleIsRefused(): void
    {
        $id = $this->service->addCalendar('Animateurs', Calendar::VISIBILITY_CHIEF)->id;

        $this->expectException(CalendarException::class);
        $this->service->updateEditRoleMin($id, 'intendant');
    }
}
