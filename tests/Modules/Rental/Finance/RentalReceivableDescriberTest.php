<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Finance;

use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Modules\Finance\Api\ReceivableViewer;
use Modules\Rental\Finance\RentalReceivableDescriber;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\RentalAuthorizationService;
use PHPUnit\Framework\TestCase;

/**
 * A booking's receivables lead to its « Finances » page — for the people
 * who manage that asset, and nobody else (issue #836).
 */
class RentalReceivableDescriberTest extends TestCase
{
    private function describer(bool $mayManage, bool $bookingExists = true): RentalReceivableDescriber
    {
        $bookings = $this->createStub(RentalBookingRepository::class);
        $bookings->method('findById')->willReturn($bookingExists ? $this->booking() : null);
        $assets = $this->createStub(RentalAssetRepository::class);
        $assets->method('findById')->willReturn(new RentalAsset(
            id: 7,
            assetType: 'hall',
            name: 'Le Chalet',
            slug: 'le-chalet',
            capacity: 40,
            quantity: 1,
            arrivalTime: '16:00',
            departureTime: '11:00',
            emergencyPhone: null,
            isArchived: false,
            isPublic: true
        ));
        $authorization = $this->createStub(RentalAuthorizationService::class);
        $authorization->method('canManageAsset')->willReturnCallback(
            static fn(?string $email, int $yearId): bool => $mayManage && $email === 'gestionnaire@example.org' && $yearId === 3
        );
        $years = $this->createStub(ScoutYearResolver::class);
        $years->method('getAuthorizationYear')->willReturn(new EffectiveScoutYear(3, '2026-2027', null));

        return new RentalReceivableDescriber($bookings, $assets, $authorization, $years);
    }

    private function booking(): RentalBooking
    {
        return new RentalBooking(
            id: 42,
            assetId: 7,
            reference: 'LOC-K7Q2M4',
            arrivalDate: '2027-08-14',
            departureDate: '2027-08-17',
            units: 1,
            estimatedPersons: 25,
            renterCategoryId: null,
            renterName: 'Dupont ASBL',
            renterEmail: 'dupont@example.test',
            renterPhone: null,
            renterOrganisation: null,
            purpose: null,
            renterComment: null,
            status: BookingStatus::CONFIRMED,
            receivedAt: new \DateTimeImmutable('2027-05-01 10:00:00'),
            finalAt: null,
            holdUntil: null,
            holdOrigin: null,
            estimatedPrice: null,
            estimatedTotalCents: null,
            agreedPrice: null,
            agreedTotalCents: null,
            conditionsVersion: null,
            conditionsHash: null,
            conditionsAcceptedAt: null,
            privacyVersion: null,
            privacyHash: null,
            privacyAcknowledgedAt: null
        );
    }

    public function testAManagerOfTheAssetIsLedToTheBookingsFinances(): void
    {
        $destination = $this->describer(true)->destinationFor(42, new ReceivableViewer('gestionnaire@example.org', Role::IDENTIFIED));

        $this->assertNotNull($destination);
        $this->assertSame('/mes-locations/le-chalet/reservations/42/finances', $destination->url);
        $this->assertSame('Ouvrir la réservation', $destination->label);
    }

    public function testATreasurerWhoDoesNotManageTheAssetGetsNoLink(): void
    {
        // The receivable is on the finance account they see; the booking
        // page would answer them with a 404.
        $this->assertNull($this->describer(false)->destinationFor(42, new ReceivableViewer('tresorier@example.org', Role::INTENDANT)));
    }

    public function testABookingThatIsGoneHasNoLink(): void
    {
        $this->assertNull($this->describer(true, false)->destinationFor(42, new ReceivableViewer('gestionnaire@example.org', Role::ADMIN)));
    }

    public function testTheGroupStillReadsAsTheBookingsReferenceAndRenter(): void
    {
        $this->assertSame('LOC-K7Q2M4 — Dupont ASBL', $this->describer(true)->describeInstance(42));
    }
}
