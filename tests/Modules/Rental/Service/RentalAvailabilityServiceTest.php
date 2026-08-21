<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Modules\Rental\Availability\BookingConstraints;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Availability\Occupancy;
use Modules\Rental\Availability\OccupancyProvider;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalException;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Persistence and validation around the pure calculator, plus the property
 * the whole design rests on: occupancy sources are pluggable, so iteration
 * 4's bookings and iteration 5's manual blocks register a provider and
 * nothing else changes.
 */
class RentalAvailabilityServiceTest extends TestCase
{
    private \PDO $pdo;
    private RentalConstraintsRepository $repository;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)');
        $stmt->execute(['Local', 'Local Saint-Georges', 'local-saint-georges']);
        $this->assetId = (int) $this->pdo->lastInsertId();

        $this->repository = new RentalConstraintsRepository($this->pdo);
    }

    /**
     * @param Occupancy[] $occupancies
     */
    private function service(array $occupancies = []): RentalAvailabilityService
    {
        $providers = $occupancies === [] ? [] : [
            new class ($occupancies) implements OccupancyProvider {
                /** @param Occupancy[] $occupancies */
                public function __construct(private array $occupancies)
                {
                }

                public function findOccupancies(int $assetId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array
                {
                    return $this->occupancies;
                }
            },
        ];

        return new RentalAvailabilityService(new AvailabilityCalculator(), $this->repository, $providers);
    }

    private function asset(int $quantity = 1, ?int $capacity = null): RentalAsset
    {
        return new RentalAsset(
            id: $this->assetId,
            assetType: 'Local',
            name: 'Local Saint-Georges',
            slug: 'local-saint-georges',
            capacity: $capacity,
            quantity: $quantity,
            arrivalTime: null,
            departureTime: null,
            emergencyPhone: null,
            isArchived: false,
            isPublic: true
        );
    }

    // ── Constraints round-trip ──────────────────────────────────────────

    public function testAnUnconfiguredAssetHasPermissiveDefaults(): void
    {
        $constraints = $this->service()->constraintsFor($this->assetId);

        $this->assertSame(0, $constraints->minNights);
        $this->assertSame(0, $constraints->maxNights);
        $this->assertSame(0, $constraints->minNoticeDays);
        $this->assertSame(0, $constraints->maxHorizonDays);
        $this->assertSame([], $constraints->allowedArrivalWeekdays);
        $this->assertNull($constraints->maxPersons);
        $this->assertSame(0, $constraints->bufferNights);
    }

    public function testAnUnknownAssetGetsDefaultsRatherThanAnException(): void
    {
        // A caller rendering a calendar has already resolved the asset;
        // throwing here would only turn a 404 into a 500.
        $constraints = $this->service()->constraintsFor(999999);

        $this->assertSame(0, $constraints->minNights);
    }

    public function testConstraintsRoundTrip(): void
    {
        $service = $this->service();
        $service->saveConstraints($this->assetId, 2, 14, 7, 365, [5, 6], 60, 1);

        $constraints = $service->constraintsFor($this->assetId);
        $this->assertSame(2, $constraints->minNights);
        $this->assertSame(14, $constraints->maxNights);
        $this->assertSame(7, $constraints->minNoticeDays);
        $this->assertSame(365, $constraints->maxHorizonDays);
        $this->assertSame([5, 6], $constraints->allowedArrivalWeekdays);
        $this->assertSame(60, $constraints->maxPersons);
        $this->assertSame(1, $constraints->bufferNights);
    }

    public function testAllSevenWeekdaysIsStoredAsNoRestriction(): void
    {
        // Same rule, one representation — otherwise "any day" has two
        // encodings and a later comparison picks the wrong one.
        $service = $this->service();
        $service->saveConstraints($this->assetId, 0, 0, 0, 0, [1, 2, 3, 4, 5, 6, 7], null, 0);

        $this->assertSame([], $service->constraintsFor($this->assetId)->allowedArrivalWeekdays);
    }

    public function testWeekdaysAreDeduplicatedSortedAndBounded(): void
    {
        $service = $this->service();
        $service->saveConstraints($this->assetId, 0, 0, 0, 0, [6, 5, 6, 0, 9, -3], null, 0);

        $this->assertSame([5, 6], $service->constraintsFor($this->assetId)->allowedArrivalWeekdays);
    }

    public function testAMinimumLongerThanTheMaximumIsRefused(): void
    {
        // Accepting it would make every possible range invalid, with nothing
        // on the page to explain why.
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/minimum ne peut pas dépasser/');

        $this->service()->saveConstraints($this->assetId, 10, 3, 0, 0, [], null, 0);
    }

    public function testANegativeConstraintIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->service()->saveConstraints($this->assetId, -1, 0, 0, 0, [], null, 0);
    }

    public function testAZeroCapacityIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->service()->saveConstraints($this->assetId, 0, 0, 0, 0, [], 0, 0);
    }

    public function testAMaximumWithNoMinimumIsAccepted(): void
    {
        $service = $this->service();
        $service->saveConstraints($this->assetId, 0, 7, 0, 0, [], null, 0);

        $this->assertSame(7, $service->constraintsFor($this->assetId)->maxNights);
    }

    // ── Occupancy providers ─────────────────────────────────────────────

    public function testWithNoProvidersEverythingBookableReadsAsFree(): void
    {
        // Honest, not a stub: bookings simply do not exist yet.
        $states = $this->service()->monthDayStates(
            $this->asset(),
            BillingUnit::PER_NIGHT,
            2027,
            7,
            new \DateTimeImmutable('2027-06-01')
        );

        $this->assertSame('free', $states['2027-07-15']->state);
    }

    public function testAProvidersOccupanciesReachTheCalendar(): void
    {
        $service = $this->service([new Occupancy('2027-07-17', '2027-07-20')]);

        $states = $service->monthDayStates(
            $this->asset(),
            BillingUnit::PER_NIGHT,
            2027,
            7,
            new \DateTimeImmutable('2027-06-01')
        );

        $this->assertSame('occupied', $states['2027-07-18']->state);
        $this->assertSame('departing', $states['2027-07-20']->state);
    }

    public function testSeveralProvidersAreMergedRatherThanOverridingEachOther(): void
    {
        // Iteration 4's bookings and iteration 5's manual blocks must add up,
        // not replace one another.
        $calculator = new AvailabilityCalculator();
        $makeProvider = static fn(array $occupancies) => new class ($occupancies) implements OccupancyProvider {
            /** @param Occupancy[] $occupancies */
            public function __construct(private array $occupancies)
            {
            }

            public function findOccupancies(int $assetId, \DateTimeImmutable $from, \DateTimeImmutable $to, \DateTimeImmutable $now): array
            {
                return $this->occupancies;
            }
        };

        $service = new RentalAvailabilityService($calculator, $this->repository, [
            $makeProvider([new Occupancy('2027-07-17', '2027-07-19', 3)]),
            $makeProvider([new Occupancy('2027-07-17', '2027-07-19', 2)]),
        ]);

        $occupancies = $service->occupanciesFor(
            $this->assetId,
            new \DateTimeImmutable('2027-07-01'),
            new \DateTimeImmutable('2027-07-31'),
            new \DateTimeImmutable('2027-06-01')
        );
        $this->assertCount(2, $occupancies);

        $states = $service->monthDayStates($this->asset(8), BillingUnit::PER_NIGHT, 2027, 7, new \DateTimeImmutable('2027-06-01'));
        $this->assertSame(['remaining' => '3'], $states['2027-07-18']->data, '8 − 3 − 2 = 3.');
    }

    public function testTheOccupancyWindowFollowsTheBufferInsteadOfAHardCodedWeek(): void
    {
        // The window used to be ±7 days flat. `bufferNights` has no ceiling,
        // so a 10-night turnaround reached past it: the conflicting stay was
        // never returned by the query and the range came back free.
        $this->repository->save($this->assetId, new BookingConstraints(bufferNights: 10));

        $windows = [];
        $provider = new class ($windows) implements OccupancyProvider {
            /** @var array<int, array{0: string, 1: string}> */
            public array $windows = [];

            /** @param array<int, array{0: string, 1: string}> $seed */
            public function __construct(array $seed)
            {
                $this->windows = $seed;
            }

            public function findOccupancies(
                int $assetId,
                \DateTimeImmutable $from,
                \DateTimeImmutable $to,
                \DateTimeImmutable $now
            ): array {
                $this->windows[] = [$from->format('Y-m-d'), $to->format('Y-m-d')];

                return [new Occupancy('2027-06-20', '2027-06-23')];
            }
        };

        $service = new RentalAvailabilityService(new AvailabilityCalculator(), $this->repository, [$provider]);

        $free = $service->isRangeFree(
            $this->asset(),
            BillingUnit::PER_NIGHT,
            new \DateTimeImmutable('2027-07-01'),
            new \DateTimeImmutable('2027-07-03'),
            1,
            new \DateTimeImmutable('2027-06-01')
        );

        $this->assertSame(['2027-06-20', '2027-07-14'], $provider->windows[0], 'Padded by the buffer plus a day.');
        $this->assertFalse($free, 'The buffered stay still runs to 2 July.');
    }

    // ── Range validation through the service ────────────────────────────

    public function testTheAssetsOwnCapacityActsAsTheCeilingWhenNoneIsConfigured(): void
    {
        // An operator who filled in "60 places" has already said what the
        // hall holds; making them repeat it is how the two end up disagreeing.
        $errors = $this->service()->validateRange(
            $this->asset(capacity: 60),
            BillingUnit::PER_NIGHT,
            new \DateTimeImmutable('2027-07-17'),
            new \DateTimeImmutable('2027-07-20'),
            1,
            new \DateTimeImmutable('2027-06-01'),
            persons: 80
        );

        $this->assertStringContainsString('capacité maximum', implode(' ', $errors));
        $this->assertStringContainsString('60', implode(' ', $errors));
    }

    public function testAnExplicitBookingMaximumOverridesTheAssetsCapacity(): void
    {
        $service = $this->service();
        $service->saveConstraints($this->assetId, 0, 0, 0, 0, [], 30, 0);

        $errors = $service->validateRange(
            $this->asset(capacity: 60),
            BillingUnit::PER_NIGHT,
            new \DateTimeImmutable('2027-07-17'),
            new \DateTimeImmutable('2027-07-20'),
            1,
            new \DateTimeImmutable('2027-06-01'),
            persons: 40
        );

        $this->assertStringContainsString('30', implode(' ', $errors));
    }

    public function testAValidRangePassesThroughTheService(): void
    {
        $errors = $this->service()->validateRange(
            $this->asset(capacity: 60),
            BillingUnit::PER_NIGHT,
            new \DateTimeImmutable('2027-07-17'),
            new \DateTimeImmutable('2027-07-20'),
            1,
            new \DateTimeImmutable('2027-06-01'),
            persons: 35
        );

        $this->assertSame([], $errors);
    }

    public function testAnOccupiedRangeIsRefusedThroughTheService(): void
    {
        $service = $this->service([new Occupancy('2027-07-18', '2027-07-19')]);

        $errors = $service->validateRange(
            $this->asset(),
            BillingUnit::PER_NIGHT,
            new \DateTimeImmutable('2027-07-17'),
            new \DateTimeImmutable('2027-07-20'),
            1,
            new \DateTimeImmutable('2027-06-01')
        );

        $this->assertSame(["Cette période n'est pas disponible."], $errors);
    }

    public function testTheBufferStoredOnTheAssetIsAppliedByTheCalendar(): void
    {
        $service = $this->service([new Occupancy('2027-07-17', '2027-07-20')]);
        $service->saveConstraints($this->assetId, 0, 0, 0, 0, [], null, 1);

        $states = $service->monthDayStates($this->asset(), BillingUnit::PER_NIGHT, 2027, 7, new \DateTimeImmutable('2027-06-01'));

        $this->assertSame('occupied', $states['2027-07-20']->state, 'The buffer holds the departure day.');
    }
}
