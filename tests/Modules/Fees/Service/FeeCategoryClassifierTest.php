<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Member\HouseholdFeeCategory;
use Modules\Fees\Service\FeeCategoryClassifier;
use PHPUnit\Framework\TestCase;

/**
 * The heuristic that lets the screen work on the day the module is
 * switched on, and — just as important — the wordings it must refuse to
 * claim.
 */
class FeeCategoryClassifierTest extends TestCase
{
    /** @return array<string, array{string, ?HouseholdFeeCategory}> */
    public static function wordingProvider(): array
    {
        return [
            'the reference dataset wording' => ['Tarif normal', HouseholdFeeCategory::NORMAL],
            'a real Desk export code' => ['N_N_COTISATION NORMALE', HouseholdFeeCategory::NORMAL],
            'couple' => ['N_C_COTISATION COUPLE', HouseholdFeeCategory::COUPLE],
            'family' => ['N_F_COTISATION FAMILLE', HouseholdFeeCategory::FAMILY],
            'accents and case are folded' => ['Cotisation Familiale', HouseholdFeeCategory::FAMILY],
            'an animateur tariff is not a household one' => ['Tarif animateur', null],
            'a reduced tariff is not a household one' => ['Tarif réduit', null],
            'an iAM membership is not a household one' => ['COT_iAM_LOCAL', null],
            'an empty wording claims nothing' => ['', null],
        ];
    }

    /** @dataProvider wordingProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('wordingProvider')]
    public function testItRecognisesTheThreeHouseholdTariffsAndNothingElse(string $wording, ?HouseholdFeeCategory $expected): void
    {
        $this->assertSame($expected, FeeCategoryClassifier::classify($wording, $wording));
    }

    /**
     * "Cotisation famille normale" is a family tariff. The needle order is
     * what decides it, so it is pinned rather than left to chance.
     */
    public function testFamilyWinsOverNormalWhenBothWordsAreThere(): void
    {
        $this->assertSame(
            HouseholdFeeCategory::FAMILY,
            FeeCategoryClassifier::classify('COT_FAM', 'Cotisation famille normale')
        );
    }

    /** The desk code alone is enough, and so is the label alone. */
    public function testEitherHalfCanCarryTheWording(): void
    {
        $this->assertSame(HouseholdFeeCategory::COUPLE, FeeCategoryClassifier::classify('X_42', 'Cotisation couple'));
        $this->assertSame(HouseholdFeeCategory::COUPLE, FeeCategoryClassifier::classify('COUPLE', 'X_42'));
    }
}
