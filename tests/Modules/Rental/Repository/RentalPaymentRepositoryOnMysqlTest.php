<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalPaymentRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The deposit's deadline, set once by the first contract sent (issue #927),
 * on the engine production runs.
 *
 * `RentalPaymentRepository::setDepositDueDateIfUnset()` decides in the
 * statement itself (`WHERE deposit_due_date IS NULL`) and reports through
 * `rowCount()`. SQLite counts the rows MATCHED, MySQL and MariaDB the rows
 * CHANGED; the predicate keeps the two answers equal — a date already set
 * is never matched, whatever its value — and this class pins that where it
 * matters.
 */
#[Group('database')]
final class RentalPaymentRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private RentalPaymentRepository $repository;
    private int $bookingId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RentalPaymentRepository($this->pdo, $encryption);

        $this->pdo->exec(
            "INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')"
        );
        $assetId = (int) $this->pdo->lastInsertId();

        $created = (new RentalBookingRepository($this->pdo, $encryption))->create(
            $assetId,
            'LOC-A2B3C4',
            '2027-07-17',
            '2027-07-20',
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

    private function storedDate(): ?string
    {
        return $this->repository->loadBookingPayment($this->bookingId)['deposit_due_date'] ?? null;
    }

    public function testTheFirstCallSetsTheDateAndSaysSo(): void
    {
        $this->assertTrue($this->repository->setDepositDueDateIfUnset($this->bookingId, '2027-02-15'));

        $this->assertSame('2027-02-15', $this->storedDate());
    }

    public function testALaterCallWithAnotherDateChangesNothingAndSaysSo(): void
    {
        $this->repository->setDepositDueDateIfUnset($this->bookingId, '2027-02-15');

        $this->assertFalse($this->repository->setDepositDueDateIfUnset($this->bookingId, '2027-03-30'));

        $this->assertSame('2027-02-15', $this->storedDate());
    }

    public function testALaterCallWithTheSameDateReadsAsAlreadySet(): void
    {
        // Already set is already set: the same value is not a first send,
        // and the answer does not depend on how the engine counts rows.
        $this->repository->setDepositDueDateIfUnset($this->bookingId, '2027-02-15');

        $this->assertFalse($this->repository->setDepositDueDateIfUnset($this->bookingId, '2027-02-15'));

        $this->assertSame('2027-02-15', $this->storedDate());
    }

    public function testAnUnknownBookingSetsNothing(): void
    {
        $this->assertFalse($this->repository->setDepositDueDateIfUnset($this->bookingId + 999, '2027-02-15'));
    }
}
