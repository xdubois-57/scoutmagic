<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalManagerSignatureRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Each manager's own signature (#708, IT-16): replaced whole, and never
 * lost to a replacement that fails.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RentalManagerSignatureRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalManagerSignatureRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->repository = new RentalManagerSignatureRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    public function testASignatureIsReplacedWhole(): void
    {
        $this->repository->save(7, 'first', new \DateTimeImmutable());
        $this->repository->save(7, 'second', new \DateTimeImmutable());

        $this->assertSame('second', $this->repository->findPng(7));
    }

    /**
     * The old row is deleted before the new one is written: a write that
     * fails must take the deletion back with it, or the manager is left
     * with no signature at all.
     */
    public function testAReplacementThatFailsKeepsTheSignatureItWasReplacing(): void
    {
        $this->repository->save(7, 'first', new \DateTimeImmutable());
        $this->pdo->exec(
            'CREATE TRIGGER refuse_signature BEFORE INSERT ON rental_manager_signatures '
                . "BEGIN SELECT RAISE(ABORT, 'refused'); END"
        );

        try {
            $this->repository->save(7, 'second', new \DateTimeImmutable());
            $this->fail('the insert was expected to fail');
        } catch (\PDOException) {
        }

        $this->assertSame('first', $this->repository->findPng(7));
    }
}
