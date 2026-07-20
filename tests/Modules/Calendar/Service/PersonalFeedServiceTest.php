<?php

declare(strict_types=1);

namespace Tests\Modules\Calendar\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Modules\Calendar\Repository\Calendar;
use Modules\Calendar\Repository\CalendarEventRepository;
use Modules\Calendar\Repository\CalendarPersonalTokenRepository;
use Modules\Calendar\Repository\CalendarRepository;
use Modules\Calendar\Repository\CalendarUnitFeedTokenRepository;
use Modules\Calendar\Service\CalendarService;
use Modules\Calendar\Service\PersonalFeedService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Calendar\CalendarTestHelper;

/**
 * @group database
 */
class PersonalFeedServiceTest extends TestCase
{
    private \PDO $pdo;
    private PersonalFeedService $service;
    private CalendarService $calendarService;
    private CalendarEventRepository $eventRepository;
    private CalendarPersonalTokenRepository $tokenRepository;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        CalendarTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $calendarRepository = new CalendarRepository($this->pdo);
        $this->eventRepository = new CalendarEventRepository($this->pdo);
        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, $memberBadgeRepository);
        $this->calendarService = new CalendarService($calendarRepository, $this->eventRepository, $sectionService, new CalendarUnitFeedTokenRepository($this->pdo));
        $this->tokenRepository = new CalendarPersonalTokenRepository($this->pdo);

        $memberYearRepo = new MemberYearRepository($this->pdo);
        $roleResolver = new RoleResolver($memberYearRepo, $this->encryption, $this->pdo);
        $memberService = new MemberService($memberYearRepo, $this->encryption, $connection);
        $userAccountRepository = new UserAccountRepository($this->pdo, $this->encryption);

        $this->service = new PersonalFeedService(
            $this->tokenRepository,
            $this->calendarService,
            $this->eventRepository,
            $roleResolver,
            $memberService,
            $userAccountRepository,
            $sectionService
        );

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

    private function createUserAccount(string $email): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$this->encryption->encrypt($email), $this->encryption->blindIndex(strtolower($email))]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return int member_year_id
     */
    private function createMemberWithFunction(string $email, int $sectionId, int $branchId, string $functionRole): int
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Jean'),
            $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($email),
            $this->encryption->blindIndex(strtolower($email)),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES ('{$functionRole}', '{$functionRole}', '{$functionRole}', 1)");
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = (int) $stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function) VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return $memberYearId;
    }

    public function testGetEventsForTokenReturnsEmptyForUnknownToken(): void
    {
        $this->assertSame([], $this->service->getEventsForToken('nope', $this->scoutYearId));
    }

    public function testGetEventsForTokenReturnsEmptyWhenVisitorHasNoLinkedMembersAndIsNotChief(): void
    {
        $userAccountId = $this->createUserAccount('nobody@test.be');
        $token = $this->service->getOrCreateToken($userAccountId);

        $events = $this->service->getEventsForToken($token, $this->scoutYearId);

        $this->assertSame([], $events);
    }

    public function testGetEventsForTokenIncludesLinkedSectionEvents(): void
    {
        $email = 'parent@test.be';
        $sectionId = $this->createSection('BAL01', 'Renards');
        $branchId = (int) $this->pdo->query("SELECT age_branch_id FROM sections WHERE id = {$sectionId}")->fetchColumn();
        $this->createMemberWithFunction($email, $sectionId, $branchId, 'identified');

        $this->calendarService->ensureSectionCalendars();
        $sectionCalendar = (new CalendarRepository($this->pdo))->findBySectionId($sectionId);
        $this->eventRepository->create($sectionCalendar->id, 'Réunion', '2026-03-15', null, null, null, null, null, null);

        $userAccountId = $this->createUserAccount($email);
        $token = $this->service->getOrCreateToken($userAccountId);

        $events = $this->service->getEventsForToken($token, $this->scoutYearId);

        $this->assertCount(1, $events);
        $this->assertSame('Réunion', $events[0]->title);
    }

    public function testGetEventsForTokenExcludesUnlinkedSectionEvents(): void
    {
        $sectionId = $this->createSection('BAL01', 'Renards');
        $this->calendarService->ensureSectionCalendars();
        $sectionCalendar = (new CalendarRepository($this->pdo))->findBySectionId($sectionId);
        $this->eventRepository->create($sectionCalendar->id, 'Réunion', '2026-03-15', null, null, null, null, null, null);

        // Visitor has no linked members at all.
        $userAccountId = $this->createUserAccount('stranger@test.be');
        $token = $this->service->getOrCreateToken($userAccountId);

        $this->assertSame([], $this->service->getEventsForToken($token, $this->scoutYearId));
    }

    public function testGetEventsForTokenIncludesAnimateursCalendarForChief(): void
    {
        $email = 'chief@test.be';
        $sectionId = $this->createSection('BAL01', 'Renards');
        $branchId = (int) $this->pdo->query("SELECT age_branch_id FROM sections WHERE id = {$sectionId}")->fetchColumn();
        $this->createMemberWithFunction($email, $sectionId, $branchId, 'chief');

        $this->calendarService->ensureDefaultCalendar();
        $default = (new CalendarRepository($this->pdo))->findDefaultCalendar();
        $this->eventRepository->create($default->id, 'Réunion animateurs', '2026-03-15', null, null, null, null, null, null);

        $userAccountId = $this->createUserAccount($email);
        $token = $this->service->getOrCreateToken($userAccountId);

        $events = $this->service->getEventsForToken($token, $this->scoutYearId);

        $titles = array_map(fn($e) => $e->title, $events);
        $this->assertContains('Réunion animateurs', $titles);
    }

    public function testGetEventsForTokenExcludesAnimateursCalendarForNonChief(): void
    {
        // "Animateurs" defaults to chief-only visibility (not public — it's
        // meant for identified animateurs, not anonymous public visitors,
        // but "identified" itself is below the chief-visibility gate), so a
        // plain identified visitor does not see it.
        $email = 'anime@test.be';
        $sectionId = $this->createSection('BAL01', 'Renards');
        $branchId = (int) $this->pdo->query("SELECT age_branch_id FROM sections WHERE id = {$sectionId}")->fetchColumn();
        $this->createMemberWithFunction($email, $sectionId, $branchId, 'identified');

        $this->calendarService->ensureDefaultCalendar();
        $default = (new CalendarRepository($this->pdo))->findDefaultCalendar();
        $this->eventRepository->create($default->id, 'Réunion animateurs', '2026-03-15', null, null, null, null, null, null);

        $userAccountId = $this->createUserAccount($email);
        $token = $this->service->getOrCreateToken($userAccountId);

        $events = $this->service->getEventsForToken($token, $this->scoutYearId);

        $this->assertSame([], $events);
    }

    public function testGetEventsForTokenExcludesChiefOnlySupplementaryCalendarForNonChief(): void
    {
        $email = 'anime@test.be';
        $sectionId = $this->createSection('BAL01', 'Renards');
        $branchId = (int) $this->pdo->query("SELECT age_branch_id FROM sections WHERE id = {$sectionId}")->fetchColumn();
        $this->createMemberWithFunction($email, $sectionId, $branchId, 'identified');

        $chiefOnly = $this->calendarService->addCalendar('Réservé chefs', 'chief');
        $this->eventRepository->create($chiefOnly->id, 'Réunion chefs', '2026-03-15', null, null, null, null, null, null);

        $userAccountId = $this->createUserAccount($email);
        $token = $this->service->getOrCreateToken($userAccountId);

        $events = $this->service->getEventsForToken($token, $this->scoutYearId);

        $this->assertSame([], $events);
    }

    public function testGetEventsForTokenIncludesNonDefaultSupplementaryCalendarVisibleToChief(): void
    {
        // Widened behavior: every role-visible supplementary calendar is
        // included, not just the one flagged "default" (Animateurs).
        $email = 'chief@test.be';
        $sectionId = $this->createSection('BAL01', 'Renards');
        $branchId = (int) $this->pdo->query("SELECT age_branch_id FROM sections WHERE id = {$sectionId}")->fetchColumn();
        $this->createMemberWithFunction($email, $sectionId, $branchId, 'chief');

        $custom = $this->calendarService->addCalendar('Anniversaires', 'chief');
        $this->eventRepository->create($custom->id, 'Anniversaire', '2026-03-15', null, null, null, null, null, null);

        $userAccountId = $this->createUserAccount($email);
        $token = $this->service->getOrCreateToken($userAccountId);

        $events = $this->service->getEventsForToken($token, $this->scoutYearId);

        $titles = array_map(fn($e) => $e->title, $events);
        $this->assertContains('Anniversaire', $titles);
    }

    public function testGetEventsForTokenIncludesStaffduEventsForChiefDUnite(): void
    {
        // No section assigned in the CSV sense — an admin-role function
        // with section_id NULL, exactly what a chef d'unité function looks
        // like before UnitStaffSectionService::syncMembership() runs.
        $email = 'cu@test.be';
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('CU', 'Chef Unité', 'admin', 1)");
        $functionId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_CU')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId, $this->encryption->encrypt('CU'), $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($email), $this->encryption->blindIndex(strtolower($email)),
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, NULL)');
        $stmt->execute([$memberYearId, $functionId]);

        // Sync membership into the real "Staff d'U" section (mirrors what
        // DeskImportService/FunctionsController trigger in production).
        $unitStaffSectionService = new UnitStaffSectionService($this->pdo);
        $unitStaffSectionService->syncMembership($this->scoutYearId);

        $this->calendarService->ensureSectionCalendars();
        $staffduId = $unitStaffSectionService->ensureSection();
        $staffduCalendar = (new CalendarRepository($this->pdo))->findBySectionId($staffduId);
        $this->eventRepository->create($staffduCalendar->id, 'Réunion CU', '2026-03-15', null, null, null, null, null, null);

        $userAccountId = $this->createUserAccount($email);
        $token = $this->service->getOrCreateToken($userAccountId);

        $events = $this->service->getEventsForToken($token, $this->scoutYearId);

        $titles = array_map(fn($e) => $e->title, $events);
        $this->assertContains('Réunion CU', $titles);
    }

    public function testResolveCalendarIdsForEmailCanBeCalledDirectlyWithoutAToken(): void
    {
        // Used by the chefs calendar page's "Mes évènements" entry, which
        // knows the logged-in chief's email from the session directly —
        // no personal token involved.
        $email = 'chief@test.be';
        $sectionId = $this->createSection('BAL01', 'Renards');
        $branchId = (int) $this->pdo->query("SELECT age_branch_id FROM sections WHERE id = {$sectionId}")->fetchColumn();
        $this->createMemberWithFunction($email, $sectionId, $branchId, 'chief');
        $this->calendarService->ensureSectionCalendars();
        $this->calendarService->ensureDefaultCalendar();

        $ids = $this->service->resolveCalendarIdsForEmail($email, $this->scoutYearId);

        $sectionCalendar = (new CalendarRepository($this->pdo))->findBySectionId($sectionId);
        $default = (new CalendarRepository($this->pdo))->findDefaultCalendar();
        $this->assertContains($sectionCalendar->id, $ids);
        $this->assertContains($default->id, $ids);
    }

    public function testGetOrCreateTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        $userAccountId = $this->createUserAccount('x@test.be');

        $first = $this->service->getOrCreateToken($userAccountId);
        $second = $this->service->getOrCreateToken($userAccountId);

        $this->assertSame($first, $second);
    }

    public function testRegenerateTokenInvalidatesThePreviousOne(): void
    {
        $userAccountId = $this->createUserAccount('x@test.be');
        $oldToken = $this->service->getOrCreateToken($userAccountId);

        $newToken = $this->service->regenerateToken($userAccountId);

        $this->assertNotSame($oldToken, $newToken);
        $this->assertNull($this->tokenRepository->findUserAccountIdByToken($oldToken));
        $this->assertSame($userAccountId, $this->tokenRepository->findUserAccountIdByToken($newToken));
    }
}
