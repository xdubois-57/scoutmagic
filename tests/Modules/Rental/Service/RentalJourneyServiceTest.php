<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Security\EncryptionService;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Service\RentalJourneyService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The checklist every reader shares (#708, IT-15), and the renter's
 * sentence read off it.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class RentalJourneyServiceTest extends TestCase
{
    private RentalBookingRepository $bookings;
    private RentalAsset $asset;
    private RentalJourneyService $service;
    private RentalChangeRequestRepository $changeRequests;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $assets = new RentalAssetRepository($pdo, $encryption);
        $assetId = $assets->create('Local', 'Local Saint-Georges', 'local-saint-georges', 60, 1, null, null, null, true);
        $asset = $assets->findById($assetId);
        $this->assertNotNull($asset);
        $this->asset = $asset;
        $this->bookings = new RentalBookingRepository($pdo, $encryption);
        $this->changeRequests = new RentalChangeRequestRepository($pdo, $encryption);
        $this->service = new RentalJourneyService($this->bookings, changeRequests: $this->changeRequests);
    }

    private function booking(): RentalBooking
    {
        $created = $this->bookings->create(
            $this->asset->id,
            'LOC-2027-0001',
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
            new PriceQuote(
                lines: [new PriceLine('3 nuits', 3, 12000, 36000, PriceLine::RULE_BASE)],
                totalCents: 36000,
                nights: 3,
                persons: 20,
                quantity: 3,
                billingUnit: BillingUnit::PER_NIGHT
            ),
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-01 10:00:00')
        );
        $booking = $this->bookings->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    public function testANewRequestTellsTheRenterTheyHaveNothingToDo(): void
    {
        $step = $this->service->renterNextStep($this->booking(), $this->asset, new \DateTimeImmutable('2027-01-02'));

        $this->assertStringStartsWith("Rien à faire de votre côté pour l'instant : nous étudions votre demande", $step->sentence);
    }

    /**
     * The decision e-mail is sent right after the transition, by a caller
     * that may still hold the booking as it was: the sentence reads the
     * booking as it is stored.
     */
    public function testTheSentenceReadsTheBookingAsStoredNotAsHandedIn(): void
    {
        $stale = $this->booking();
        $this->bookings->setStatus($stale->id, BookingStatus::INFO_REQUESTED, new \DateTimeImmutable('2027-01-02'));

        $step = $this->service->renterNextStep($stale, $this->asset, new \DateTimeImmutable('2027-01-02'));

        $this->assertStringStartsWith('À vous : répondez à notre question', $step->sentence);
        $this->assertTrue($step->onTrackingPage);
    }

    /**
     * A proposal of the unit is a change request, not a status: the booking
     * stays « reçue » while the renter owes an answer. Their sentence says
     * so, as the manager's « Prochaine action » does — and a change the
     * renter asked for themselves is not one they have to answer.
     */
    public function testAProposalWaitingOnTheRenterIsTheirs(): void
    {
        $booking = $this->booking();
        $now = new \DateTimeImmutable('2027-01-02');
        $this->changeRequests->create(
            $booking->id,
            ChangeRequestOrigin::RENTER,
            ChangeRequestKind::DATES,
            '2027-07-08',
            '2027-07-11',
            null,
            null,
            null,
            null
        );

        $theirs = $this->service->renterNextStep($booking, $this->asset, $now);
        $this->assertStringStartsWith("Rien à faire de votre côté pour l'instant", $theirs->sentence);

        $this->changeRequests->create(
            $booking->id,
            ChangeRequestOrigin::MANAGER,
            ChangeRequestKind::PERSONS,
            null,
            null,
            null,
            25,
            null,
            null
        );

        $step = $this->service->renterNextStep($booking, $this->asset, $now);
        $this->assertStringStartsWith('À vous : acceptez ou refusez notre proposition', $step->sentence);
        $this->assertTrue($step->onTrackingPage);
    }

    /** Without Finances, the payment lines are « sans objet » rather than owed. */
    public function testWithoutPaymentsNoPaymentIsOwed(): void
    {
        $payment = $this->service->payment($this->booking(), $this->asset);

        $this->assertFalse($payment['enabled']);
    }
}
