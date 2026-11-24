<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Service;

use Core\Member\HouseholdFeeCategory;
use Modules\Fees\Service\FeeCategoryClassifier;
use Modules\Fees\FeeTariff;
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
            // Desk offers a unit three cotisation types and nothing else
            // (issue #194), so an unrecognised value is either a tariff the
            // federation added later or a wording nobody anticipated. Both
            // must answer null rather than be forced into one of the three.
            'a guest fee is not a household one' => ['Cotisation invités', null],
            'a solidarity fee is not a household one' => ['Cotisation de solidarité', null],
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

    /** @return iterable<string, array{string, ?FeeTariff}> */
    public static function fiveTariffs(): iterable
    {
        yield 'normal invoice code' => ['COT_NORM', FeeTariff::NORMAL];
        yield 'couple invoice code' => ['COT_COUPLE', FeeTariff::COUPLE];
        yield 'family invoice code' => ['COT_FAM', FeeTariff::FAMILY];
        yield 'mixed case local IAmA invoice code' => ['COT_iAM_LOCAL', FeeTariff::IAMA];
        yield 'upper case local IAmA invoice code' => ['COT_IAM_LOCAL', FeeTariff::IAMA];
        yield 'real IAmA Desk wording' => ['L_L_COTISATION IAMA', FeeTariff::IAMA];
        yield 'guest invoice code' => ['COT_INVITE', FeeTariff::GUEST];
        yield 'guest wording' => ['Cotisation invités et demi-année', FeeTariff::GUEST];
        yield 'federal-only IAmA' => ['IAmA uniquement au niveau fédéral', null];
        yield 'federal fee for a unit IAmA' => ['IAmA liés à une unité — cotisation fédérale', FeeTariff::IAMA];
        yield 'an unknown future reference' => ['COT_EXAMPLE_FUTURE', null];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('fiveTariffs')]
    public function testApplicableTariffsIncludeOnlyRecognisedReferences(string $wording, ?FeeTariff $expected): void
    {
        self::assertSame($expected, FeeCategoryClassifier::classifyTariff($wording, $wording));
    }
}
