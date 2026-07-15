<?php

declare(strict_types=1);

namespace Tests\Core\Member\Service;

use Core\Database\Connection;
use Core\Member\Repository\MemberSearchRepository;
use Core\Member\Service\MemberSearchService;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class MemberSearchServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $enc;
    private MemberSearchService $service;
    private int $yearId;
    private int $otherYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->enc = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $repo = new MemberSearchRepository(Connection::withPdo($this->pdo), $this->enc);
        $this->service = new MemberSearchService($repo);

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->yearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', '2025-08-31')");
        $this->otherYearId = (int) $this->pdo->lastInsertId();
    }

    private function insertMember(
        string $first,
        string $last,
        ?string $totem = null,
        ?string $email = null,
        ?string $mobile = null,
        bool $active = true,
        ?int $yearId = null
    ): int {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D" . uniqid() . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, email_encrypted, mobile_encrypted, is_active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $yearId ?? $this->yearId,
            $this->enc->encrypt($first),
            $this->enc->encrypt($last),
            $totem !== null ? $this->enc->encrypt($totem) : null,
            $email !== null ? $this->enc->encrypt($email) : null,
            $mobile !== null ? $this->enc->encrypt($mobile) : null,
            $active ? 1 : 0,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testSearchByLastName(): void
    {
        $this->insertMember('Jean', 'Dupont');
        $this->insertMember('Marie', 'Martin');

        $results = $this->service->search($this->yearId, 'dupont');

        $this->assertCount(1, $results);
        $this->assertSame('Dupont', $results[0]->lastName);
    }

    public function testSearchByFirstName(): void
    {
        $this->insertMember('Alexandre', 'Dupont');
        $this->insertMember('Marie', 'Martin');

        $results = $this->service->search($this->yearId, 'alex');

        $this->assertCount(1, $results);
        $this->assertSame('Alexandre', $results[0]->firstName);
    }

    public function testSearchByEmail(): void
    {
        $this->insertMember('Jean', 'Dupont', email: 'jean.dupont@example.be');
        $this->insertMember('Marie', 'Martin', email: 'marie@example.be');

        $results = $this->service->search($this->yearId, 'jean.dupont@');

        $this->assertCount(1, $results);
        $this->assertSame('Dupont', $results[0]->lastName);
    }

    public function testSearchByPhone(): void
    {
        $this->insertMember('Jean', 'Dupont', mobile: '0476123456');
        $this->insertMember('Marie', 'Martin', mobile: '0498765432');

        $results = $this->service->search($this->yearId, '0476');

        $this->assertCount(1, $results);
        $this->assertSame('Dupont', $results[0]->lastName);
    }

    public function testSearchIsAccentInsensitive(): void
    {
        $this->insertMember('Jean', 'Dupont', totem: 'Renard Espiègle');

        $results = $this->service->search($this->yearId, 'espiegle');

        $this->assertCount(1, $results);
    }

    public function testEmptyQueryReturnsNothing(): void
    {
        $this->insertMember('Jean', 'Dupont');

        $this->assertSame([], $this->service->search($this->yearId, ''));
        $this->assertSame([], $this->service->search($this->yearId, '   '));
    }

    public function testResultsSortedByLastNameThenFirstName(): void
    {
        $this->insertMember('Bob', 'Zorro', email: 'x@a.be');
        $this->insertMember('Alice', 'Alpha', email: 'x@a.be');
        $this->insertMember('Bea', 'Alpha', email: 'x@a.be');

        $results = $this->service->search($this->yearId, 'x@a.be');

        $this->assertCount(3, $results);
        $this->assertSame('Alpha', $results[0]->lastName);
        $this->assertSame('Alice', $results[0]->firstName);
        $this->assertSame('Alpha', $results[1]->lastName);
        $this->assertSame('Bea', $results[1]->firstName);
        $this->assertSame('Zorro', $results[2]->lastName);
    }

    public function testInactiveMembersAreIncludedAndFlagged(): void
    {
        $this->insertMember('Jean', 'Dupont', active: false);

        $results = $this->service->search($this->yearId, 'dupont');

        $this->assertCount(1, $results);
        $this->assertFalse($results[0]->isActive);
    }

    public function testSearchScopedToYear(): void
    {
        $this->insertMember('Jean', 'Dupont', yearId: $this->otherYearId);

        $this->assertSame([], $this->service->search($this->yearId, 'dupont'));
    }

    public function testFindByIdOnlyReturnsMembersOfTheYear(): void
    {
        $id = $this->insertMember('Jean', 'Dupont', yearId: $this->otherYearId);

        $this->assertNull($this->service->findById($this->yearId, $id));
        $this->assertNotNull($this->service->findById($this->otherYearId, $id));
    }
}
