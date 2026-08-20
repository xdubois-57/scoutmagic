<?php

declare(strict_types=1);

namespace Tests\Core\Member;

use Core\Member\MemberNotFoundException;
use Core\Member\MemberService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MemberServiceTest extends TestCase
{
    private \PDO $pdo;
    private MemberService $service;
    private int $scoutYearId;
    private string $testEmail = 'test@example.com';

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->service = new MemberService(
            memberYearRepo: new \Core\Import\MemberYearRepository($this->pdo),
            encryption: new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            connection: \Core\Database\Connection::withPdo($this->pdo)
        );

        // Create scout year
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    private function createTestMember(string $email, ?string $totem = null): int
    {
        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $blindIndex = $encryption->blindIndex(strtolower($email), 'email');

        // Create member
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('TEST_" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        // Create member_year
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index, totem_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $encryption->encrypt('John', 'member_years.first_name'),
            $encryption->encrypt('Doe', 'member_years.last_name'),
            $encryption->encrypt($email, 'member_years.email'),
            $blindIndex,
            $totem ? $encryption->encrypt($totem, 'member_years.totem') : null,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function testGetLinkedMembersReturnsCorrectMembersForAnEmail(): void
    {
        $memberYearId1 = $this->createTestMember($this->testEmail, 'Baloo');
        $memberYearId2 = $this->createTestMember($this->testEmail, 'Mowgli');

        $members = $this->service->getLinkedMembers($this->testEmail, $this->scoutYearId);

        $this->assertCount(2, $members);
        $this->assertSame('Baloo', $members[0]->getDisplayName());
        $this->assertSame('Mowgli', $members[1]->getDisplayName());
    }

    public function testGetLinkedMembersReturnsEmptyArrayForUnknownEmail(): void
    {
        $members = $this->service->getLinkedMembers('unknown@example.com', $this->scoutYearId);
        $this->assertEmpty($members);
    }

    public function testGetLinkedMembersReturnsMembersFromCorrectScoutYearOnly(): void
    {
        // Create a member for current year
        $this->createTestMember($this->testEmail, 'Baloo');

        // Create another scout year and a member for it
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2024-2025', '2024-09-01', '2025-08-31', 0)");
        $otherYearId = (int) $this->pdo->lastInsertId();
        $otherMemberRepo = new \Core\Import\MemberYearRepository($this->pdo);
        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $blindIndex = $encryption->blindIndex(strtolower($this->testEmail), 'email');
        
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('TEST_OTHER')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $otherYearId,
            $encryption->encrypt('John', 'member_years.first_name'),
            $encryption->encrypt('Doe', 'member_years.last_name'),
            $encryption->encrypt($this->testEmail, 'member_years.email'),
            $blindIndex,
        ]);

        // Should only return members from current year
        $members = $this->service->getLinkedMembers($this->testEmail, $this->scoutYearId);
        $this->assertCount(1, $members);
        $this->assertSame('Baloo', $members[0]->getDisplayName());

        // Should return empty for other year
        $members = $this->service->getLinkedMembers($this->testEmail, $otherYearId);
        $this->assertCount(1, $members);
    }

    public function testGetMemberProfileReturnsFullyDecryptedProfile(): void
    {
        $memberYearId = $this->createTestMember($this->testEmail, 'Baloo');

        $profile = $this->service->getMemberProfile($memberYearId);

        $this->assertSame($memberYearId, $profile->memberYearId);
        $this->assertSame('John', $profile->firstName);
        $this->assertSame('Doe', $profile->lastName);
        $this->assertSame('Baloo', $profile->totem);
        $this->assertSame($this->testEmail, $profile->email);
        $this->assertSame('Baloo', $profile->getDisplayName());
    }

    public function testGetMemberProfileIncludesAddressesAndFunctions(): void
    {
        $memberYearId = $this->createTestMember($this->testEmail);

        // Add an address
        $encryption = new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, postal_code_encrypted, city_encrypted)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberYearId,
            'Domicile',
            $encryption->encrypt('Rue de la Paix', 'member_addresses.street'),
            $encryption->encrypt('1000', 'member_addresses.postal_code'),
            $encryption->encrypt('Bruxelles', 'member_addresses.city'),
        ]);

        // Add a function
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('Animateur', 'Animateur', 'identified')");
        $functionId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, is_main_function) VALUES (?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId]);

        $profile = $this->service->getMemberProfile($memberYearId);

        $this->assertCount(1, $profile->addresses);
        $this->assertSame('Domicile', $profile->addresses[0]->type);
        $this->assertSame('Rue de la Paix', $profile->addresses[0]->street);

        $this->assertCount(1, $profile->functions);
        $this->assertSame('Animateur', $profile->functions[0]->functionLabel);
        $this->assertTrue($profile->functions[0]->isMainFunction);
    }

    public function testGetMemberProfileThrowsForNonExistentMemberYear(): void
    {
        $this->expectException(MemberNotFoundException::class);
        $this->service->getMemberProfile(99999);
    }

    public function testCanAccessReturnsTrueForMatchingEmail(): void
    {
        $memberYearId = $this->createTestMember($this->testEmail);

        $this->assertTrue($this->service->canAccess($this->testEmail, $memberYearId, 'identified'));
    }

    public function testCanAccessReturnsTrueForChiefRole(): void
    {
        $memberYearId = $this->createTestMember('different@example.com');

        $this->assertTrue($this->service->canAccess('any@example.com', $memberYearId, 'chief'));
        $this->assertTrue($this->service->canAccess('any@example.com', $memberYearId, 'admin'));
    }

    public function testCanAccessReturnsFalseForDifferentEmailWithIdentifiedRole(): void
    {
        $memberYearId = $this->createTestMember($this->testEmail);

        $this->assertFalse($this->service->canAccess('different@example.com', $memberYearId, 'identified'));
        $this->assertFalse($this->service->canAccess('different@example.com', $memberYearId, 'intendant'));
    }

    public function testFindProfileByMemberAndYearResolvesViaPersistentIdentity(): void
    {
        $this->createTestMember($this->testEmail, 'Akela');
        $memberId = (int) $this->pdo->query('SELECT id FROM members ORDER BY id DESC LIMIT 1')->fetchColumn();

        $profile = $this->service->findProfileByMemberAndYear($memberId, $this->scoutYearId);

        $this->assertNotNull($profile);
        $this->assertSame('Akela', $profile->totem);
    }

    public function testFindProfileByMemberAndYearReturnsNullWhenNoRowForThatYear(): void
    {
        $this->createTestMember($this->testEmail, 'Akela');
        $memberId = (int) $this->pdo->query('SELECT id FROM members ORDER BY id DESC LIMIT 1')->fetchColumn();

        $this->assertNull($this->service->findProfileByMemberAndYear($memberId, 999999));
    }

    public function testFindProfileByMemberAndYearReturnsNullForUnknownMemberId(): void
    {
        $this->assertNull($this->service->findProfileByMemberAndYear(999999, $this->scoutYearId));
    }

    public function testIsUnitChiefIsFalseWithoutLinkedMembers(): void
    {
        $this->assertFalse($this->service->isUnitChief('nobody@example.com', $this->scoutYearId));
    }

    public function testIsUnitChiefIsFalseForAChiefOfAnOrdinarySection(): void
    {
        $memberYearId = $this->createTestMember($this->testEmail);
        $this->createMainFunction($memberYearId, 'chief', 'BAL01');

        $this->assertFalse($this->service->isUnitChief($this->testEmail, $this->scoutYearId));
    }

    public function testIsUnitChiefIsTrueForAStaffDuMember(): void
    {
        $memberYearId = $this->createTestMember($this->testEmail);
        $this->createMainFunction($memberYearId, 'admin', \Core\Member\UnitStaffSectionService::DESK_CODE);

        $this->assertTrue($this->service->isUnitChief($this->testEmail, $this->scoutYearId));
    }

    /**
     * Creates a confirmed, main function for $memberYearId with the given
     * role and section desk_code (creating the section on the fly if
     * needed) — the exact shape isUnitChief() reads via getMainFunction().
     */
    private function createMainFunction(int $memberYearId, string $role, string $sectionDeskCode): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM sections WHERE desk_code = ?');
        $stmt->execute([$sectionDeskCode]);
        $sectionId = $stmt->fetchColumn();
        if ($sectionId === false) {
            $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BR_" . uniqid() . "', 'Branch', 10)");
            $branchId = (int) $this->pdo->lastInsertId();
            $stmt = $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name) VALUES (?, ?, ?)');
            $stmt->execute([$sectionDeskCode, $branchId, $sectionDeskCode]);
            $sectionId = (int) $this->pdo->lastInsertId();
        }

        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('FN_" . uniqid() . "', 'Fonction', '{$role}')");
        $functionId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)'
        );
        $stmt->execute([$memberYearId, $functionId, $sectionId]);
    }
}
