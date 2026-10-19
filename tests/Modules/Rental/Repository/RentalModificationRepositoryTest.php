<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Booking\ModificationStatus;
use Modules\Rental\Booking\ModificationTerms;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * A booking's modifications (#927, chantier §2.3): one project in progress
 * at most, every transition a compare-and-set, and the project's new dates
 * offered to the availability as an occupation under the booking's
 * reference.
 */
#[Group('database')]
final class RentalModificationRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalModificationRepository $repository;
    private \DateTimeImmutable $now;
    private RentalBookingRepository $bookings;
    private int $assetId;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2027-02-01 10:00:00');
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RentalModificationRepository($this->pdo);
        $this->bookings = new RentalBookingRepository($this->pdo, $encryption);
        $this->pdo->exec(
            "INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')"
        );
        $this->assetId = (int) $this->pdo->lastInsertId();
    }

    private function booking(string $reference = 'LOC-A2B3C4'): RentalBooking
    {
        $created = $this->bookings->create(
            $this->assetId,
            $reference,
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

        return $this->bookings->findById($created['id']) ?? self::fail('no booking');
    }

    private static function terms(string $arrival, string $departure, int $persons = 20): ModificationTerms
    {
        return new ModificationTerms(
            $arrival,
            $departure,
            1,
            $persons,
            new PriceQuote([], 48000, 4, $persons, 1, BillingUnit::PER_NIGHT)
        );
    }

    public function testADraftKeepsWhatTheBookingSaidAndHoldsItsNewDates(): void
    {
        $booking = $this->booking();
        $id = $this->repository->createDraft($booking, self::terms('2027-07-10', '2027-07-14'), 7, $this->now);

        $draft = $this->repository->findActiveFor($booking->id);
        $this->assertNotNull($draft);
        $this->assertSame($id, $draft->id);
        $this->assertSame(ModificationStatus::DRAFT, $draft->status);
        $this->assertSame('2027-07-01', $draft->previousArrivalDate);
        $this->assertSame('2027-07-10', $draft->arrivalDate);
        $this->assertSame(48000, $draft->totalCents);

        $occupancies = $this->repository->findOccupancies(
            $this->assetId,
            new \DateTimeImmutable('2027-07-01'),
            new \DateTimeImmutable('2027-07-31'),
            new \DateTimeImmutable()
        );
        $this->assertCount(1, $occupancies);
        $this->assertSame([], $this->repository->findOccupancies(
            $this->assetId,
            new \DateTimeImmutable('2027-08-01'),
            new \DateTimeImmutable('2027-08-31'),
            new \DateTimeImmutable()
        ));
    }

    /** A change of head count alone asks the asset for nothing more. */
    public function testAProjectOnTheSameDatesOccupiesNothingOfItsOwn(): void
    {
        $booking = $this->booking();
        $this->repository->createDraft($booking, self::terms('2027-07-01', '2027-07-04', 30), null, $this->now);

        $this->assertSame([], $this->repository->findOccupancies(
            $this->assetId,
            new \DateTimeImmutable('2027-07-01'),
            new \DateTimeImmutable('2027-07-31'),
            new \DateTimeImmutable()
        ));
    }

    public function testTheDatabaseRefusesASecondProjectInProgress(): void
    {
        $booking = $this->booking();
        $this->repository->createDraft($booking, self::terms('2027-07-10', '2027-07-14'), null, $this->now);

        $this->expectException(\PDOException::class);
        $this->repository->createDraft($booking, self::terms('2027-07-20', '2027-07-24'), null, $this->now);
    }

    public function testAClosedProjectFreesTheWayForTheNext(): void
    {
        $booking = $this->booking();
        $first = $this->repository->createDraft($booking, self::terms('2027-07-10', '2027-07-14'), null, $this->now);
        $this->assertTrue($this->repository->markCancelled($first, 3, $this->now));
        $this->assertFalse($this->repository->markCancelled($first, 3, $this->now));

        $second = $this->repository->createDraft($booking, self::terms('2027-07-20', '2027-07-24'), null, $this->now);
        $this->repository->recordDirect($booking, self::terms('2027-07-02', '2027-07-04'), null, $this->now);

        $this->assertSame($second, $this->repository->findActiveFor($booking->id)?->id);
        $this->assertCount(3, $this->repository->findForBooking($booking->id));
        $cancelled = $this->repository->findById($first);
        $this->assertSame(ModificationStatus::CANCELLED, $cancelled?->status);
        $this->assertSame(3, $cancelled->closedByMemberId);
    }

    /**
     * Sent, the terms are frozen; applied once, however many times the
     * countersignature is pressed; and a draft is never applied.
     */
    public function testEveryTransitionIsACompareAndSet(): void
    {
        $booking = $this->booking();
        $id = $this->repository->createDraft($booking, self::terms('2027-07-10', '2027-07-14'), null, $this->now);

        $this->assertFalse($this->repository->markApplied($id, null, $this->now));
        $this->assertTrue($this->repository->updateDraft($id, self::terms('2027-07-11', '2027-07-14'), $this->now));
        // Saved again unchanged: still in preparation, still true.
        $this->assertTrue($this->repository->updateDraft($id, self::terms('2027-07-11', '2027-07-14'), $this->now));

        $this->assertTrue($this->repository->markSent($id, 42, 5, $this->now));
        $this->assertFalse($this->repository->markSent($id, 43, 5, $this->now));
        $this->assertFalse($this->repository->updateDraft($id, self::terms('2027-07-12', '2027-07-14'), $this->now));
        $sent = $this->repository->findById($id);
        $this->assertSame(42, $sent?->contractDocumentId);
        $this->assertSame('2027-07-11', $sent->arrivalDate);

        $this->assertTrue($this->repository->markApplied($id, 5, $this->now));
        $this->assertFalse($this->repository->markApplied($id, 5, $this->now));
        $this->assertFalse($this->repository->markCancelled($id, 5, $this->now));
        $this->assertNull($this->repository->findActiveFor($booking->id));
        $this->assertSame(ModificationStatus::APPLIED, $this->repository->findById($id)?->status);
    }

    public function testTheBookingsEndCancelsItsProjectAndItsDocuments(): void
    {
        $booking = $this->booking();
        $id = $this->repository->createDraft($booking, self::terms('2027-07-10', '2027-07-14'), null, $this->now);
        $this->pdo->prepare(
            "INSERT INTO rental_documents (booking_id, file_id, document_type, version, modification_id, created_at)
             VALUES (?, 1, 'contract', 2, ?, ?)"
        )->execute([$booking->id, $id, '2027-02-01 10:00:00']);

        $cancelled = $this->repository->cancelActiveFor(
            $booking->id,
            null,
            new \DateTimeImmutable('2027-02-02 10:00:00')
        );

        $this->assertSame($id, $cancelled?->id);
        $this->assertNull($this->repository->cancelActiveFor($booking->id, null, $this->now));
        $this->assertSame(
            '2027-02-02 10:00:00',
            $this->pdo->query('SELECT superseded_at FROM rental_documents')->fetchColumn()
        );
    }

    public function testTheProjectsOfSeveralBookingsComeInOneQuery(): void
    {
        $one = $this->booking('LOC-AAAAAA');
        $two = $this->booking('LOC-BBBBBB');
        $this->booking('LOC-CCCCCC');
        $this->repository->createDraft($one, self::terms('2027-07-10', '2027-07-14'), null, $this->now);
        $this->repository->createDraft($two, self::terms('2027-08-10', '2027-08-14'), null, $this->now);

        $active = $this->repository->findActiveForBookings([$one->id, $two->id, 999]);

        $this->assertSame([$one->id, $two->id], array_keys($active));
        $this->assertSame([], $this->repository->findActiveForBookings([]));
    }
}
