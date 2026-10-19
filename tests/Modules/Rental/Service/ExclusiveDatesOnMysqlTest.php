<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;
use Tests\UsesProductionEngine;

/**
 * Exclusive dates on the engine production runs, with **two real
 * connections** (#927, chantier §2.1, §4).
 *
 * SQLite serialises every writer on its own, so `ExclusiveDatesTest` shows
 * the rule and never the lock. Here two sessions of MariaDB/MySQL each
 * build the whole service stack, as two PHP requests would, and the second
 * one acts while the first is inside its gesture: it must wait on the
 * asset's row, and once the first has committed, find the dates taken.
 *
 * The case that mattered most is the first one: an asset with **no booking
 * at all**. The lock used to be a `FOR UPDATE` over the asset's booking
 * rows, which locks nothing there — and the public request took no lock
 * whatsoever — so two visitors sending the same week at once both landed.
 */
#[Group('database')]
final class ExclusiveDatesOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->assetId = (new RentalAssetRepository($this->pdo, self::encryption()))
            ->create('Local', 'Local Saint-Georges', 'local', 60, 1, null, null, null, true);
        $this->stack($this->pdo)['pricing']->saveAssetPricing($this->assetId, 'per_night', 12000, null, null);
    }

    /** The premise: the locking branch runs here. On SQLite nothing below would test anything. */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Two visitors, the same week, a hall nobody has booked yet. While the
     * first request is between its check and its write, the second session
     * waits on the asset — and gives up at its one-second timeout, since it
     * runs inside the first one's work. Once the first has committed, the
     * second is told the dates went. One booking, not two.
     */
    public function testTwoSimultaneousRequestsForAFreshAssetCannotBothLand(): void
    {
        $other = $this->secondConnection();
        $mine = $this->stack($this->pdo);
        $theirs = $this->stack($other);
        $now = new \DateTimeImmutable('2027-02-01 10:00:00');

        $whileHeld = null;
        $theirsChecked = false;
        $this->request($mine, $now, function () use ($theirs, $now, &$whileHeld, &$theirsChecked): void {
            try {
                $this->request($theirs, $now, function () use (&$theirsChecked): void {
                    $theirsChecked = true;
                });
                $whileHeld = 'landed';
            } catch (RentalException $e) {
                $whileHeld = $e->getPrevious() instanceof \PDOException ? 'waited' : 'refused: ' . $e->getMessage();
            }
        });

        $this->assertSame('waited', $whileHeld, 'the second request did not wait on the asset\'s row');
        // Waiting is not enough: it must wait BEFORE its own check. A lock
        // on the booking rows let it read the hall free and stall only at
        // its insert, on a gap lock — two checks on one snapshot, and a
        // deadlock between the two inserts.
        $this->assertFalse($theirsChecked, 'the second request checked availability while the first held the asset');

        try {
            $this->request($theirs, $now->modify('+1 second'));
            $this->fail('the second request landed on dates the first one holds');
        } catch (RentalException $e) {
            $this->assertStringContainsString('disponible', $e->getMessage());
        }

        $this->assertSame(1, $this->bookingCount());
    }

    /**
     * Two managers confirming two requests for one week, both holds lapsed:
     * the second confirmation waits for the first, then finds the week
     * taken. Never two confirmed bookings.
     */
    public function testTwoSimultaneousConfirmationsCannotBothLand(): void
    {
        $other = $this->secondConnection();
        $mine = $this->stack($this->pdo);
        $theirs = $this->stack($other);
        $first = $this->request($mine, new \DateTimeImmutable('2027-02-01 10:00:00'), holdDays: 5);
        $second = $this->request($theirs, new \DateTimeImmutable('2027-02-07 10:00:00'), holdDays: 5);
        $now = new \DateTimeImmutable('2027-02-20 10:00:00');
        $assets = new RentalAssetRepository($other, self::encryption());

        $whileHeld = null;
        $mine['operations']->atomicallyOnAsset($this->assetId, function () use (
            $mine,
            $theirs,
            $first,
            $second,
            $assets,
            $now,
            &$whileHeld
        ): void {
            $mine['operations']->confirm(
                $mine['bookings']->findById($first->id) ?? self::fail('no booking'),
                (new RentalAssetRepository($this->pdo, self::encryption()))->findById($this->assetId)
                    ?? self::fail('no asset'),
                1,
                $now
            );
            try {
                $theirs['operations']->confirm(
                    $theirs['bookings']->findById($second->id) ?? self::fail('no booking'),
                    $assets->findById($this->assetId) ?? self::fail('no asset'),
                    2,
                    $now
                );
                $whileHeld = 'landed';
            } catch (\PDOException) {
                $whileHeld = 'waited';
            } catch (RentalException $e) {
                $whileHeld = 'refused: ' . $e->getMessage();
            }
        });

        $this->assertSame('waited', $whileHeld, 'the second confirmation did not wait on the asset\'s row');

        try {
            $theirs['operations']->confirm(
                $theirs['bookings']->findById($second->id) ?? self::fail('no booking'),
                $assets->findById($this->assetId) ?? self::fail('no asset'),
                2,
                $now
            );
            $this->fail('two bookings of one week were confirmed');
        } catch (RentalException $e) {
            $this->assertStringContainsString('plus disponibles', $e->getMessage());
        }

        $statuses = $this->pdo->query('SELECT status FROM rental_bookings ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertSame([BookingStatus::CONFIRMED->value, BookingStatus::RECEIVED->value], $statuses);
    }

    /**
     * The firm hold is a correlated subquery in every booking read, and in
     * the sweep's WHERE beside a LIMIT — read here by the engine that runs
     * it: a copy holds, a refused or superseded one does not, and the sweep
     * never returns a booking a copy holds.
     */
    public function testTheFirmHoldIsDerivedFromTheCopiesOnThisEngine(): void
    {
        $stack = $this->stack($this->pdo);
        $booking = $this->request($stack, new \DateTimeImmutable('2027-02-01 10:00:00'), holdDays: 5);
        $insert = $this->pdo->prepare(
            "INSERT INTO rental_documents (booking_id, file_id, document_type, created_at, refused_at, superseded_at)
             VALUES (?, 1, 'signed_copy', ?, ?, ?)"
        );
        $insert->execute([$booking->id, '2027-02-04 09:00:00', '2027-02-04 12:00:00', null]);
        $insert->execute([$booking->id, '2027-02-03 09:00:00', null, '2027-02-03 12:00:00']);

        $lapsed = new \DateTimeImmutable('2027-02-10 10:00:00');
        $this->assertNull($this->reload($stack, $booking)->signedCopyReceivedAt, 'refused or superseded holds nothing');
        $this->assertCount(1, $stack['bookings']->findWithLapsedHold($lapsed));

        $insert->execute([$booking->id, '2027-02-05 09:00:00', null, null]);

        $held = $this->reload($stack, $booking);
        $this->assertSame('2027-02-05 09:00:00', $held->signedCopyReceivedAt?->format('Y-m-d H:i:s'));
        $this->assertTrue($held->occupiesTheAsset($lapsed));
        $this->assertSame([], $stack['bookings']->findWithLapsedHold($lapsed));
        $this->assertCount(1, $stack['bookings']->findOccupyingBetween($this->assetId, '2027-07-01', '2027-07-31'));
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    /**
     * The services one request of the site builds, on one connection.
     *
     * @return array{bookings: RentalBookingRepository, booking: RentalBookingService,
     *     availability: RentalAvailabilityService, pricing: RentalPricingService,
     *     operations: RentalOperationsService, assets: RentalAssetRepository}
     */
    private function stack(\PDO $pdo): array
    {
        $journal = new JournalService(new JournalRepository($pdo));
        $bookings = new RentalBookingRepository($pdo, self::encryption());
        $booking = new RentalBookingService($bookings, $journal);
        $pricing = new RentalPricingService(new RentalPricingRepository($pdo), new RentalPricingEngine(), $journal);
        $availability = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($pdo),
            [$booking, new RentalBlockRepository($pdo)]
        );

        return [
            'bookings' => $bookings,
            'booking' => $booking,
            'availability' => $availability,
            'pricing' => $pricing,
            'operations' => new RentalOperationsService(
                $bookings,
                RentalTestHelper::bookingAudit($pdo, self::encryption()),
                new RentalBookingCommentRepository($pdo, self::encryption()),
                new RentalModificationRepository($pdo),
                $availability,
                $pricing,
                $journal
            ),
            'assets' => new RentalAssetRepository($pdo, self::encryption()),
        ];
    }

    /**
     * A public request for 1–4 July, as the form sends it. `$alsoUnderLock`
     * runs inside the lock, after the check and before the write — the
     * moment a second request would slip in.
     *
     * @param array{bookings: RentalBookingRepository, booking: RentalBookingService,
     *     availability: RentalAvailabilityService, pricing: RentalPricingService,
     *     operations: RentalOperationsService, assets: RentalAssetRepository} $stack
     * @throws RentalException
     */
    private function request(
        array $stack,
        \DateTimeImmutable $now,
        ?\Closure $alsoUnderLock = null,
        int $holdDays = 30
    ): RentalBooking {
        $asset = $stack['assets']->findById($this->assetId) ?? self::fail('no asset');
        $check = function () use ($stack, $asset, $now, $alsoUnderLock): array {
            $errors = $stack['availability']->validateRange(
                $asset,
                BillingUnit::PER_NIGHT,
                DateInput::requireFromStorage('2027-07-01', 'arrival'),
                DateInput::requireFromStorage('2027-07-04', 'departure'),
                1,
                $now->setTime(0, 0),
                now: $now
            );
            if ($alsoUnderLock !== null) {
                $alsoUnderLock();
            }

            return $errors;
        };

        return $stack['booking']->createFromPublicRequest(
            $this->assetId,
            '2027-07-01',
            '2027-07-04',
            1,
            10,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => '+32 495 11 22 33',
                'organisation' => null,
                'purpose' => 'Week-end',
                'comment' => null,
            ],
            null,
            ['conditions_version' => 'v1', 'conditions_text' => 'x', 'privacy_version' => 'v1', 'privacy_text' => 'y'],
            $now,
            $holdDays,
            $check
        )['booking'];
    }

    /**
     * @param array{bookings: RentalBookingRepository} $stack
     */
    private function reload(array $stack, RentalBooking $booking): RentalBooking
    {
        return $stack['bookings']->findById($booking->id) ?? self::fail('no booking');
    }

    private function bookingCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rental_bookings')->fetchColumn();
    }

    private function secondConnection(): \PDO
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $other;
    }
}
