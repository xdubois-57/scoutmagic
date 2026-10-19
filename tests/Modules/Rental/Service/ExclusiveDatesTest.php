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
use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Availability\OccupancyProvider;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\HoldOrigin;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Calendar\PublishFrom;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\QuoteEditor;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBlockRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBlockService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalOccupancyGuard;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Exclusive dates (#927, chantier §2.1): whatever occupies an asset stops
 * everybody else — a visitor, a manager confirming, an option, a contract
 * sent — and the one predicate deciding what occupies is read the same way
 * by every surface.
 *
 * Each case is a pair of writers on the same week, in the order they
 * actually happen. The two-connection version of the first one, on the
 * engine production runs, is `ExclusiveDatesOnMysqlTest`.
 */
#[Group('database')]
final class ExclusiveDatesTest extends TestCase
{
    private \PDO $pdo;
    private RentalAssetRepository $assetRepository;
    private RentalBookingRepository $bookingRepository;
    private RentalBookingService $bookingService;
    private RentalBlockService $blockService;
    private RentalAvailabilityService $availability;
    private RentalPricingService $pricing;
    private RentalOperationsService $operations;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->assetRepository = new RentalAssetRepository($this->pdo, $encryption);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $encryption);
        $this->bookingService = new RentalBookingService($this->bookingRepository, $journal);
        $blocks = new RentalBlockRepository($this->pdo);
        $this->blockService = new RentalBlockService($blocks, $journal);
        $this->pricing = new RentalPricingService(new RentalPricingRepository($this->pdo), new RentalPricingEngine(), $journal);
        $this->availability = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            [$this->bookingService, $blocks]
        );
        $this->operations = new RentalOperationsService(
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $encryption),
            new RentalBookingCommentRepository($this->pdo, $encryption),
            new RentalChangeRequestRepository($this->pdo, $encryption),
            $this->availability,
            $this->pricing,
            new QuoteEditor(),
            $journal
        );

        $this->assetId = $this->assetRepository->create('Local', 'Local Saint-Georges', 'local', 60, 1, null, null, null, true);
        $this->pricing->saveAssetPricing($this->assetId, 'per_night', 12000, null, null);
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function asset(?int $id = null): RentalAsset
    {
        return $this->assetRepository->findById($id ?? $this->assetId) ?? self::fail('no asset');
    }

    private static function at(string $moment): \DateTimeImmutable
    {
        return new \DateTimeImmutable($moment);
    }

    /**
     * A public request, exactly as the form sends it: checked once more
     * under the asset's lock by the write itself.
     *
     * @throws RentalException
     */
    private function request(
        string $arrival,
        string $departure,
        string $now,
        int $units = 1,
        ?int $assetId = null,
        int $holdDays = 30
    ): RentalBooking {
        $asset = $this->asset($assetId);
        $at = self::at($now);
        $validate = fn(): array => $this->availability->validateRange(
            $asset,
            BillingUnit::PER_NIGHT,
            DateInput::requireFromStorage($arrival, 'arrival'),
            DateInput::requireFromStorage($departure, 'departure'),
            $units,
            $at->setTime(0, 0),
            now: $at
        );

        return $this->bookingService->createFromPublicRequest(
            $asset->id,
            $arrival,
            $departure,
            $units,
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
            $at,
            $holdDays,
            $validate
        )['booking'];
    }

    private function reload(RentalBooking $booking): RentalBooking
    {
        return $this->bookingRepository->findById($booking->id) ?? self::fail('no booking');
    }

    // ── Two requests ────────────────────────────────────────────────────

    /** Provisional against provisional: the second visitor is told the dates went. */
    public function testASecondRequestForAHeldWeekIsRefused(): void
    {
        $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00');

        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/disponible/');
        $this->request('2027-07-03', '2027-07-06', '2027-02-01 10:05');
    }

    /** Once the first hold lapsed, the dates are anybody's, without any task. */
    public function testALapsedHoldLetsTheNextRequestIn(): void
    {
        $first = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);

        $second = $this->request('2027-07-01', '2027-07-04', '2027-02-06 10:01');

        $this->assertFalse($this->reload($first)->occupiesTheAsset(self::at('2027-02-06 10:01')));
        $this->assertTrue($this->reload($second)->occupiesTheAsset(self::at('2027-02-06 10:01')));
    }

    // ── Confirmations ───────────────────────────────────────────────────

    /**
     * No manager passes over a competing request still holding its dates.
     * The first request's hold lapsed while a second one took the week:
     * confirming the first is refused, the second goes through.
     */
    public function testAConfirmationIsRefusedOverAnotherRequestsRunningHold(): void
    {
        $first = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);
        $second = $this->request('2027-07-02', '2027-07-05', '2027-02-10 10:00');
        $now = self::at('2027-02-11 10:00');

        try {
            $this->operations->confirm($this->reload($first), $this->asset(), 1, $now);
            $this->fail('a confirmation went through over another request\'s running hold');
        } catch (RentalException $e) {
            $this->assertStringContainsString('plus disponibles', $e->getMessage());
        }
        $this->assertSame(BookingStatus::RECEIVED, $this->reload($first)->status);

        $this->operations->confirm($this->reload($second), $this->asset(), 2, $now);
        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($second)->status);
    }

    /** Two managers confirming two requests for one week: the second is told. */
    public function testTwoConfirmationsOfOneWeekCannotBothLand(): void
    {
        $first = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);
        $second = $this->request('2027-07-01', '2027-07-04', '2027-02-07 10:00', holdDays: 5);
        // Both holds lapsed: neither stands in the other's way any more.
        $now = self::at('2027-02-20 10:00');

        $this->operations->confirm($this->reload($second), $this->asset(), 1, $now);

        $this->expectException(RentalException::class);
        $this->operations->confirm($this->reload($first), $this->asset(), 2, $now);
    }

    // ── An option, a contract ───────────────────────────────────────────

    /** An option takes the dates again: never over a request that holds them. */
    public function testAnOptionIsRefusedOverARequestThatHoldsTheDates(): void
    {
        $first = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);
        $this->request('2027-07-02', '2027-07-05', '2027-02-10 10:00');

        try {
            $this->operations->placeOption(
                $this->reload($first),
                $this->asset(),
                self::at('2027-03-01 18:00'),
                1,
                self::at('2027-02-11 10:00')
            );
            $this->fail('an option was placed over another request\'s hold');
        } catch (RentalException $e) {
            $this->assertStringContainsString('occupées', $e->getMessage());
        }
        $this->assertSame(HoldOrigin::AUTOMATIC, $this->reload($first)->holdOrigin, 'nothing was written');
    }

    public function testAnOptionOnFreeDatesIsPlaced(): void
    {
        $booking = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);

        $this->operations->placeOption($this->reload($booking), $this->asset(), self::at('2027-03-01 18:00'), 1, self::at('2027-02-11 10:00'));

        $this->assertSame(HoldOrigin::MANAGER, $this->reload($booking)->holdOrigin);
    }

    /**
     * A contract that would lengthen a lapsed hold over somebody else's
     * dates is refused before it leaves; sent anyway in a race, the status
     * moves and the dates are not taken.
     */
    public function testAContractCannotTakeBackDatesSomebodyElseHolds(): void
    {
        $first = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);
        $this->request('2027-07-02', '2027-07-05', '2027-02-10 10:00');
        $now = self::at('2027-02-11 10:00');

        try {
            $this->operations->assertContractMayHold($this->reload($first), $this->asset(), $now);
            $this->fail('a contract was allowed to take back dates somebody else holds');
        } catch (RentalException $e) {
            $this->assertSame(RentalOperationsService::DATES_TAKEN_FOR_CONTRACT, $e->getMessage());
        }

        $this->assertFalse($this->operations->contractSent($this->reload($first), $this->asset(), 1, $now));
        $sent = $this->reload($first);
        $this->assertSame(BookingStatus::CONTRACT_SENT, $sent->status);
        $this->assertFalse($sent->occupiesTheAsset($now));
    }

    /** Its own hold still running, a contract lengthens it freely. */
    public function testAContractWithinItsOwnHoldLengthensIt(): void
    {
        $booking = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);
        $now = self::at('2027-02-03 10:00');

        $this->operations->assertContractMayHold($this->reload($booking), $this->asset(), $now);
        $this->assertTrue($this->operations->contractSent($this->reload($booking), $this->asset(), 1, $now));
        $this->assertSame('2027-02-18 10:00', $this->reload($booking)->holdUntil?->format('Y-m-d H:i'));
    }

    // ── A manual block, a stock, a buffer ───────────────────────────────

    /** A block is an occupation: no request, option or confirmation lands on it. */
    public function testAManualBlockStopsEveryBookingWriter(): void
    {
        $booking = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);
        $this->blockService->create($this->assetId, '2027-07-03', '2027-07-03', 'Chantier', 1);
        $now = self::at('2027-02-11 10:00');

        foreach ([
            'request' => fn() => $this->request('2027-07-02', '2027-07-05', '2027-02-11 10:00'),
            'option' => fn() => $this->operations->placeOption($this->reload($booking), $this->asset(), self::at('2027-03-01'), 1, $now),
            'confirmation' => fn() => $this->operations->confirm($this->reload($booking), $this->asset(), 1, $now),
        ] as $writer => $write) {
            try {
                $write();
                $this->fail($writer . ' landed on a manual block');
            } catch (RentalException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** The other way round, a block may lie over a stay (specifications.md §22.8). */
    public function testABlockMayStillBeDrawnOverABooking(): void
    {
        $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00');

        $this->blockService->create($this->assetId, '2027-07-02', '2027-07-02', 'Concierge absent', 1);

        $this->assertCount(1, $this->blockService->between($this->assetId, self::at('2027-07-01'), self::at('2027-07-04')));
    }

    /** Ten tents, eight held: two more fit, three do not. */
    public function testAStockIsCountedAcrossProvisionalHolds(): void
    {
        $tents = $this->assetRepository->create('Tente', 'Tentes', 'tentes', null, 10, null, null, null, true);
        $this->pricing->saveAssetPricing($tents, 'per_night', 1500, null, null);
        $this->request('2027-08-01', '2027-08-05', '2027-02-01 10:00', 8, $tents);

        try {
            $this->request('2027-08-02', '2027-08-04', '2027-02-01 10:05', 3, $tents);
            $this->fail('three tents were let out of two');
        } catch (RentalException) {
            $this->addToAssertionCount(1);
        }

        $this->request('2027-08-02', '2027-08-04', '2027-02-01 10:06', 2, $tents);
        $this->assertTrue($this->availability->isRangeFree(
            $this->asset($tents),
            BillingUnit::PER_NIGHT,
            self::at('2027-08-06'),
            self::at('2027-08-08'),
            10,
            self::at('2027-02-01 10:10')
        ));
    }

    /** Two nights of buffer after a provisional stay: the next arrival waits for them. */
    public function testTheBufferFollowsAProvisionalStayToo(): void
    {
        $this->availability->saveConstraints($this->assetId, 0, 0, 0, 0, [], null, 2);
        $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00');

        try {
            $this->request('2027-07-05', '2027-07-07', '2027-02-01 10:05');
            $this->fail('a request landed inside the buffer of a held stay');
        } catch (RentalException) {
            $this->addToAssertionCount(1);
        }

        $this->request('2027-07-06', '2027-07-08', '2027-02-01 10:06');
    }

    // ── Expiry ──────────────────────────────────────────────────────────

    /**
     * The sweep releases a provisional hold that lapsed, never a firm one
     * — and an option's lapse still ends its booking.
     */
    public function testTheExpirySweepTouchesNoFirmHold(): void
    {
        $lapsed = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);
        $option = $this->request('2027-08-01', '2027-08-04', '2027-02-01 10:00');
        $this->bookingRepository->setHold($option->id, self::at('2027-02-05 10:00'), HoldOrigin::MANAGER);
        $confirmed = $this->request('2027-09-01', '2027-09-04', '2027-02-01 10:00');
        $this->operations->confirm($this->reload($confirmed), $this->asset(), 1, self::at('2027-02-02 10:00'));

        $result = $this->bookingService->expireLapsedHolds(self::at('2027-02-10 10:00'));

        $this->assertSame(['released' => 1, 'expired' => 1], $result);
        $this->assertSame(BookingStatus::RECEIVED, $this->reload($lapsed)->status);
        $this->assertSame(BookingStatus::EXPIRED, $this->reload($option)->status);
        $this->assertSame(BookingStatus::CONFIRMED, $this->reload($confirmed)->status);
    }

    // ── One predicate for every surface ─────────────────────────────────

    /**
     * The public calendar, the form and the calendar publication read a
     * hold at the real instant, never at midnight: one that lapses at ten
     * is taken at 9:59 and free at 10:01, on all three at once.
     */
    public function testEverySurfaceReadsTheHoldAtTheSameInstant(): void
    {
        $booking = $this->request('2027-07-01', '2027-07-04', '2027-02-01 10:00', holdDays: 5);
        $today = self::at('2027-02-06 00:00');

        foreach (['2027-02-06 09:59' => true, '2027-02-06 10:01' => false] as $moment => $taken) {
            $now = self::at($moment);
            $states = $this->availability->monthDayStates($this->asset(), BillingUnit::PER_NIGHT, 2027, 7, $today, now: $now);
            $errors = $this->availability->validateRange(
                $this->asset(),
                BillingUnit::PER_NIGHT,
                self::at('2027-07-01'),
                self::at('2027-07-04'),
                1,
                $today,
                now: $now
            );

            $this->assertSame($taken ? 'occupied' : 'free', $states['2027-07-02']->state, $moment);
            $this->assertSame($taken, $errors !== [], $moment);
            $this->assertSame($taken, PublishFrom::HOLD->covers($this->reload($booking), $now), $moment);
            $this->assertSame($taken, $this->reload($booking)->occupiesTheAsset($now), $moment);
        }
    }

    /**
     * What `OccupancyProvider` promises the sources to come — a booking's
     * modification project first (chantier §2.3): an occupancy carrying the
     * booking's reference is left out of that booking's own conflicts, and
     * stops everybody else's.
     */
    public function testAnotherProvidersOccupancyOfTheSameBookingIsItsOwnNeverAnybodyElses(): void
    {
        $project = new class implements OccupancyProvider {
            public function findOccupancies(
                int $assetId,
                \DateTimeImmutable $from,
                \DateTimeImmutable $to,
                \DateTimeImmutable $now
            ): array {
                return [new Occupancy('2027-07-10', '2027-07-12', reference: 'LOC-OWNER1')];
            }
        };
        $availability = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            [$this->bookingService, $project]
        );
        $guard = new RentalOccupancyGuard($this->bookingRepository, $availability, $this->pricing);
        $now = self::at('2027-02-01 10:00');

        $this->assertTrue($guard->rangeIsFree($this->asset(), 'LOC-OWNER1', '2027-07-10', '2027-07-12', 1, $now));
        $this->assertFalse($guard->rangeIsFree($this->asset(), 'LOC-OTHER2', '2027-07-10', '2027-07-12', 1, $now));
        $this->assertFalse($guard->rangeIsFree($this->asset(), null, '2027-07-11', '2027-07-13', 1, $now));
    }
}
