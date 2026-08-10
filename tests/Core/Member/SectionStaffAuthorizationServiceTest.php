<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class SectionStaffAuthorizationServiceTest extends TestCase
{
    private \PDO $pdo;
    private SectionStaffAuthorizationService $service;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $this->service = new SectionStaffAuthorizationService($connection, $this->encryption, $sectionService);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 2)");
    }

    private function createSection(string $deskCode): int
    {
        $branchId = (int) $this->pdo->query('SELECT id FROM age_branches LIMIT 1')->fetchColumn();

        $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
        $stmt->execute([$deskCode, $branchId, $deskCode]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @param array<int, int> $sectionIds
     */
    private function createAccountWithFunction(string $email, string $functionRole, array $sectionIds): void
    {
        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)));

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_blind_index) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $this->scoutYearId, 'enc', 'enc', $blindIndex]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT OR IGNORE INTO functions (desk_code, label, role) VALUES ('{$functionRole}', '{$functionRole}', '{$functionRole}')");
        $functionId = (int) $this->pdo->query("SELECT id FROM functions WHERE desk_code = '{$functionRole}'")->fetchColumn();

        foreach ($sectionIds as $sectionId) {
            $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id) VALUES (?, ?, ?)');
            $stmt->execute([$memberYearId, $functionId, $sectionId]);
        }
    }

    public function testChiefOfOneSectionGetsOnlyThatSection(): void
    {
        $sectionA = $this->createSection('A');
        $sectionB = $this->createSection('B');
        $this->createAccountWithFunction('chief@test.com', 'chief', [$sectionA]);

        $sections = $this->service->getStaffedSections('chief@test.com', 'chief', $this->scoutYearId);

        $this->assertCount(1, $sections);
        $this->assertSame($sectionA, $sections[0]['id']);
        $this->assertNotSame($sectionB, $sections[0]['id']);
    }

    public function testChiefOfTwoSectionsGetsBoth(): void
    {
        $sectionA = $this->createSection('A');
        $sectionB = $this->createSection('B');
        $this->createAccountWithFunction('chief@test.com', 'chief', [$sectionA, $sectionB]);

        $sections = $this->service->getStaffedSections('chief@test.com', 'chief', $this->scoutYearId);
        $ids = array_map(fn(array $s) => $s['id'], $sections);

        $this->assertCount(2, $sections);
        $this->assertContains($sectionA, $ids);
        $this->assertContains($sectionB, $ids);
    }

    public function testAnimeGetsNothingDespiteSharingTheSectionId(): void
    {
        $sectionA = $this->createSection('A');
        $this->createAccountWithFunction('anime@test.com', 'identified', [$sectionA]);

        $sections = $this->service->getStaffedSections('anime@test.com', 'identified', $this->scoutYearId);

        $this->assertSame([], $sections);
    }

    public function testAdminGetsEverySection(): void
    {
        $this->createSection('A');
        $this->createSection('B');

        $sections = $this->service->getStaffedSections('nobody@test.com', 'admin', $this->scoutYearId);

        $this->assertCount(2, $sections);
    }

    public function testSuperadminGetsEverySection(): void
    {
        $this->createSection('A');
        $this->createSection('B');

        $sections = $this->service->getStaffedSections('nobody@test.com', 'superadmin', $this->scoutYearId);

        $this->assertCount(2, $sections);
    }

    public function testAccountWithNoDeskLinkGetsNothing(): void
    {
        $this->createSection('A');

        $sections = $this->service->getStaffedSections('unknown@test.com', 'chief', $this->scoutYearId);

        $this->assertSame([], $sections);
    }
}
