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
        $blindIndex = $encryption->blindIndex(strtolower($email));

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
            $encryption->encrypt('John'),
            $encryption->encrypt('Doe'),
            $encryption->encrypt($email),
            $blindIndex,
            $totem ? $encryption->encrypt($totem) : null,
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
        $blindIndex = $encryption->blindIndex(strtolower($this->testEmail));
        
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('TEST_OTHER')");
        $memberId = (int) $this->pdo->lastInsertId();
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $otherYearId,
            $encryption->encrypt('John'),
            $encryption->encrypt('Doe'),
            $encryption->encrypt($this->testEmail),
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
            $encryption->encrypt('Rue de la Paix'),
            $encryption->encrypt('1000'),
            $encryption->encrypt('Bruxelles'),
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
}
