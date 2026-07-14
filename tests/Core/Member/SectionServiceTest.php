<?php

declare(strict_types=1);

namespace Tests\Core\Member;

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
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $this->service = new SectionService($connection, $this->encryption);

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

        $this->createMemberInSection($sectionA, 'Alice');
        $this->createMemberInSection($sectionA, 'Bob');
        $this->createMemberInSection($sectionB, 'Charlie');

        $staff = $this->service->getSectionStaff($sectionA, $this->scoutYearId);

        $this->assertCount(2, $staff);
        $names = array_map(fn($p) => $p->firstName, $staff);
        $this->assertContains('Alice', $names);
        $this->assertContains('Bob', $names);
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
}
