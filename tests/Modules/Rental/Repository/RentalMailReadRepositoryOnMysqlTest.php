<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Rental\Repository\RentalMailReadRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The same person reading a booking's mail on two tabs (#720), on the
 * engine that reports the collision: the primary key answers SQLSTATE
 * 23000 there, and a trigger refusing the row 45000 — on SQLite both
 * carry code 19. Two sessions: this one has already read when the other
 * tab's row lands.
 */
#[Group('database')]
final class RentalMailReadRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private \PDO $otherSession;
    private RentalMailReadRepository $repository;
    private int $bookingId;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->otherSession = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $this->repository = new RentalMailReadRepository($this->pdo);
        $this->bookingId = RentalMilestoneMarkRepositoryOnMysqlTest::booking($this->pdo);
        $this->accountId = (new UserAccountRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        ))->create('gestionnaire@test.be')->id;
    }

    /**
     * The other tab wrote the row between this one's read and its insert:
     * the position still moves forward to what this tab was shown.
     */
    public function testTheOtherTabWritingTheRowFirstStillMovesThePositionForward(): void
    {
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT COUNT(*) FROM rental_booking_mail_reads')->fetchColumn();
        (new RentalMailReadRepository($this->otherSession))->markRead($this->bookingId, $this->accountId, 5);

        $this->repository->markRead($this->bookingId, $this->accountId, 9);
        $this->pdo->commit();

        $this->assertSame(
            [$this->bookingId => 9],
            $this->repository->readUpTo($this->accountId, [$this->bookingId])
        );
    }

    /** A write the database refuses is not the other tab: it surfaces. */
    public function testAWriteTheDatabaseRefusesSurfacesAndRecordsNothing(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER refuse_mail_reads BEFORE INSERT ON rental_booking_mail_reads FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'lecture refusée'"
        );

        try {
            $this->repository->markRead($this->bookingId, $this->accountId, 9);
            $this->fail('a refused write must not be taken for the other tab');
        } catch (\PDOException $e) {
            $this->assertSame('45000', (string) $e->getCode());
        } finally {
            $this->pdo->exec('DROP TRIGGER refuse_mail_reads');
        }

        $this->assertSame([], $this->repository->readUpTo($this->accountId, [$this->bookingId]));
    }
}
