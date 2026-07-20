<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarPersonalTokenRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarPickerService;
use Modules\Calendar\Service\CalendarService;
use Modules\Calendar\Service\PersonalFeedService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class CalendarPickerServiceTest extends TestCase
{
    private \PDO $pdo;
    private CalendarPickerService $picker;
    private CalendarService $calendarService;
    private CalendarRepository $calendarRepository;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $this->calendarRepository = new CalendarRepository($this->pdo);
        $eventRepository = new CalendarEventRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $encryption, $memberBadgeRepository);
        $this->calendarService = new CalendarService(
            $this->calendarRepository,
            $eventRepository,
            $sectionService,
            new CalendarUnitFeedTokenRepository($this->pdo)
        );

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $roleResolver = new RoleResolver($memberYearRepo, $encryption, $this->pdo);
        $memberService = new MemberService($memberYearRepo, $encryption, $connection);
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);
        $personalFeedService = new PersonalFeedService(
            new CalendarPersonalTokenRepository($this->pdo),
            $this->calendarService,
            $eventRepository,
            $roleResolver,
            $memberService,
            $userAccountRepository,
            $sectionService
        );

        $this->picker = new CalendarPickerService($this->calendarService, $personalFeedService);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    private function createSection(string $deskCode, string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $deskCode, 10]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testBuildOptionsPrependsMyEventsSentinel(): void
    {
        $options = $this->picker->buildOptions([]);

        $this->assertCount(1, $options);
        $this->assertSame(CalendarPickerService::MY_EVENTS_ID, $options[0]['id']);
        $this->assertSame('Mes évènements', $options[0]['label']);
        $this->assertNull($options[0]['color']);
    }

    public function testBuildOptionsIncludesLabelAndColorForEachEligibleCalendar(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);

        $options = $this->picker->buildOptions([$calendar]);

        $this->assertCount(2, $options);
        $this->assertSame($calendar->id, $options[1]['id']);
        $this->assertSame('Renards', $options[1]['label']);
        $this->assertNotNull($options[1]['color']);
    }

    public function testResolveSelectedCalendarIdDefaultsToMyEventsWhenNoneRequested(): void
    {
        $selected = $this->picker->resolveSelectedCalendarId(null, []);
        $this->assertSame(CalendarPickerService::MY_EVENTS_ID, $selected);

        $selected = $this->picker->resolveSelectedCalendarId('', []);
        $this->assertSame(CalendarPickerService::MY_EVENTS_ID, $selected);
    }

    public function testResolveSelectedCalendarIdAcceptsEligibleId(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);

        $selected = $this->picker->resolveSelectedCalendarId((string) $calendar->id, [$calendar]);

        $this->assertSame($calendar->id, $selected);
    }

    public function testResolveSelectedCalendarIdRejectsIneligibleId(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);

        $selected = $this->picker->resolveSelectedCalendarId('999999', [$calendar]);

        $this->assertSame(CalendarPickerService::MY_EVENTS_ID, $selected);
    }

    public function testResolveCalendarIdsForGridReturnsSingleIdForSpecificSelection(): void
    {
        $ids = $this->picker->resolveCalendarIdsForGrid(42, [], 'someone@test.be', $this->scoutYearId);

        $this->assertSame([42], $ids);
    }

    public function testResolveCalendarIdsForGridResolvesMyEventsForAuthenticatedEmailViaPersonalFeed(): void
    {
        // "Animateurs" defaults to chief-only visibility (not public), so
        // a genuinely authenticated but unlinked identified visitor's
        // "Mes évènements" (a real, narrow personal scope) must not
        // include it — unlike the anonymous-visitor fallback below.
        $this->calendarService->ensureDefaultCalendar();
        $default = $this->calendarRepository->findDefaultCalendar();
        \assert($default !== null);

        $ids = $this->picker->resolveCalendarIdsForGrid(
            CalendarPickerService::MY_EVENTS_ID,
            [$default],
            'someone@test.be',
            $this->scoutYearId
        );

        $this->assertNotContains($default->id, $ids);
    }

    public function testResolveCalendarIdsForGridFallsBackToEveryEligibleCalendarForAnonymousVisitor(): void
    {
        // An empty email (no session at all) has no personal scope to
        // resolve — falls back to "everything eligible" so the public
        // page still shows the unit's activities by default, rather than
        // resolving through PersonalFeedService (which would incorrectly
        // narrow to "linked sections only", i.e. almost nothing).
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $calendar = $this->calendarRepository->findBySectionId($sectionId);

        $ids = $this->picker->resolveCalendarIdsForGrid(
            CalendarPickerService::MY_EVENTS_ID,
            [$calendar],
            '',
            $this->scoutYearId
        );

        $this->assertSame([$calendar->id], $ids);
    }
}
