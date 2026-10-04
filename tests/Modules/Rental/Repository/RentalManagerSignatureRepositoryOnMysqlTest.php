<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalManagerSignatureRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A manager's signature (#708, IT-16) saved by the statement the production
 * engine runs.
 *
 * `RentalManagerSignatureRepository::save()` writes one upsert per dialect;
 * SQLite, the ordinary test engine, only ever runs its `ON CONFLICT` spelling.
 * This class runs the `ON DUPLICATE KEY UPDATE` one, against the production
 * schema and its foreign key to `user_accounts`.
 */
#[Group('database')]
final class RentalManagerSignatureRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private RentalManagerSignatureRepository $repository;
    private int $accountId;

    protected function setUp(): void
    {
        $pdo = $this->productionEngine();
        $this->repository = new RentalManagerSignatureRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute(['chiffré', str_repeat('c', 64)]);
        $this->accountId = (int) $pdo->lastInsertId();
    }

    public function testAFirstSignatureIsCreatedAndASecondReplacesItWhole(): void
    {
        $this->assertFalse($this->repository->has($this->accountId));

        $this->repository->save($this->accountId, 'first', new \DateTimeImmutable());
        $this->repository->save($this->accountId, 'second', new \DateTimeImmutable());

        $this->assertSame('second', $this->repository->findPng($this->accountId));
        $this->assertTrue($this->repository->delete($this->accountId));
        $this->assertNull($this->repository->findPng($this->accountId));
    }
}
