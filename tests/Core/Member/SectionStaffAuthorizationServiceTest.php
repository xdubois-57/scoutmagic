<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Member\MemberEmailRepository;
use Core\Member\SectionService;
use Core\Member\SectionStaffAuthorizationService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SectionStaffAuthorizationServiceTest extends TestCase
{
    private \PDO $pdo;
    private SectionStaffAuthorizationService $service;
    private SectionStaffAuthorizationService $serviceWithoutSecondaryAddresses;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $this->service = new SectionStaffAuthorizationService(
            $connection,
            $this->encryption,
            $sectionService,
            new MemberEmailRepository($this->pdo, $this->encryption)
        );
        $this->serviceWithoutSecondaryAddresses = new SectionStaffAuthorizationService($connection, $this->encryption, $sectionService);

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
     * @return int the members.id row created, for the secondary-address cases
     */
    private function createAccountWithFunction(string $email, string $functionRole, array $sectionIds): int
    {
        $blindIndex = $this->encryption->blindIndex(strtolower(trim($email)), 'email');

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

        return $memberId;
    }

    private function addSecondaryAddress(int $memberId, string $email, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_emails (member_id, email_encrypted, email_blind_index, source, status) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->encryption->encrypt($email),
            $this->encryption->blindIndex(strtolower(trim($email)), 'email'),
            'manual',
            $status,
        ]);
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

    public function testSigningInWithAValidSecondaryAddressStillStaffsTheSection(): void
    {
        $sectionA = $this->createSection('A');
        $memberId = $this->createAccountWithFunction('desk@test.com', 'chief', [$sectionA]);
        $this->addSecondaryAddress($memberId, 'perso@test.com', 'valid');

        $sections = $this->service->getStaffedSections('perso@test.com', 'chief', $this->scoutYearId);

        $this->assertCount(1, $sections);
        $this->assertSame($sectionA, $sections[0]['id']);
    }

    public function testAPendingSecondaryAddressStaffsNothing(): void
    {
        $sectionA = $this->createSection('A');
        $memberId = $this->createAccountWithFunction('desk@test.com', 'chief', [$sectionA]);
        $this->addSecondaryAddress($memberId, 'perso@test.com', 'pending');

        $this->assertSame([], $this->service->getStaffedSections('perso@test.com', 'chief', $this->scoutYearId));
    }

    public function testAnInactiveSecondaryAddressStaffsNothing(): void
    {
        $sectionA = $this->createSection('A');
        $memberId = $this->createAccountWithFunction('desk@test.com', 'chief', [$sectionA]);
        $this->addSecondaryAddress($memberId, 'perso@test.com', 'inactive');

        $this->assertSame([], $this->service->getStaffedSections('perso@test.com', 'chief', $this->scoutYearId));
    }

    public function testASecondaryAddressOfAnAnimeStaffsNothing(): void
    {
        $sectionA = $this->createSection('A');
        $memberId = $this->createAccountWithFunction('anime@test.com', 'identified', [$sectionA]);
        $this->addSecondaryAddress($memberId, 'perso@test.com', 'valid');

        $this->assertSame([], $this->service->getStaffedSections('perso@test.com', 'identified', $this->scoutYearId));
    }

    public function testTheDeskAddressStillResolvesWhenSecondaryAddressesExist(): void
    {
        $sectionA = $this->createSection('A');
        $memberId = $this->createAccountWithFunction('desk@test.com', 'chief', [$sectionA]);
        $this->addSecondaryAddress($memberId, 'perso@test.com', 'valid');

        $sections = $this->service->getStaffedSections('desk@test.com', 'chief', $this->scoutYearId);

        $this->assertCount(1, $sections);
        $this->assertSame($sectionA, $sections[0]['id']);
    }

    /**
     * The optional dependency fails CLOSED: a call site that forgets the
     * repository loses the secondary-address path and returns fewer
     * sections, never more.
     */
    public function testWithoutTheEmailRepositoryASecondaryAddressStaffsNothing(): void
    {
        $sectionA = $this->createSection('A');
        $memberId = $this->createAccountWithFunction('desk@test.com', 'chief', [$sectionA]);
        $this->addSecondaryAddress($memberId, 'perso@test.com', 'valid');

        $this->assertSame([], $this->serviceWithoutSecondaryAddresses->getStaffedSections('perso@test.com', 'chief', $this->scoutYearId));
        $this->assertCount(1, $this->serviceWithoutSecondaryAddresses->getStaffedSections('desk@test.com', 'chief', $this->scoutYearId));
    }
}
