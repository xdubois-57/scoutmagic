<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Repository\RentalMilestoneMarkRepository;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The steps ticked by hand (issue #462, D5): what is stored is exactly what
 * is ticked — a second tick is not a new fact, an untick leaves no row.
 */
class RentalMilestoneMarkRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalMilestoneMarkRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $this->repository = new RentalMilestoneMarkRepository($this->pdo);
    }

    public function testABookingWithNothingTickedHasNoMarks(): void
    {
        $this->assertSame([], $this->repository->findForBooking(1));
    }

    public function testATickIsReadBackWithWhoAndWhen(): void
    {
        $at = new \DateTimeImmutable('2027-07-17 09:30:00');

        $this->assertTrue($this->repository->mark(1, 'arrival_inventory', 42, $at));

        $this->assertEquals(
            ['arrival_inventory' => ['marked_at' => $at, 'marked_by_member_id' => 42]],
            $this->repository->findForBooking(1)
        );
    }

    /**
     * The first tick is the one that counts: pressing twice keeps who did
     * it first and when, and reports that nothing changed.
     */
    public function testASecondTickChangesNothing(): void
    {
        $first = new \DateTimeImmutable('2027-07-17 09:30:00');
        $this->repository->mark(1, 'arrival_inventory', 42, $first);

        $this->assertFalse($this->repository->mark(1, 'arrival_inventory', 7, new \DateTimeImmutable('2027-07-18 10:00:00')));
        $this->assertEquals($first, $this->repository->findForBooking(1)['arrival_inventory']['marked_at']);
        $this->assertSame(42, $this->repository->findForBooking(1)['arrival_inventory']['marked_by_member_id']);
    }

    public function testAnUntickLeavesNoRowBehind(): void
    {
        $this->repository->mark(1, 'arrival_inventory', null, new \DateTimeImmutable('2027-07-17 09:30:00'));

        $this->assertTrue($this->repository->unmark(1, 'arrival_inventory'));
        $this->assertSame([], $this->repository->findForBooking(1));
        $this->assertFalse($this->repository->unmark(1, 'arrival_inventory'));
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM rental_booking_milestone_marks')->fetchColumn());
    }

    public function testMarksBelongToTheirBooking(): void
    {
        $at = new \DateTimeImmutable('2027-07-17 09:30:00');
        $this->repository->mark(1, 'arrival_inventory', null, $at);
        $this->repository->mark(2, 'departure_inventory', null, $at);

        $this->assertSame(['arrival_inventory'], array_keys($this->repository->findForBooking(1)));
        $this->assertSame(['departure_inventory'], array_keys($this->repository->findForBooking(2)));
    }
}
