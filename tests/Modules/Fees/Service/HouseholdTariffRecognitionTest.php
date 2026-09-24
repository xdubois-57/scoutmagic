<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Import\FeeCategoryRepository;
use Core\Member\HouseholdFeeCategory;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Service\HouseholdTariffRecognition;
use Modules\Fees\Service\HouseholdTariffService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;

/**
 * The capability this module publishes to core (issue #356): whether a
 * Desk tariff means one of the three household cotisations.
 *
 * Two questions, and the distinction between them is the whole class —
 * one is about a wording nobody has had a chance to map yet, the other
 * about a stored row a unit may have settled by hand.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class HouseholdTariffRecognitionTest extends TestCase
{
    private \PDO $pdo;
    private FeeCategoryRepository $feeCategories;
    private HouseholdTariffService $tariffs;
    private HouseholdTariffRecognition $recognition;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->feeCategories = new FeeCategoryRepository($this->pdo);
        $this->tariffs = new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $this->feeCategories);
        $this->recognition = new HouseholdTariffRecognition($this->tariffs, $this->feeCategories);
    }

    public function testTheOrdinaryCotisationWordingsAreRecognised(): void
    {
        $this->assertTrue($this->recognition->recognisesWording('N_N_COTISATION NORMALE', 'Cotisation normale'));
        $this->assertTrue($this->recognition->recognisesWording('N_C_COTISATION COUPLE', 'Cotisation couple'));
        $this->assertTrue($this->recognition->recognisesWording('N_F_COTISATION FAMILLE', 'Cotisation famille'));
    }

    public function testAWordingOutsideTheThreeIsNot(): void
    {
        $this->assertFalse($this->recognition->recognisesWording('reduit_fratrie', 'reduit_fratrie'));
    }

    /**
     * The question an import asks, and why it is the WORDING one: the row
     * exists but nothing can have been mapped onto it yet, so the settled
     * question would answer « unrecognised » for a perfectly ordinary
     * « Cotisation normale » arriving for the first time.
     */
    public function testAFreshlyCreatedOrdinaryCategoryReadsAsRecognisedOnItsWording(): void
    {
        $this->feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');

        $this->assertTrue($this->recognition->recognisesWording('N_N_COTISATION NORMALE', 'Cotisation normale'));
    }

    public function testTheSettledAnswerLeavesTheRecognisedOnesOut(): void
    {
        $this->feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $unknown = $this->feeCategories->create('reduit_fratrie', 'reduit_fratrie');

        $this->assertSame([$unknown], $this->recognition->unmappedFeeCategoryIds());
    }

    /**
     * The case the interface warns is not the complement of the other
     * method: a unit mapping « couple » onto one code takes that claim
     * away from whatever the heuristic had guessed for couple. The guessed
     * one then reads as recognised on its wording and is claimed by
     * nobody — so it IS a gap, and saying otherwise would hide a member
     * left out of the comparison.
     */
    public function testACategoryAnExplicitMappingTookTheClaimFromIsAGap(): void
    {
        $guessed = $this->feeCategories->create('N_C_COTISATION COUPLE', 'Cotisation couple');
        $chosen = $this->feeCategories->create('X_DUO', 'Duo');

        $this->assertNotContains($guessed, $this->recognition->unmappedFeeCategoryIds());

        $this->tariffs->save(HouseholdFeeCategory::COUPLE, $chosen, 5625);

        $settled = (new HouseholdTariffRecognition(
            new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $this->feeCategories),
            $this->feeCategories
        ))->unmappedFeeCategoryIds();

        $this->assertContains($guessed, $settled);
        $this->assertNotContains($chosen, $settled);
        $this->assertTrue($this->recognition->recognisesWording('N_C_COTISATION COUPLE', 'Cotisation couple'));
    }
}
