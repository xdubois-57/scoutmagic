<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class SectionServiceTest extends TestCase
{
    private \PDO $pdo;
    private SectionService $service;
    private EncryptionService $encryption;
    private MemberBadgeRepository $memberBadgeRepository;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $this->memberBadgeRepository = new MemberBadgeRepository($this->pdo);
        $this->service = new SectionService($connection, $this->encryption, $this->memberBadgeRepository);

        // Create scout year
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        // Create age branches
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BAL', 'Baladins', 1)");
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 2)");
    }

    private function createSection(string $deskCode, int $branchId, ?string $name = null, ?string $email = null): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, email) VALUES (?, ?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $name, $email]);
        return (int) $this->pdo->lastInsertId();
    }

    private function createMemberInSection(int $sectionId, string $firstName, string $functionRole = 'identified', ?string $formationLevel = null): int
    {
        // Create member
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        // Create member_year
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, phone_encrypted, mobile_encrypted, formation_level)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName),
            $this->encryption->encrypt('Dupont'),
            $this->encryption->encrypt($firstName . '@test.be'),
            $this->encryption->blindIndex($firstName . '@test.be'),
            $this->encryption->encrypt('0470123456'),
            $this->encryption->encrypt('0498765432'),
            $formationLevel,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        // Create function
        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('{$functionRole}', 'Animateur {$functionRole}', '{$functionRole}')");
        $stmt = $this->pdo->prepare('SELECT id FROM functions WHERE desk_code = ?');
        $stmt->execute([$functionRole]);
        $functionId = (int) $stmt->fetchColumn();

        // Get branch from section
        $stmt = $this->pdo->prepare('SELECT age_branch_id FROM sections WHERE id = ?');
        $stmt->execute([$sectionId]);
        $branchId = (int) $stmt->fetchColumn();

        // Create member_function
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
             VALUES (?, ?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId, $branchId]);

        return $memberYearId;
    }

    public function testGetAllWithBranchesReturnsOrderedSections(): void
    {
        // Get branch IDs
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();
        $stmt->execute(['LOU']);
        $louId = (int) $stmt->fetchColumn();

        $this->createSection('BAL01', $balId, 'Baladins A');
        $this->createSection('LOU01', $louId, 'Louveteaux A');
        $this->createSection('BAL02', $balId, 'Baladins B');

        $sections = $this->service->getAllWithBranches();

        $this->assertCount(3, $sections);
        // Baladins (sort_order 1) should come before Louveteaux (sort_order 2)
        $this->assertSame('Baladins', $sections[0]['branch_name']);
        $this->assertSame('Baladins', $sections[1]['branch_name']);
        $this->assertSame('Louveteaux', $sections[2]['branch_name']);
    }

    public function testGetSectionStaffReturnsMembersLinkedToSection(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();
        $stmt->execute(['LOU']);
        $louId = (int) $stmt->fetchColumn();

        $sectionA = $this->createSection('BAL01', $balId);
        $sectionB = $this->createSection('LOU01', $louId);

        $this->createMemberInSection($sectionA, 'Alice', 'chief');
        $this->createMemberInSection($sectionA, 'Bob', 'admin');
        $this->createMemberInSection($sectionB, 'Charlie', 'chief');

        $staff = $this->service->getSectionStaff($sectionA, $this->scoutYearId);

        $this->assertCount(2, $staff);
        $names = array_map(fn($p) => $p->firstName, $staff);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
    }

    public function testGetSectionStaffExcludesAnimeRoleMembers(): void
    {
        // A section's animés also carry a section_id on their
        // member_functions row — getSectionStaff() must filter them out.
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId);
        $this->createMemberInSection($sectionId, 'Alice', 'chief');
        $this->createMemberInSection($sectionId, 'Petit Loup', 'identified');

        $staff = $this->service->getSectionStaff($sectionId, $this->scoutYearId);

        $names = array_map(fn($p) => $p->firstName, $staff);
        $this->assertContains('Alice', $names);
        $this->assertNotContains('Petit Loup', $names);
    }

    public function testGetSectionStaffIncludesActiveBadges(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId);
        $memberYearId = $this->createMemberInSection($sectionId, 'Alice', 'chief');

        $this->pdo->exec("INSERT INTO badges (name) VALUES ('Infirmier')");
        $badgeId = (int) $this->pdo->lastInsertId();
        $this->memberBadgeRepository->assign($memberYearId, $badgeId, null);

        $staff = $this->service->getSectionStaff($sectionId, $this->scoutYearId);

        $this->assertCount(1, $staff);
        $this->assertCount(1, $staff[0]->badges);
        $this->assertSame('Infirmier', $staff[0]->badges[0]->name);
    }

    public function testGetSectionStaffReturnsDecryptedData(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId);
        $this->createMemberInSection($sectionId, 'Alice', 'chief', 'Animateur breveté');

        $staff = $this->service->getSectionStaff($sectionId, $this->scoutYearId);

        $this->assertCount(1, $staff);
        $member = $staff[0];
        $this->assertSame('Alice', $member->firstName);
        $this->assertSame('Dupont', $member->lastName);
        $this->assertSame('Alice@test.be', $member->email);
        $this->assertSame('0470123456', $member->phone);
        $this->assertSame('0498765432', $member->mobile);
        $this->assertSame('Animateur breveté', $member->formationLevel);
    }

    public function testUpdateSectionInfoUpdatesNameAndEmail(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId, 'Old Name', 'old@test.be');
        $this->service->updateSectionInfo($sectionId, 'New Name', 'new@test.be');

        $section = $this->service->getSection($sectionId);
        $this->assertSame('New Name', $section['name']);
        $this->assertSame('new@test.be', $section['email']);
    }

    public function testUpdateSectionInfoWithNullNameClearsName(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId, 'Some Name', 'email@test.be');
        $this->service->updateSectionInfo($sectionId, null, null);

        $section = $this->service->getSection($sectionId);
        $this->assertNull($section['name']);
        $this->assertNull($section['email']);
    }

    public function testUpdateSectionInfoWithEmptyStringClearsName(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId, 'Some Name');
        $this->service->updateSectionInfo($sectionId, '', '');

        $section = $this->service->getSection($sectionId);
        $this->assertNull($section['name']);
        $this->assertNull($section['email']);
    }

    public function testGetSectionReturnsSectionWithBranchInfo(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['LOU']);
        $louId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('LOU01', $louId, 'Ma Meute', 'meute@test.be');
        $section = $this->service->getSection($sectionId);

        $this->assertNotNull($section);
        $this->assertSame($sectionId, $section['id']);
        $this->assertSame('LOU01', $section['desk_code']);
        $this->assertSame('Ma Meute', $section['name']);
        $this->assertSame('meute@test.be', $section['email']);
        $this->assertSame('Louveteaux', $section['branch_name']);
    }

    public function testGetSectionReturnsNullForNonExistent(): void
    {
        $this->assertNull($this->service->getSection(9999));
    }

    public function testGetSectionStaffReturnsEmptyForSectionWithNoMembers(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId);
        $staff = $this->service->getSectionStaff($sectionId, $this->scoutYearId);
        $this->assertEmpty($staff);
    }

    public function testGetAllWithBranchesExcludesHiddenSectionsByDefault(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $visibleId = $this->createSection('BAL01', $balId);
        $hiddenId = $this->createSection('BAL02', $balId);
        $this->service->updateSectionVisibility($hiddenId, false);

        $ids = array_column($this->service->getAllWithBranches(), 'id');

        $this->assertContains($visibleId, $ids);
        $this->assertNotContains($hiddenId, $ids);
    }

    public function testGetAllWithBranchesIncludesHiddenWhenRequested(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $hiddenId = $this->createSection('BAL01', $balId);
        $this->service->updateSectionVisibility($hiddenId, false);

        $ids = array_column($this->service->getAllWithBranches(includeHidden: true), 'id');

        $this->assertContains($hiddenId, $ids);
    }

    public function testUpdateSectionVisibilityCanBeToggledBackOn(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId);
        $this->service->updateSectionVisibility($sectionId, false);
        $this->service->updateSectionVisibility($sectionId, true);

        $ids = array_column($this->service->getAllWithBranches(), 'id');
        $this->assertContains($sectionId, $ids);
    }

    public function testNewSectionsAreVisibleByDefault(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $sectionId = $this->createSection('BAL01', $balId);

        $sections = $this->service->getAllWithBranches();
        $section = array_values(array_filter($sections, fn(array $s) => $s['id'] === $sectionId))[0];
        $this->assertTrue($section['is_visible']);
    }

    public function testGetAllWithBranchesExcludesInactiveSectionsByDefault(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $activeId = $this->createSection('BAL01', $balId);
        $inactiveId = $this->createSection('BAL02', $balId);
        $this->pdo->exec("UPDATE sections SET is_active = 0 WHERE id = {$inactiveId}");

        $ids = array_column($this->service->getAllWithBranches(), 'id');

        $this->assertContains($activeId, $ids);
        $this->assertNotContains($inactiveId, $ids);
    }

    public function testGetAllWithBranchesExcludesInactiveEvenWhenIncludeHiddenIsTrue(): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM age_branches WHERE desk_code = ?');
        $stmt->execute(['BAL']);
        $balId = (int) $stmt->fetchColumn();

        $inactiveId = $this->createSection('BAL01', $balId);
        $this->pdo->exec("UPDATE sections SET is_active = 0 WHERE id = {$inactiveId}");

        // includeHidden only bypasses is_visible — an inactive section stays
        // excluded everywhere, including the Config Desk admin listing.
        $ids = array_column($this->service->getAllWithBranches(includeHidden: true), 'id');

        $this->assertNotContains($inactiveId, $ids);
    }

    public function testColorForSectionReturnsDedicatedColorForStaffdu(): void
    {
        $color = SectionService::colorForSection(['desk_code' => 'STAFFDU', 'branch_sort_order' => 50]);

        $this->assertMatchesRegularExpression('/^#[0-9A-Fa-f]{6}$/', $color);
        // Distinct from a regular branch color (never falls back to the
        // colorForBranchSortOrder() gray, since sort_order 50 has no entry
        // in MemberYearService::BRANCHES).
        $this->assertNotSame('#6c757d', $color);
    }

    public function testColorForSectionDelegatesToBranchColorForRegularSections(): void
    {
        $color = SectionService::colorForSection(['desk_code' => 'BAL01', 'branch_sort_order' => 10]);

        $this->assertSame(\Core\Member\MemberYearService::colorForBranchSortOrder(10), $color);
    }

    public function testColorForSectionIsConsistentBetweenStaffduAndRegularSections(): void
    {
        $staffduColor = SectionService::colorForSection(['desk_code' => 'STAFFDU', 'branch_sort_order' => 50]);
        $regularColor = SectionService::colorForSection(['desk_code' => 'BAL01', 'branch_sort_order' => 10]);

        $this->assertNotSame($staffduColor, $regularColor);
    }

    public function testColorForSectionExplicitOverrideWinsOverBranchDefault(): void
    {
        $color = SectionService::colorForSection(['desk_code' => 'BAL01', 'branch_sort_order' => 10, 'color' => '#123456']);

        $this->assertSame('#123456', $color);
    }

    public function testColorForSectionExplicitOverrideWinsOverStaffduDefault(): void
    {
        $color = SectionService::colorForSection(['desk_code' => 'STAFFDU', 'branch_sort_order' => 50, 'color' => '#123456']);

        $this->assertSame('#123456', $color);
    }

    public function testColorForSectionIgnoresEmptyOverride(): void
    {
        $color = SectionService::colorForSection(['desk_code' => 'BAL01', 'branch_sort_order' => 10, 'color' => '']);

        $this->assertSame(\Core\Member\MemberYearService::colorForBranchSortOrder(10), $color);
    }

    public function testUpdateSectionColorPersistsOverride(): void
    {
        $sectionId = $this->createSection('BAL01', 1);

        $this->service->updateSectionColor($sectionId, '#123456');

        $section = $this->service->getSection($sectionId);
        $this->assertSame('#123456', $section['color']);
    }

    public function testUpdateSectionColorClearsOverrideWhenNull(): void
    {
        $sectionId = $this->createSection('BAL01', 1);
        $this->service->updateSectionColor($sectionId, '#123456');

        $this->service->updateSectionColor($sectionId, null);

        $section = $this->service->getSection($sectionId);
        $this->assertNull($section['color']);
    }

    public function testUpdateSectionColorRejectsInvalidHex(): void
    {
        $sectionId = $this->createSection('BAL01', 1);

        $this->expectException(\InvalidArgumentException::class);
        $this->service->updateSectionColor($sectionId, 'not-a-color');
    }
}
