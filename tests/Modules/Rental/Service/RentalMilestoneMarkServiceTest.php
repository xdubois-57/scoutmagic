<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Security\EncryptionService;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Repository\RentalMilestoneMarkRepository;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalMilestoneMarkService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * « Marquer comme fait » (issue #462, D5): a tick is a real fact, carried
 * by the booking's history like any other action — and only a step the
 * site can never derive is ever ticked at all.
 */
class RentalMilestoneMarkServiceTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalMilestoneMarkService $service;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->service = new RentalMilestoneMarkService(
            new RentalMilestoneMarkRepository($this->pdo),
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption)
        );
    }

    private function booking(): RentalBooking
    {
        return new RentalBooking(
            id: 5,
            assetId: 1,
            reference: 'LOC-2027-0005',
            arrivalDate: '2027-07-17',
            departureDate: '2027-07-20',
            units: 1,
            estimatedPersons: 30,
            renterCategoryId: null,
            renterName: 'Marie Dupont',
            renterEmail: 'marie@example.org',
            renterPhone: null,
            renterOrganisation: null,
            purpose: null,
            renterComment: null,
            status: BookingStatus::CONFIRMED,
            receivedAt: new \DateTimeImmutable('2027-01-04 10:00:00'),
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

    public function testATickIsStoredAndWrittenToTheHistory(): void
    {
        $this->assertTrue($this->service->set(
            $this->booking(),
            'arrival_inventory',
            "État des lieux d'entrée",
            true,
            null,
            new \DateTimeImmutable('2027-07-17 09:30:00')
        ));

        $this->assertArrayHasKey('arrival_inventory', $this->service->marksFor(5));
        $history = RentalTestHelper::bookingHistory($this->pdo, $this->encryption, 5);
        $this->assertCount(1, $history);
        $this->assertSame(BookingAudit::STEP_MARKED, $history[0]->fieldKey);
        $this->assertSame("État des lieux d'entrée", $history[0]->summary);
        $this->assertSame('Fait', $history[0]->toValue);
    }

    public function testAnUntickIsWrittenToTheHistoryToo(): void
    {
        $at = new \DateTimeImmutable('2027-07-17 09:30:00');
        $this->service->set($this->booking(), 'arrival_inventory', "État des lieux d'entrée", true, null, $at);

        $this->assertTrue($this->service->set($this->booking(), 'arrival_inventory', "État des lieux d'entrée", false, null, $at));

        $this->assertSame([], $this->service->marksFor(5));
        $history = RentalTestHelper::bookingHistory($this->pdo, $this->encryption, 5);
        $this->assertCount(2, $history);
        $this->assertSame('À faire', $history[1]->toValue);
    }

    /**
     * Pressing twice is not a second fact, and the history does not say it
     * happened twice.
     */
    public function testATickThatChangesNothingWritesNothing(): void
    {
        $at = new \DateTimeImmutable('2027-07-17 09:30:00');
        $this->service->set($this->booking(), 'arrival_inventory', "État des lieux d'entrée", true, null, $at);

        $this->assertFalse($this->service->set($this->booking(), 'arrival_inventory', "État des lieux d'entrée", true, null, $at));
        $this->assertCount(1, RentalTestHelper::bookingHistory($this->pdo, $this->encryption, 5));
    }

    /**
     * A step the site derives is never ticked by hand, whoever asks: a tick
     * beside the payments would be a second truth.
     */
    public function testADerivedStepIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->service->set($this->booking(), 'deposit_received', 'Acompte reçu', true, null, new \DateTimeImmutable());
    }
}
