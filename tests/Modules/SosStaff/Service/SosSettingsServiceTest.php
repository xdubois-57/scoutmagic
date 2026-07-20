<?php

declare(strict_types=1);

namespace Tests\Modules\SosStaff\Service;

use Core\Badge\MemberBadgeRepository;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\Member\UnitStaffSectionService;
use Core\Security\EncryptionService;
use Modules\SosStaff\Repository\ExcludedSectionRepository;
use Modules\SosStaff\Repository\SosSettingsRepository;
use Modules\SosStaff\Service\SosException;
use Modules\SosStaff\Service\SosSettingsService;
use Modules\Trombinoscope\Repository\TrombinoscopeRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\SosStaff\SosStaffTestHelper;

/**
 * @group database
 */
class SosSettingsServiceTest extends TestCase
{
    private \PDO $pdo;
    private SosSettingsService $service;
    private EncryptionService $encryption;
    private UnitStaffSectionService $unitStaffSectionService;
    private SectionService $sectionService;
    private MemberYearRepository $memberYearRepository;
    private SettingService $settingService;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SosStaffTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $this->sectionService = new SectionService($connection, $this->encryption, $memberBadgeRepository);
        $this->memberYearRepository = new MemberYearRepository($this->pdo);
        $this->unitStaffSectionService = new UnitStaffSectionService($this->pdo);
        $this->settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService->register('transition_hour', '10:00', 'text', 'Heure', 'desc', 'sos_staff');
        $this->settingService->register('email_notifications_enabled', '1', 'boolean', 'Emails', 'desc', 'sos_staff');

        $this->service = $this->buildService(null);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    private function buildService(?TrombinoscopeRepository $trombinoscopeRepository): SosSettingsService
    {
        return new SosSettingsService(
            new ExcludedSectionRepository($this->pdo),
            new SosSettingsRepository($this->pdo),
            $this->sectionService,
            $this->memberYearRepository,
            $this->unitStaffSectionService,
            $this->settingService,
            $trombinoscopeRepository
        );
    }

    private function createSection(string $deskCode, int $branchSortOrder = 10): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO age_branches (desk_code, label, sort_order) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $deskCode, $branchSortOrder]);
        $branchId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $deskCode]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return int member_id (persistent identity)
     */
    private function createStaffduMember(string $totem, ?string $mobile): int
    {
        $staffduId = $this->unitStaffSectionService->ensureSection();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Jean'),
            $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($totem),
            $mobile !== null ? $this->encryption->encrypt($mobile) : null,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role, confirmed) VALUES ('CU', 'Chef Unité', 'admin', 1)");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = 'CU'")->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $staffduId]);

        return $memberId;
    }

    /**
     * @return int member_id (persistent identity)
     */
    private function createStaffduMemberWithFunction(string $totem, ?string $mobile, int $functionId): int
    {
        $staffduId = $this->unitStaffSectionService->ensureSection();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, mobile_encrypted)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId, $this->scoutYearId,
            $this->encryption->encrypt('Jean'),
            $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($totem),
            $mobile !== null ? $this->encryption->encrypt($mobile) : null,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $staffduId]);

        return $memberId;
    }

    public function testGetExcludedSectionIdsAlwaysIncludesStaffdu(): void
    {
        $staffduId = $this->unitStaffSectionService->ensureSection();

        $excluded = $this->service->getExcludedSectionIds();

        $this->assertContains($staffduId, $excluded);
    }

    public function testUpdateExcludedSectionsPersistsNonStaffduSections(): void
    {
        $sectionA = $this->createSection('ROU01');

        $this->service->updateExcludedSections([$sectionA]);

        $excluded = $this->service->getExcludedSectionIds();
        $this->assertContains($sectionA, $excluded);
    }

    public function testUpdateExcludedSectionsCannotRemoveStaffduFromResult(): void
    {
        $staffduId = $this->unitStaffSectionService->ensureSection();

        // Attempt to submit only STAFFDU (as if trying to "deselect" it —
        // it's still forced back in by getExcludedSectionIds() regardless).
        $this->service->updateExcludedSections([$staffduId]);

        $this->assertContains($staffduId, $this->service->getExcludedSectionIds());
    }

    public function testGetExcludedSectionIdsSeedsCoreBranchesAsIncludedByDefault(): void
    {
        $baladins = $this->createSection('BAL01', 10);

        $excluded = $this->service->getExcludedSectionIds();

        $this->assertNotContains($baladins, $excluded);
    }

    public function testGetExcludedSectionIdsSeedsOtherBranchesAsExcludedByDefault(): void
    {
        $route = $this->createSection('ROU01', 60);

        $excluded = $this->service->getExcludedSectionIds();

        $this->assertContains($route, $excluded);
    }

    public function testDefaultSeedingRunsOnlyOnceAndNeverOverwritesAnExplicitChoice(): void
    {
        $route = $this->createSection('ROU01', 60);

        // First read seeds defaults — Route is excluded.
        $this->assertContains($route, $this->service->getExcludedSectionIds());

        // Admin explicitly re-includes everything.
        $this->service->updateExcludedSections([]);
        $this->assertNotContains($route, $this->service->getExcludedSectionIds());

        // A later read must not re-run the seeding and re-exclude Route.
        $this->assertNotContains($route, $this->service->getExcludedSectionIds());
    }

    public function testGetStaffOptionsListsMembersWithMobile(): void
    {
        $this->createStaffduMember('Akela', '+32470000001');

        $options = $this->service->getStaffOptions($this->scoutYearId);

        $this->assertCount(1, $options);
        $this->assertSame('Akela', $options[0]['label']);
        $this->assertSame('+32470000001', $options[0]['mobile']);
    }

    public function testGetStaffOptionsExcludesMembersWithoutMobile(): void
    {
        $this->createStaffduMember('Akela', null);

        $options = $this->service->getStaffOptions($this->scoutYearId);

        $this->assertSame([], $options);
    }

    public function testGetDefaultNumberReturnsNullWhenNothingConfiguredAndNoRoster(): void
    {
        $this->assertNull($this->service->getDefaultNumber($this->scoutYearId));
    }

    public function testGetDefaultNumberResolvesLiveMobileForSelectedMember(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');

        $this->service->setDefaultNumberFromMember($memberId);

        $this->assertSame('+32470000001', $this->service->getDefaultNumber($this->scoutYearId));
    }

    public function testGetDefaultNumberReturnsNullWhenSelectedMemberHasNoRowThisYear(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');
        $this->service->setDefaultNumberFromMember($memberId);

        $this->assertNull($this->service->getDefaultNumber(999999));
    }

    public function testGetDefaultNumberAutoResolvesToFirstRosterMemberWhenNoExplicitChoice(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');

        // No setDefaultNumberFromMember() call — the module invariant is
        // "there is always a default number", so this must still resolve.
        $this->assertSame('+32470000001', $this->service->getDefaultNumber($this->scoutYearId));
        $this->assertSame($memberId, $this->service->resolveDefaultNumberMemberId($this->scoutYearId));
    }

    public function testResolveDefaultNumberMemberIdExplicitChoiceWinsOverAutoResolution(): void
    {
        $akela = $this->createStaffduMember('Akela', '+32470000001');
        $baloo = $this->createStaffduMember('Baloo', '+32470000002');

        $this->service->setDefaultNumberFromMember($baloo);

        $this->assertSame($baloo, $this->service->resolveDefaultNumberMemberId($this->scoutYearId));
        $this->assertNotSame($akela, $this->service->resolveDefaultNumberMemberId($this->scoutYearId));
    }

    public function testResolveDefaultNumberMemberIdPrefersSectionResponsableOverFirstRosterMember(): void
    {
        $this->pdo->exec('CREATE TABLE trombinoscope_function_flags (
            function_id INTEGER PRIMARY KEY,
            is_lead INTEGER NOT NULL DEFAULT 0
        )');
        $trombinoscopeRepository = new TrombinoscopeRepository(Connection::withPdo($this->pdo));
        $service = $this->buildService($trombinoscopeRepository);

        $first = $this->createStaffduMember('Akela', '+32470000001');

        // A distinct function from createStaffduMember()'s shared 'CU' one
        // (otherwise flagging it as lead would flag Akela's identical
        // function too, and the test couldn't tell the two apart).
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('RESP', 'Responsable', 'admin', 1)");
        $leadFunctionId = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare('INSERT INTO trombinoscope_function_flags (function_id, is_lead) VALUES (?, 1)')
            ->execute([$leadFunctionId]);

        $responsable = $this->createStaffduMemberWithFunction('Baloo', '+32470000002', $leadFunctionId);

        $this->assertSame($responsable, $service->resolveDefaultNumberMemberId($this->scoutYearId));
        $this->assertNotSame($first, $service->resolveDefaultNumberMemberId($this->scoutYearId));
    }

    public function testLabelForMemberReturnsDisplayName(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');

        $this->assertSame('Akela', $this->service->labelForMember($memberId, $this->scoutYearId));
    }

    public function testLabelForMemberReturnsNullWhenNoRowForThatYear(): void
    {
        $memberId = $this->createStaffduMember('Akela', '+32470000001');

        $this->assertNull($this->service->labelForMember($memberId, 999999));
    }

    public function testGetTransitionHourReturnsRegisteredDefault(): void
    {
        $this->assertSame('10:00', $this->service->getTransitionHour());
    }

    public function testSetTransitionHourRejectsInvalidFormat(): void
    {
        $this->expectException(SosException::class);
        $this->service->setTransitionHour('25:99');
    }

    public function testSetTransitionHourPersistsValidValue(): void
    {
        $this->service->setTransitionHour('08:30');

        $this->assertSame('08:30', $this->service->getTransitionHour());
    }

    public function testEmailNotificationsDefaultToEnabled(): void
    {
        $this->assertTrue($this->service->isEmailNotificationsEnabled());
    }

    public function testSetEmailNotificationsEnabledPersistsFalse(): void
    {
        $this->service->setEmailNotificationsEnabled(false);

        $this->assertFalse($this->service->isEmailNotificationsEnabled());
    }
}
