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
use Tests\UsesProductionEngine;

/**
 * One project in progress per booking, on the engine production runs
 * (#927, chantier §2.3).
 *
 * The rule is a UNIQUE key on `active_booking_id`, which carries the
 * booking's id while a project is in preparation or sent and NULL once it
 * is closed — so the key has to accept any number of NULLs, and refuse the
 * second live row even when it comes from another connection, as two
 * managers pressing « Enregistrer » together would. And since MySQL and
 * MariaDB count the rows an UPDATE CHANGED, not those it matched, a draft
 * saved again unchanged must still read as saved: `updateDraft()` reads the
 * row back rather than trusting `rowCount()`.
 */
#[Group('database')]
final class RentalModificationRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private RentalModificationRepository $repository;
    private \DateTimeImmutable $now;
    private RentalBooking $booking;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2027-02-01 10:00:00');
        $this->pdo = $this->productionEngine();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->repository = new RentalModificationRepository($this->pdo);
        $bookings = new RentalBookingRepository($this->pdo, $encryption);

        $this->pdo->exec(
            "INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')"
        );
        $created = $bookings->create(
            (int) $this->pdo->lastInsertId(),
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
    }

    private static function terms(string $arrival, string $departure): ModificationTerms
    {
        return new ModificationTerms(
            $arrival,
            $departure,
            1,
            20,
            new PriceQuote([], 48000, 4, 20, 1, BillingUnit::PER_NIGHT)
        );
    }

    public function testASecondConnectionCannotOpenASecondProject(): void
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = new RentalModificationRepository(self::productionEngineConnection($database)->getPdo());

        $this->repository->createDraft($this->booking, self::terms('2027-07-10', '2027-07-14'), null, $this->now);

        try {
            $other->createDraft($this->booking, self::terms('2027-07-20', '2027-07-24'), null, $this->now);
            $this->fail('a second project in progress was written');
        } catch (\PDOException $e) {
            // What RentalModificationService turns into its French sentence.
            $this->assertSame('23000', (string) $e->getCode());
        }
        $this->assertCount(1, $this->repository->findForBooking($this->booking->id));
    }

    /** Closed projects keep NULL in the key, and any number of them coexist. */
    public function testClosedProjectsDoNotCollideOnTheKey(): void
    {
        $now = new \DateTimeImmutable();
        $first = $this->repository->createDraft($this->booking, self::terms('2027-07-10', '2027-07-14'), null, $now);
        $this->assertTrue($this->repository->markCancelled($first, null, $now));
        $this->repository->recordDirect($this->booking, self::terms('2027-07-02', '2027-07-04'), null, $now);
        $second = $this->repository->createDraft($this->booking, self::terms('2027-07-20', '2027-07-24'), null, $now);
        $this->assertTrue($this->repository->markSent($second, 1, null, $now));
        $this->assertTrue($this->repository->markApplied($second, null, $now));
        $third = $this->repository->createDraft($this->booking, self::terms('2027-08-01', '2027-08-04'), null, $now);

        $this->assertCount(4, $this->repository->findForBooking($this->booking->id));
        $this->assertSame($third, $this->repository->findActiveFor($this->booking->id)?->id);
    }

    public function testADraftSavedAgainUnchangedStillReadsAsSaved(): void
    {
        $now = new \DateTimeImmutable('2027-02-01 10:00:00');
        $id = $this->repository->createDraft($this->booking, self::terms('2027-07-10', '2027-07-14'), null, $now);
        $this->assertTrue($this->repository->updateDraft($id, self::terms('2027-07-10', '2027-07-14'), $now));

        $this->assertTrue($this->repository->markSent($id, 1, null, $now));
        $this->assertFalse($this->repository->updateDraft($id, self::terms('2027-07-11', '2027-07-14'), $now));
        $this->assertSame(ModificationStatus::SENT, $this->repository->findById($id)?->status);
        // The transitions decide from rowCount(): applied once, never twice.
        $this->assertTrue($this->repository->markApplied($id, null, $now));
        $this->assertFalse($this->repository->markApplied($id, null, $now));
    }
}
