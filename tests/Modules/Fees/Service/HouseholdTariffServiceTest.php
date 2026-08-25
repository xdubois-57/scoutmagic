<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Core\Member\HouseholdFeeCategory;
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
    private int $animateurId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalId = $this->feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->coupleId = $this->feeCategories->create('N_C_COTISATION COUPLE', 'Cotisation couple');
        $this->familyId = $this->feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');
        $this->animateurId = $this->feeCategories->create('Tarif animateur', 'Tarif animateur');

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
        $this->assertNull($this->service->categoryForFeeCategoryId($this->animateurId));
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

    public function testThePanelAlwaysCarriesTheThreeLinesAndWhatTheSiteGuessed(): void
    {
        $this->service->save(HouseholdFeeCategory::NORMAL, $this->animateurId, 4200);

        $panel = $this->service->panel();

        $this->assertSame(['normal', 'couple', 'family'], array_keys($panel));
        $this->assertSame($this->animateurId, $panel['normal']['fee_category_id']);
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
}
