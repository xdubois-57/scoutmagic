<?php

declare(strict_types=1);

namespace Tests\Core\Contact\Device;

use Core\Contact\Device\DeviceCredentialRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `device_credentials`, and the one layer that ever sees a secret's hash.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeviceCredentialRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private DeviceCredentialRepository $repository;
    private int $accountId;
    private int $otherAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new DeviceCredentialRepository($this->pdo);

        $this->accountId = $this->insertAccount('a');
        $this->otherAccountId = $this->insertAccount('b');
    }

    private function insertAccount(string $marker): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)'
        );
        $stmt->execute(['x', str_repeat($marker, 64)]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * The secret is never stored, and nothing in this class hands one
     * back: the column holds a SHA-256 and the object holds no secret at
     * all.
     */
    public function testOnlyTheHashIsStored(): void
    {
        $this->repository->create($this->accountId, 'Téléphone', 'the-secret');

        $stored = (string) $this->pdo->query('SELECT secret_hash FROM device_credentials')->fetchColumn();

        $this->assertSame(hash('sha256', 'the-secret'), $stored);
        $this->assertStringNotContainsString('the-secret', $stored);
    }

    public function testASecretMatchesItsOwnCredentialAndNothingElse(): void
    {
        $id = $this->repository->create($this->accountId, 'Téléphone', 'right')->id;
        $this->repository->create($this->accountId, 'Ordinateur', 'other');

        $this->assertSame($id, $this->repository->findLiveMatching($this->accountId, 'right')?->id);
        $this->assertNull($this->repository->findLiveMatching($this->accountId, 'wrong'));
        $this->assertNull($this->repository->findLiveMatching($this->accountId, ''));
    }

    /**
     * A credential authenticates its own account and no other, even with
     * the right secret: the account is part of what is matched, not a
     * label hung on the result.
     */
    public function testASecretDoesNotWorkOnSomebodyElsesAccount(): void
    {
        $this->repository->create($this->accountId, 'Téléphone', 'shared-string');

        $this->assertNull($this->repository->findLiveMatching($this->otherAccountId, 'shared-string'));
    }

    public function testARevokedCredentialStopsMatchingAtOnce(): void
    {
        $id = $this->repository->create($this->accountId, 'Téléphone', 'secret')->id;
        $this->assertNotNull($this->repository->findLiveMatching($this->accountId, 'secret'));

        $this->repository->revoke($id);

        $this->assertNull($this->repository->findLiveMatching($this->accountId, 'secret'));
    }

    /**
     * Revoking is a stamp, not a delete: the row is the evidence that the
     * device existed, and the copy it pulled down is still on it.
     */
    public function testRevokingKeepsTheRowAndIsIdempotent(): void
    {
        $id = $this->repository->create($this->accountId, 'Téléphone', 'secret')->id;

        $this->repository->revoke($id);
        $first = $this->repository->findById($id)?->revokedAt;
        $this->repository->revoke($id);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM device_credentials')->fetchColumn());
        $this->assertEquals($first, $this->repository->findById($id)?->revokedAt);
    }

    public function testACredentialIsCreatedHavingNeverSynchronised(): void
    {
        $id = $this->repository->create($this->accountId, 'Téléphone', 'secret')->id;
        $credential = $this->repository->findById($id);

        $this->assertNotNull($credential);
        $this->assertTrue($credential->hasNeverSynchronised());
        $this->assertNull($credential->lastSyncAt);

        $this->repository->touchSync($id);

        $this->assertFalse($this->repository->findById($id)?->hasNeverSynchronised());
    }

    public function testACredentialListsOnlyItsOwnAccountAndLiveOnesFirst(): void
    {
        $revoked = $this->repository->create($this->accountId, 'Ancien', 'a')->id;
        $this->repository->revoke($revoked);
        $live = $this->repository->create($this->accountId, 'Actuel', 'b')->id;
        $this->repository->create($this->otherAccountId, 'Ailleurs', 'c');

        $mine = $this->repository->findAllForAccount($this->accountId);

        $this->assertSame([$live, $revoked], array_map(static fn($c): int => $c->id, $mine));
        $this->assertSame(1, $this->repository->countLiveForAccount($this->accountId));
        $this->assertCount(3, $this->repository->findAll());
    }

    public function testAnUnknownCredentialIsSimplyNull(): void
    {
        $this->assertNull($this->repository->findById(999999));
        $this->assertNull($this->repository->findLiveMatching($this->accountId, 'anything'));
    }
}
