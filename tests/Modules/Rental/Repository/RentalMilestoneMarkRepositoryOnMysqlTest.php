<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalMilestoneMarkRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * A tick that loses a race, on the engine that reports it (issue #462, D5).
 *
 * `mark()` reads, then inserts; the unique index is the backstop for two
 * presses racing past the read. What it must tell apart is only visible
 * on MySQL/MariaDB: there, the unique index answers SQLSTATE 23000 and a
 * trigger refusing the row answers 45000 — on SQLite both carry code 19.
 * The race is played across two sessions: this one has already read when
 * the other one's tick lands.
 */
#[Group('database')]
final class RentalMilestoneMarkRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private \PDO $otherSession;
    private RentalMilestoneMarkRepository $repository;
    private int $bookingId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->otherSession = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $this->repository = new RentalMilestoneMarkRepository($this->pdo);
        $this->bookingId = self::booking($this->pdo);
    }

    /**
     * The other manager's tick lands between this one's read and its
     * insert: « already ticked », and theirs is the one kept.
     */
    public function testATickThatLosesTheRaceIsAlreadyTickedAndKeepsTheFirst(): void
    {
        // Our read is taken before their write and does not see it, as
        // when the two requests interleave.
        $this->pdo->beginTransaction();
        $this->pdo->query('SELECT COUNT(*) FROM rental_booking_milestone_marks')->fetchColumn();
        (new RentalMilestoneMarkRepository($this->otherSession))
            ->mark($this->bookingId, 'arrival_inventory', 41, new \DateTimeImmutable('2027-07-01 09:00:00'));

        $marked = $this->repository
            ->mark($this->bookingId, 'arrival_inventory', 42, new \DateTimeImmutable('2027-07-01 09:00:05'));
        $this->pdo->commit();

        $this->assertFalse($marked, 'a second press is not a new fact');
        $this->assertSame(
            41,
            $this->repository->findForBooking($this->bookingId)['arrival_inventory']['marked_by_member_id'] ?? null,
            'the first tick is the one that counts'
        );
    }

    /** Any other refusal is a real failure, and surfaces as one. */
    public function testAWriteTheDatabaseRefusesIsAFailureNotAnAlreadyTicked(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER refuse_milestone_marks BEFORE INSERT ON rental_booking_milestone_marks FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'coche refusée'"
        );

        try {
            $this->repository->mark($this->bookingId, 'arrival_inventory', 42, new \DateTimeImmutable());
            $this->fail('a refused write must not read as « already ticked »');
        } catch (\PDOException $e) {
            $this->assertSame('45000', (string) $e->getCode());
        } finally {
            $this->pdo->exec('DROP TRIGGER refuse_milestone_marks');
        }

        $this->assertSame([], $this->repository->findForBooking($this->bookingId));
    }

    /** A booking for the marks to hang on: the table's foreign key wants one. */
    public static function booking(\PDO $pdo): int
    {
        $pdo->exec("INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')");
        $assetId = (int) $pdo->lastInsertId();

        return (new RentalBookingRepository($pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))))
            ->create(
                $assetId,
                'LOC-A2B3C4',
                '2027-07-01',
                '2027-07-04',
                1,
                20,
                null,
                [
                    'name' => 'Jeanne Martin',
                    'email' => 'jeanne@example.be',
                    'phone' => null,
                    'organisation' => null,
                    'purpose' => null,
                    'comment' => null,
                ],
                null,
                null,
                null,
                'v1',
                str_repeat('0', 64),
                'v1',
                str_repeat('0', 64),
                new \DateTimeImmutable('2027-01-01 10:00:00')
            )['id'];
    }
}
