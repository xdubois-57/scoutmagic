<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\ModificationTerms;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalModificationService;
use Modules\Rental\Service\RentalOccupancyGuard;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;
use Tests\UsesProductionEngine;

/**
 * A modification project whose row the engine production runs refuses
 * (#927, chantier §2.3).
 *
 * `saveProject()` reads the duplicate key's SQLSTATE 23000 as « another
 * manager opened a project between the check and the write ». Any other
 * refusal of the insert is not that, and must not be told as that: it
 * reaches the caller as it is, and nothing of the project — its row, its
 * line in the booking's history — is left behind. Here, a trigger that
 * SIGNALs 45000, as a refusing engine answers; SQLite cannot stand in,
 * since its triggers abort with 23000 itself.
 */
#[Group('database')]
final class RentalModificationServiceOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalAsset $asset;
    private RentalBooking $booking;
    private RentalModificationService $service;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $assets = new RentalAssetRepository($this->pdo, $this->encryption);
        $bookings = new RentalBookingRepository($this->pdo, $this->encryption);
        $assetId = $assets->create('Local', 'Local Saint-Georges', 'local', 60, 1, null, null, null, true);
        $this->asset = $assets->findById($assetId) ?? self::fail('no asset');
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
        $this->booking = $bookings->findById($created['id']) ?? self::fail('no booking');

        $pricing = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journal
        );
        $availability = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo)
        );
        $this->service = new RentalModificationService(
            $bookings,
            new RentalModificationRepository($this->pdo),
            new RentalDocumentRepository($this->pdo),
            new RentalOccupancyGuard($bookings, $availability, $pricing),
            $availability,
            $pricing,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $journal
        );
    }

    public function testAProjectRowTheEngineRefusesIsNotToldAsAProjectAlreadyInProgress(): void
    {
        $this->pdo->exec(
            "CREATE TRIGGER refuse_project BEFORE INSERT ON rental_booking_modifications FOR EACH ROW
             SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'projet refusé'"
        );
        // Five more people, same dates: no occupation to check.
        $terms = new ModificationTerms(
            '2027-07-01',
            '2027-07-04',
            1,
            25,
            new PriceQuote([], 48000, 3, 25, 1, BillingUnit::PER_NIGHT)
        );

        try {
            $this->service->saveProject($this->booking, $this->asset, null, $terms, 1, new \DateTimeImmutable());
            $this->fail('a refused project row was reported as saved');
        } catch (\PDOException $e) {
            $this->assertSame('45000', (string) $e->getCode());
        } finally {
            $this->pdo->exec('DROP TRIGGER refuse_project');
        }

        $this->assertSame(0, (int) $this->pdo->query(
            'SELECT COUNT(*) FROM rental_booking_modifications'
        )->fetchColumn());
        $this->assertSame([], RentalTestHelper::bookingHistory($this->pdo, $this->encryption, $this->booking->id));
    }
}
