<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PricingRequest;
use Modules\Rental\Pricing\PricingSettings;
use Modules\Rental\Pricing\RentalFee;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * Persistence and validation around the pure engine, plus the property that
 * matters most for the configuration screen: the simulator and the price a
 * visitor is shown come out of the same code, so a tariff that looks right
 * in the simulator cannot be wrong on the public page.
 */
class RentalPricingServiceTest extends TestCase
{
    private \PDO $pdo;
    private RentalPricingRepository $repository;
    private RentalPricingService $service;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        RentalTestHelper::createTables($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)');
        $stmt->execute(['Local', 'Local Saint-Georges', 'local-saint-georges']);
        $this->assetId = (int) $this->pdo->lastInsertId();

        $this->repository = new RentalPricingRepository($this->pdo);

        $journalRepository = new class extends JournalRepository {
            public function __construct()
            {
            }

            public function insert(
                string $category,
                string $type,
                string $level,
                string $description,
                ?string $context = null,
                ?int $userId = null,
                ?string $ipAddress = null
            ): void {
            }
        };

        $this->service = new RentalPricingService(
            $this->repository,
            new RentalPricingEngine(),
            new JournalService($journalRepository)
        );
    }

    // ── The four configuration blocks round-trip ────────────────────────

    public function testAnUnconfiguredAssetLoadsUsableDefaults(): void
    {
        $settings = $this->service->loadSettings($this->assetId);

        $this->assertSame(BillingUnit::FLAT_STAY, $settings->billingUnit);
        $this->assertSame([], $settings->periods);
        $this->assertSame([], $settings->categories);
        $this->assertSame([], $settings->fees);
        $this->assertNull($settings->defaultUnitPriceCents);
        $this->assertFalse($settings->hasMinimum());
    }

    public function testAnUnknownAssetLoadsDefaultsRatherThanThrowing(): void
    {
        $settings = $this->service->loadSettings(999999);

        $this->assertSame(BillingUnit::FLAT_STAY, $settings->billingUnit);
    }

    public function testAssetPricingRoundTrips(): void
    {
        $this->service->saveAssetPricing($this->assetId, 'per_person_night', 250, null, 25);

        $settings = $this->service->loadSettings($this->assetId);
        $this->assertSame(BillingUnit::PER_PERSON_NIGHT, $settings->billingUnit);
        $this->assertSame(250, $settings->defaultUnitPriceCents);
        $this->assertSame(25, $settings->minimumPersons);
        $this->assertNull($settings->minimumAmountCents);
    }

    public function testPeriodsCategoriesGridAndFeesRoundTrip(): void
    {
        $this->service->saveAssetPricing($this->assetId, 'per_night', null, null, null);
        $low = $this->service->addPeriod($this->assetId, 'Basse saison', '2027-01-01', '2027-06-30', false);
        $high = $this->service->addPeriod($this->assetId, 'Haute saison', '2027-07-01', '2027-08-31', false);
        $youth = $this->service->addCategory($this->assetId, 'Mouvement de jeunesse', true);
        $company = $this->service->addCategory($this->assetId, 'Entreprise', false);

        $this->service->saveGrid($this->assetId, [
            PricingSettings::gridKey($low, $youth) => 5000,
            PricingSettings::gridKey($high, $company) => 12000,
        ]);
        $this->service->addFee($this->assetId, 'Nettoyage', RentalFee::NATURE_FIXED, 5000, null);
        $this->service->addFee($this->assetId, 'Électricité', RentalFee::NATURE_METER, 35, 'kWh');

        $settings = $this->service->loadSettings($this->assetId);

        $this->assertCount(2, $settings->periods);
        $this->assertSame('Basse saison', $settings->periods[0]->label);
        $this->assertCount(2, $settings->categories);
        $this->assertTrue($settings->categories[0]->isDefault);
        $this->assertSame(5000, $settings->unitPriceCentsFor($low, $youth));
        $this->assertSame(12000, $settings->unitPriceCentsFor($high, $company));
        $this->assertNull($settings->unitPriceCentsFor($low, $company), 'A cell nobody filled has no price.');
        $this->assertCount(2, $settings->fees);
        $this->assertSame('kWh', $settings->fees[1]->meterUnit);
    }

    public function testSavingTheGridReplacesItRatherThanMergingIntoIt(): void
    {
        // A cell the operator cleared has to disappear, not keep its old
        // price — the screen posts the whole grid.
        $period = $this->service->addPeriod($this->assetId, 'P', '2027-01-01', '2027-12-31', false);
        $catA = $this->service->addCategory($this->assetId, 'A', false);
        $catB = $this->service->addCategory($this->assetId, 'B', false);

        $this->service->saveGrid($this->assetId, [
            PricingSettings::gridKey($period, $catA) => 5000,
            PricingSettings::gridKey($period, $catB) => 9000,
        ]);
        $this->service->saveGrid($this->assetId, [
            PricingSettings::gridKey($period, $catA) => 5500,
        ]);

        $settings = $this->service->loadSettings($this->assetId);
        $this->assertSame(5500, $settings->unitPriceCentsFor($period, $catA));
        $this->assertNull($settings->unitPriceCentsFor($period, $catB));
    }

    public function testSavingTheGridDropsCellsForDeletedPeriodsOrCategories(): void
    {
        // A stale form posting a deleted season's id must not fail the whole
        // save on a foreign key.
        $period = $this->service->addPeriod($this->assetId, 'P', '2027-01-01', '2027-12-31', false);
        $category = $this->service->addCategory($this->assetId, 'A', false);

        $this->service->saveGrid($this->assetId, [
            PricingSettings::gridKey($period, $category) => 5000,
            PricingSettings::gridKey(9999, $category) => 7000,
            PricingSettings::gridKey($period, 8888) => 8000,
        ]);

        $settings = $this->service->loadSettings($this->assetId);
        $this->assertSame(5000, $settings->unitPriceCentsFor($period, $category));
        $this->assertCount(1, $settings->priceGridCents);
    }

    public function testDeletingAPeriodTakesItsGridCellsWithIt(): void
    {
        $period = $this->service->addPeriod($this->assetId, 'P', '2027-01-01', '2027-12-31', false);
        $category = $this->service->addCategory($this->assetId, 'A', false);
        $this->service->saveGrid($this->assetId, [PricingSettings::gridKey($period, $category) => 5000]);

        $this->service->deletePeriod($this->assetId, $period);

        $settings = $this->service->loadSettings($this->assetId);
        $this->assertSame([], $settings->periods);
        $this->assertSame([], $settings->priceGridCents, 'A price for a season that no longer exists is not a price.');
    }

    public function testDeletingACategoryTakesItsGridCellsWithIt(): void
    {
        $period = $this->service->addPeriod($this->assetId, 'P', '2027-01-01', '2027-12-31', false);
        $category = $this->service->addCategory($this->assetId, 'A', false);
        $this->service->saveGrid($this->assetId, [PricingSettings::gridKey($period, $category) => 5000]);

        $this->service->deleteCategory($this->assetId, $category);

        $this->assertSame([], $this->service->loadSettings($this->assetId)->priceGridCents);
    }

    public function testDeletingAFeeRemovesIt(): void
    {
        $fee = $this->service->addFee($this->assetId, 'Nettoyage', RentalFee::NATURE_FIXED, 5000, null);

        $this->service->deleteFee($this->assetId, $fee);

        $this->assertSame([], $this->service->loadSettings($this->assetId)->fees);
    }

    public function testAnotherAssetsConfigurationIsNeverReturnedOrDeleted(): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO rental_assets (asset_type, name, slug) VALUES (?, ?, ?)');
        $stmt->execute(['Local', 'Autre', 'autre']);
        $otherId = (int) $this->pdo->lastInsertId();

        $mine = $this->service->addFee($this->assetId, 'Le mien', RentalFee::NATURE_FIXED, 100, null);
        $this->service->addFee($otherId, 'Le leur', RentalFee::NATURE_FIXED, 200, null);

        $this->assertCount(1, $this->service->loadSettings($this->assetId)->fees);
        $this->assertCount(1, $this->service->loadSettings($otherId)->fees);

        // Deleting is scoped by asset, so a forged id cannot reach across.
        $this->service->deleteFee($otherId, $mine);
        $this->assertCount(1, $this->service->loadSettings($this->assetId)->fees);
    }

    // ── Validation ──────────────────────────────────────────────────────

    public function testTheMinimumIsAnAmountOrAPersonCountNeverBoth(): void
    {
        // Storing both would need a precedence rule between them, and this
        // engine deliberately has none.
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/pas les deux/');

        $this->service->saveAssetPricing($this->assetId, 'per_person_night', 250, 20000, 25);
    }

    public function testAPersonMinimumIsRefusedForANonPersonBasedUnit(): void
    {
        $this->expectException(RentalException::class);

        $this->service->saveAssetPricing($this->assetId, 'per_night', 5000, null, 25);
    }

    public function testAnUnknownBillingUnitIsRefused(): void
    {
        $this->expectException(RentalException::class);

        $this->service->saveAssetPricing($this->assetId, 'per_fortnight', 5000, null, null);
    }

    public function testNegativeAmountsAreRefused(): void
    {
        try {
            $this->service->saveAssetPricing($this->assetId, 'per_night', -1, null, null);
            $this->fail('A negative rate must be refused.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('négatif', $e->getMessage());
        }

        try {
            $this->service->saveGrid($this->assetId, [PricingSettings::gridKey(null, null) => -500]);
            $this->fail('A negative grid cell must be refused.');
        } catch (RentalException $e) {
            $this->assertStringContainsString('négatif', $e->getMessage());
        }
    }

    public function testAPeriodNeedsALabelAndValidDates(): void
    {
        foreach ([
            ['', '2027-01-01', '2027-06-30'],
            ['P', 'pas-une-date', '2027-06-30'],
            ['P', '2027-01-01', '2027-02-31'],
        ] as [$label, $start, $end]) {
            try {
                $this->service->addPeriod($this->assetId, $label, $start, $end, false);
                $this->fail("Expected a refusal for [{$label}, {$start}, {$end}].");
            } catch (RentalException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testANonRecurringPeriodMustNotEndBeforeItStarts(): void
    {
        $this->expectException(RentalException::class);

        $this->service->addPeriod($this->assetId, 'P', '2027-06-30', '2027-01-01', false);
    }

    public function testARecurringPeriodMayWrapTheNewYear(): void
    {
        // "20 December → 5 January" is a real season — the busiest weeks a
        // scout hall has. Rejecting it would make it impossible to price.
        $id = $this->service->addPeriod($this->assetId, 'Vacances de Noël', '2027-12-20', '2027-01-05', true);

        $periods = $this->service->loadSettings($this->assetId)->periods;
        $this->assertCount(1, $periods);
        $this->assertSame($id, $periods[0]->id);
        $this->assertTrue($periods[0]->recursYearly);
        $this->assertTrue($periods[0]->covers(new \DateTimeImmutable('2028-12-24')));
        $this->assertTrue($periods[0]->covers(new \DateTimeImmutable('2029-01-02')));
    }

    public function testACategoryNeedsALabel(): void
    {
        $this->expectException(RentalException::class);

        $this->service->addCategory($this->assetId, '   ', false);
    }

    public function testAFeeNeedsALabelAValidNatureAndANonNegativeAmount(): void
    {
        foreach ([
            ['', RentalFee::NATURE_FIXED, 100],
            ['Frais', 'per_fortnight', 100],
            ['Frais', RentalFee::NATURE_FIXED, -1],
        ] as [$label, $nature, $amount]) {
            try {
                $this->service->addFee($this->assetId, $label, $nature, $amount, null);
                $this->fail("Expected a refusal for [{$label}, {$nature}, {$amount}].");
            } catch (RentalException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testAMeterFeeMustDeclareItsUnit(): void
    {
        // Without a unit, a reading is a bare number: 400 what?
        $this->expectException(RentalException::class);
        $this->expectExceptionMessageMatches('/unité/');

        $this->service->addFee($this->assetId, 'Électricité', RentalFee::NATURE_METER, 35, '  ');
    }

    public function testANonMeterFeeStoresNoMeterUnitEvenIfOneIsSupplied(): void
    {
        $this->service->addFee($this->assetId, 'Nettoyage', RentalFee::NATURE_FIXED, 5000, 'kWh');

        $this->assertNull($this->service->loadSettings($this->assetId)->fees[0]->meterUnit);
    }

    // ── Amount parsing ──────────────────────────────────────────────────

    public function testAFrenchDecimalCommaParsesToTheRightNumberOfCents(): void
    {
        // (float) "2,50" is 2.0 — a tariff silently entered at a fifth of its
        // value, with nothing to notice it by. This is the case that matters.
        $this->assertSame(250, RentalPricingService::parseAmountToCents('2,50'));
        $this->assertSame(250, RentalPricingService::parseAmountToCents('2.50'));
        $this->assertSame(123456, RentalPricingService::parseAmountToCents('1 234,56'));
        $this->assertSame(5000, RentalPricingService::parseAmountToCents(' 50 '));
        $this->assertSame(0, RentalPricingService::parseAmountToCents('0'));
    }

    public function testAnEmptyAmountMeansNotSetRatherThanZero(): void
    {
        $this->assertNull(RentalPricingService::parseAmountToCents(''));
        $this->assertNull(RentalPricingService::parseAmountToCents('   '));
        $this->assertNull(RentalPricingService::parseAmountToCents(null));
    }

    public function testANonNumericAmountIsRefused(): void
    {
        $this->expectException(RentalException::class);

        RentalPricingService::parseAmountToCents('gratuit');
    }

    // ── The simulator is the same code as the public price ──────────────

    public function testQuoteForAssetAndQuoteWithSettingsAgreeExactly(): void
    {
        // The configuration simulator's whole purpose is to be a guard-rail
        // against a wrong tariff (§ iteration 2), which it can only be if it
        // is provably the same engine the visitor's price comes from. A
        // second code path would be a guard-rail against nothing.
        $this->service->saveAssetPricing($this->assetId, 'per_person_night', null, null, 25);
        $period = $this->service->addPeriod($this->assetId, 'Haute saison', '2027-07-01', '2027-08-31', false);
        $category = $this->service->addCategory($this->assetId, 'Mouvement de jeunesse', true);
        $this->service->saveGrid($this->assetId, [PricingSettings::gridKey($period, $category) => 250]);
        $this->service->addFee($this->assetId, 'Nettoyage', RentalFee::NATURE_FIXED, 5000, null);
        $this->service->addFee($this->assetId, 'Taxe', RentalFee::NATURE_PER_PERSON, 100, null);

        $request = new PricingRequest('2027-07-16', '2027-07-19', persons: 38, renterCategoryId: $category);

        $fromAsset = $this->service->quoteForAsset($this->assetId, $request);
        $fromSettings = $this->service->quoteWithSettings($this->service->loadSettings($this->assetId), $request);

        $this->assertEquals($fromAsset->toArray(), $fromSettings->toArray());
        $this->assertSame(37300, $fromAsset->totalCents, 'The spec §9 scenario E total, end to end through the database.');
    }

    public function testAStoredConfigurationReproducesTheSpecScenarioEBreakdown(): void
    {
        $this->service->saveAssetPricing($this->assetId, 'per_person_night', 250, null, 25);
        $this->service->addFee($this->assetId, 'Nettoyage', RentalFee::NATURE_FIXED, 5000, null);
        $this->service->addFee($this->assetId, 'Taxe de séjour', RentalFee::NATURE_PER_PERSON, 100, null);

        $withEnough = $this->service->quoteForAsset($this->assetId, new PricingRequest('2027-07-16', '2027-07-19', persons: 38));
        $belowFloor = $this->service->quoteForAsset($this->assetId, new PricingRequest('2027-07-16', '2027-07-19', persons: 18));

        $this->assertSame(37300, $withEnough->totalCents);
        $this->assertFalse($withEnough->minimumApplied);

        $this->assertSame(25550, $belowFloor->totalCents);
        $this->assertTrue($belowFloor->minimumApplied);
        $this->assertStringContainsString('(minimum)', $belowFloor->lines[0]->label);
    }
}
