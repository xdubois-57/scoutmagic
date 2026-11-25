<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Core\Member\HouseholdFeeCategory;
use Modules\Fees\FeeTariff;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Service\HouseholdTariffService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * The barème: which Desk tariff means what, and what a discrepancy costs.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class HouseholdTariffServiceTest extends TestCase
{
    private \PDO $pdo;
    private HouseholdTariffService $service;
    private FeeCategoryRepository $feeCategories;
    private int $normalId;
    private int $coupleId;
    private int $familyId;
    private int $unrecognisedFeeId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalId = $this->feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->coupleId = $this->feeCategories->create('N_C_COTISATION COUPLE', 'Cotisation couple');
        $this->familyId = $this->feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');
        $this->unrecognisedFeeId = $this->feeCategories->create('Cotisation invités', 'Cotisation invités');

        $this->service = new HouseholdTariffService(
            new HouseholdTariffRepository($this->pdo),
            $this->feeCategories
        );
    }

    /**
     * Nothing configured, and the screen already works: this is what keeps
     * a unit from having to set anything up before it can be told what is
     * wrong.
     */
    public function testTheHeuristicAnswersBeforeAnybodyConfiguresAnything(): void
    {
        $this->assertSame(HouseholdFeeCategory::NORMAL, $this->service->categoryForFeeCategoryId($this->normalId));
        $this->assertSame(HouseholdFeeCategory::COUPLE, $this->service->categoryForFeeCategoryId($this->coupleId));
        $this->assertSame(HouseholdFeeCategory::FAMILY, $this->service->categoryForFeeCategoryId($this->familyId));
    }

    public function testATariffOutsideTheThreeIsNotOneOfThemAndSaysSo(): void
    {
        $this->assertNull($this->service->categoryForFeeCategoryId($this->unrecognisedFeeId));
        $this->assertNull($this->service->categoryForFeeCategoryId(null));
        $this->assertNull($this->service->categoryForFeeCategoryId(999999));
    }

    /**
     * A unit saying "couple is THIS code" is also saying it is not the one
     * the site guessed: the override has to take the claim away from the
     * guessed row, or two codes would both mean "couple".
     */
    public function testAnOverrideBothClaimsItsCodeAndReleasesTheGuessedOne(): void
    {
        $oddId = $this->feeCategories->create('T2', 'T2');
        $this->service->save(HouseholdFeeCategory::COUPLE, $oddId, null);

        $this->assertSame(HouseholdFeeCategory::COUPLE, $this->service->categoryForFeeCategoryId($oddId));
        $this->assertNull($this->service->categoryForFeeCategoryId($this->coupleId));
        // The other two are untouched.
        $this->assertSame(HouseholdFeeCategory::NORMAL, $this->service->categoryForFeeCategoryId($this->normalId));
    }

    /**
     * What the « Attendu » column shows (#1326): the real Desk code of the
     * category the CALCULATION uses for each household tariff — the same
     * mapping, not a second one beside it.
     */
    public function testTheExpectedDeskCodeIsTheOneTheHeuristicMapsTo(): void
    {
        $this->assertSame(['N_N_COTISATION NORMALE'], $this->service->deskCodesFor(HouseholdFeeCategory::NORMAL));
        $this->assertSame(['N_C_COTISATION COUPLE'], $this->service->deskCodesFor(HouseholdFeeCategory::COUPLE));
        $this->assertSame(['N_F_COTISATION FAMILLE'], $this->service->deskCodesFor(HouseholdFeeCategory::FAMILY));
    }

    public function testAnOverrideReplacesTheGuessedCodeInTheExpectedColumn(): void
    {
        $this->service->save(HouseholdFeeCategory::COUPLE, $this->unrecognisedFeeId, null);

        $this->assertSame(['Cotisation invités'], $this->service->deskCodesFor(HouseholdFeeCategory::COUPLE));
        // The guessed one is released, not kept beside it.
        $this->assertSame(['N_N_COTISATION NORMALE'], $this->service->deskCodesFor(HouseholdFeeCategory::NORMAL));
    }

    /** A code is never invented: a tariff nobody maps has no code at all. */
    public function testATariffWithNoDeskCategoryHasNoExpectedCode(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($pdo);
        $repository = new FeeCategoryRepository($pdo);
        $repository->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $service = new HouseholdTariffService(new HouseholdTariffRepository($pdo), $repository);

        $this->assertSame([], $service->deskCodesFor(HouseholdFeeCategory::COUPLE));
    }

    public function testSavingTheScaleRefreshesTheExpectedCodes(): void
    {
        $this->assertSame(['N_C_COTISATION COUPLE'], $this->service->deskCodesFor(HouseholdFeeCategory::COUPLE));

        $this->service->save(HouseholdFeeCategory::COUPLE, $this->unrecognisedFeeId, null);

        $this->assertSame(['Cotisation invités'], $this->service->deskCodesFor(HouseholdFeeCategory::COUPLE));
    }

    public function testTheDifferenceIsSignedAndNullAsSoonAsEitherAmountIsMissing(): void
    {
        $this->service->save(HouseholdFeeCategory::NORMAL, null, 4000);
        $this->service->save(HouseholdFeeCategory::COUPLE, null, 3500);

        $this->assertSame(-500, $this->service->differenceCents(HouseholdFeeCategory::COUPLE, HouseholdFeeCategory::NORMAL));
        $this->assertSame(500, $this->service->differenceCents(HouseholdFeeCategory::NORMAL, HouseholdFeeCategory::COUPLE));
        // Family has no amount: a figure here would be invented.
        $this->assertNull($this->service->differenceCents(HouseholdFeeCategory::FAMILY, HouseholdFeeCategory::NORMAL));
        // Nothing encoded at all: nothing to compare.
        $this->assertNull($this->service->differenceCents(HouseholdFeeCategory::NORMAL, null));
    }

    public function testItKnowsWhetherAnyAmountWasEverEntered(): void
    {
        $this->assertFalse($this->service->hasAnyAmount());

        $this->service->save(HouseholdFeeCategory::COUPLE, null, null);
        $this->assertFalse($this->service->hasAnyAmount(), 'A mapping without an amount is not an amount.');

        $this->service->save(HouseholdFeeCategory::FAMILY, null, 3000);
        $this->assertTrue($this->service->hasAnyAmount());
    }

    public function testThePanelAlwaysCarriesTheFiveTariffsAndWhatTheSiteGuessed(): void
    {
        $this->service->save(HouseholdFeeCategory::NORMAL, $this->unrecognisedFeeId, 4200);

        $panel = $this->service->panel();

        $this->assertSame(['normal', 'couple', 'family', 'iama', 'guest'], array_keys($panel));
        $this->assertSame($this->unrecognisedFeeId, $panel['normal']['fee_category_id']);
        $this->assertSame(4200, $panel['normal']['amount_cents']);
        // The guess is still offered beside the override, so a chief can see
        // what they overrode.
        $this->assertSame($this->normalId, $panel['normal']['suggested_fee_category_id']);
        $this->assertNull($panel['couple']['fee_category_id']);
        $this->assertSame($this->coupleId, $panel['couple']['suggested_fee_category_id']);
    }

    public function testSavingTwiceReplacesTheLineRatherThanAddingASecond(): void
    {
        $this->service->save(HouseholdFeeCategory::FAMILY, null, 3000);
        $this->service->save(HouseholdFeeCategory::FAMILY, null, 3100);

        $this->assertSame(3100, $this->service->amountCentsFor(HouseholdFeeCategory::FAMILY));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM fees_household_tariffs')->fetchColumn());
    }

    public function testAnAmountCanBeClearedBackToNothing(): void
    {
        $this->service->save(HouseholdFeeCategory::FAMILY, null, 3000);
        $this->service->save(HouseholdFeeCategory::FAMILY, null, null);

        $this->assertNull($this->service->amountCentsFor(HouseholdFeeCategory::FAMILY));
    }

    public function testTheNewTariffsRoundTripWithoutBecomingHouseholdSizes(): void
    {
        $iama = $this->feeCategories->create('L_L_COTISATION IAMA', 'Cotisation IAmA');
        $this->service->save(FeeTariff::IAMA, $iama, 2500);
        $this->service->save(FeeTariff::GUEST, null, 1825);
        $fresh = new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $this->feeCategories);

        self::assertSame([FeeTariff::IAMA, FeeTariff::GUEST, 2500, 1825, null, null, 675], [
            $fresh->tariffForFeeCategoryId($iama),
            $fresh->tariffForFeeCategoryId($this->unrecognisedFeeId),
            $fresh->amountCentsFor(FeeTariff::IAMA),
            $fresh->amountCentsFor(FeeTariff::GUEST),
            $fresh->categoryForFeeCategoryId($iama),
            $fresh->categoryForFeeCategoryId($this->unrecognisedFeeId),
            $fresh->differenceCents(FeeTariff::IAMA, FeeTariff::GUEST),
        ]);
    }

    public function testASavedEmptyNewTariffIsDistinctFromAnAbsentRow(): void
    {
        $before = $this->service->wasSaved(FeeTariff::IAMA);
        $this->service->save(FeeTariff::IAMA, null, null);

        self::assertSame([false, true, null, ['L_L_COTISATION IAMA']], [
            $before, $this->service->wasSaved(FeeTariff::IAMA),
            $this->service->amountCentsFor(FeeTariff::IAMA),
            $this->codesAfterImportingIama(),
        ]);
    }

    /** Resolving an imported Desk category must refresh the mapping after a scale write. */
    /** @return list<string> */
    private function codesAfterImportingIama(): array
    {
        $id = $this->feeCategories->create('L_L_COTISATION IAMA', 'Cotisation IAmA');
        $this->service->save(FeeTariff::IAMA, $id, null);

        return $this->service->deskCodesFor(FeeTariff::IAMA);
    }
}
