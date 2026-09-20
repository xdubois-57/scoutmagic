<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Core\Security\EncryptionService;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Booking\ChangeRequestOrigin;
use Modules\Rental\Booking\ChangeRequestStatus;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The bulk pending loader « À traiter » is built on (§22.5).
 *
 * It exists for one reason: the overview asks whether each of an asset's
 * bookings carries something pending, and an asset with thirty bookings
 * would otherwise ask thirty times — on a page that already reads every
 * booking it shows in a single statement.
 */
class RentalChangeRequestRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalChangeRequestRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $this->repository = new RentalChangeRequestRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    private function create(int $bookingId, ChangeRequestOrigin $origin = ChangeRequestOrigin::RENTER): int
    {
        return $this->repository->create(
            $bookingId,
            $origin,
            ChangeRequestKind::DATES,
            '2027-07-18',
            '2027-07-21',
            null,
            null,
            null,
            'Un mot du locataire.'
        );
    }

    public function testItGroupsEveryPendingRequestByItsBooking(): void
    {
        $this->create(1);
        $this->create(1, ChangeRequestOrigin::MANAGER);
        $this->create(2);

        $byBooking = $this->repository->findPendingForBookings([1, 2]);

        $this->assertSame([1, 2], array_keys($byBooking));
        $this->assertCount(2, $byBooking[1]);
        $this->assertCount(1, $byBooking[2]);
    }

    /**
     * Absent rather than present-and-empty: callers read it with `?? []`,
     * and the two mean the same thing to them.
     */
    public function testABookingWithNothingPendingIsSimplyAbsent(): void
    {
        $this->create(1);

        $this->assertArrayNotHasKey(2, $this->repository->findPendingForBookings([1, 2]));
    }

    public function testADecidedRequestIsNotPending(): void
    {
        $id = $this->create(1);
        $this->repository->decide($id, ChangeRequestStatus::ACCEPTED, null);

        $this->assertSame([], $this->repository->findPendingForBookings([1]));
    }

    public function testABookingNobodyAskedAboutIsNeverReturned(): void
    {
        $this->create(1);
        $this->create(2);

        $this->assertSame([1], array_keys($this->repository->findPendingForBookings([1])));
    }

    /**
     * An asset with no bookings at all reaches this with an empty list, and
     * must not produce `IN ()` — which is a syntax error, not an empty
     * result.
     */
    public function testNoBookingsAtAllAsksNothing(): void
    {
        $this->assertSame([], $this->repository->findPendingForBookings([]));
    }

    public function testTheSameBookingNamedTwiceIsAskedAboutOnce(): void
    {
        $this->create(1);

        $byBooking = $this->repository->findPendingForBookings([1, 1, 1]);

        $this->assertCount(1, $byBooking[1]);
    }

    /**
     * The decrypted message comes back like it does on the single-booking
     * read: `hydrate()` is shared, and a bulk read that returned ciphertext
     * would be a different object with the same name.
     */
    public function testTheRequestsComeBackHydratedLikeTheSingleBookingRead(): void
    {
        $this->create(1);

        $bulk = $this->repository->findPendingForBookings([1])[1][0];
        $single = $this->repository->findPendingForBooking(1)[0];

        $this->assertSame($single->id, $bulk->id);
        $this->assertSame('Un mot du locataire.', $bulk->message);
        $this->assertSame(ChangeRequestOrigin::RENTER, $bulk->origin);
    }
}
