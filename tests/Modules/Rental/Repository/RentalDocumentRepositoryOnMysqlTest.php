<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Document\DocumentType;
use Modules\Rental\Document\RentalDocument;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The lock the answer to a renter's copy is decided under (#708, IT-16),
 * on the engine that actually takes it.
 *
 * `RentalDocumentRepository::withBookingLocked()` holds the booking's row
 * while a countersignature or a refusal checks the copy is still waiting
 * and writes its answer, so two managers answering the same copy at once
 * cannot both file a signed contract. SQLite, the ordinary test engine,
 * takes the method's other branch — no `FOR UPDATE` — so this class runs
 * it on the production schema (`Tests\UsesProductionEngine`) and watches a
 * second session wait.
 */
#[Group('database')]
final class RentalDocumentRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private RentalDocumentRepository $repository;
    private int $bookingId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new RentalDocumentRepository($this->pdo);

        $this->pdo->exec(
            "INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')"
        );
        $assetId = (int) $this->pdo->lastInsertId();

        $bookings = new RentalBookingRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $created = $bookings->create(
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
        );
        $this->bookingId = $created['id'];
    }

    /**
     * The premise: this connection is not SQLite, so the lock is issued.
     * Were it SQLite, the tests below would pass without testing anything.
     */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /** While an answer is being written, a second one waits for the booking. */
    public function testASecondAnswerWaitsWhileTheFirstHoldsTheBooking(): void
    {
        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = null;
        $this->repository->withBookingLocked($this->bookingId, function () use ($other, &$blocked): void {
            try {
                $other->beginTransaction();
                $probe = $other->prepare('SELECT id FROM rental_bookings WHERE id = ? FOR UPDATE');
                $probe->execute([$this->bookingId]);
                $probe->fetchAll();
                $blocked = false;
            } catch (\PDOException $e) {
                $blocked = str_contains(strtolower($e->getMessage()), 'lock');
            } finally {
                if ($other->inTransaction()) {
                    $other->rollBack();
                }
            }
        });

        $this->assertTrue(
            $blocked,
            'a second session took the booking while an answer held it, so two managers can still '
                . 'countersign the same copy'
        );
    }

    /** A failure inside the work rolls back what it wrote, and releases the booking. */
    public function testAFailureRollsBackAndReleases(): void
    {
        try {
            $this->repository->withBookingLocked($this->bookingId, function (): void {
                $this->pdo->prepare('UPDATE rental_bookings SET units = 9 WHERE id = ?')->execute([$this->bookingId]);
                throw new \RuntimeException('assembly failed');
            });
            $this->fail('the failure was expected to surface');
        } catch (\RuntimeException) {
        }

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        $probe = $other->prepare('SELECT units FROM rental_bookings WHERE id = ? FOR UPDATE');
        $probe->execute([$this->bookingId]);
        $this->assertSame(1, (int) $probe->fetchColumn(), 'nothing written by the failed work remains');
        $other->rollBack();
    }

    /**
     * What a billing document bills (#980, chantier decision 2.8), on the real
     * schema: the three columns round-trip, and a supplement is found by
     * the settlement it bills — the question « Générer » asks under the lock.
     */
    public function testWhatAnInvoiceAndItsSupplementBillRoundTrips(): void
    {
        $invoiceId = $this->repository->create(
            $this->bookingId,
            1,
            DocumentType::INVOICE,
            1,
            true,
            null,
            null,
            RentalDocument::SOURCE_MANUAL,
            null,
            null,
            null,
            46750
        );
        $supplementId = $this->repository->create(
            $this->bookingId,
            2,
            DocumentType::SUPPLEMENTARY_INVOICE,
            1,
            true,
            null,
            null,
            RentalDocument::SOURCE_MANUAL,
            null,
            null,
            41,
            51750,
            $invoiceId
        );

        $invoice = $this->repository->findById($invoiceId);
        $this->assertNull($invoice?->settlementId);
        $this->assertSame(46750, $invoice?->billedKeptCents);
        $this->assertNull($invoice?->supplementsDocumentId);

        $supplement = $this->repository->findById($supplementId);
        $this->assertSame(DocumentType::SUPPLEMENTARY_INVOICE, $supplement?->type);
        $this->assertSame(41, $supplement?->settlementId);
        $this->assertSame(51750, $supplement?->billedKeptCents);
        $this->assertSame($invoiceId, $supplement?->supplementsDocumentId);

        $this->assertTrue($this->repository->hasSupplementForSettlement($this->bookingId, 41));
        $this->assertFalse($this->repository->hasSupplementForSettlement($this->bookingId, 42));
        // An invoice billing a settlement is not its supplement.
        $this->repository->create(
            $this->bookingId,
            3,
            DocumentType::INVOICE,
            2,
            true,
            null,
            null,
            RentalDocument::SOURCE_MANUAL,
            null,
            null,
            43,
            50000
        );
        $this->assertFalse($this->repository->hasSupplementForSettlement($this->bookingId, 43));
    }

    private function secondConnection(): \PDO
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        return self::productionEngineConnection($database)->getPdo();
    }
}
