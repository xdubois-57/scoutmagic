<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Pricing;

use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\QuoteEditor;
use PHPUnit\Framework\TestCase;

/**
 * The one line a manager adds to a price: a discount (#927, chantier §2.3).
 *
 * The rule under test: the tariff's lines stay exactly as the engine
 * computed them, a single « Remise » sits beside them as its own visible
 * line, and the total is always the lines added up.
 */
class QuoteEditorTest extends TestCase
{
    private QuoteEditor $editor;

    protected function setUp(): void
    {
        $this->editor = new QuoteEditor();
    }

    /**
     * @param PriceLine[] $lines
     */
    private function quote(array $lines): PriceQuote
    {
        $total = 0;
        foreach ($lines as $line) {
            if (!$line->isInformational) {
                $total += $line->amountCents;
            }
        }

        return new PriceQuote($lines, $total, 3, 20, 1, BillingUnit::PER_NIGHT);
    }

    private function tariff(): PriceQuote
    {
        return $this->quote([
            new PriceLine('Location — nuit', 3, 18000, 54000, PriceLine::RULE_BASE),
            new PriceLine('Nettoyage final', 1, null, 12000, PriceLine::RULE_FEE_FIXED),
            new PriceLine('Électricité', 1, 35, 0, PriceLine::RULE_FEE_METER, true),
        ]);
    }

    public function testADiscountIsItsOwnLineAndTheTotalFollows(): void
    {
        $priced = $this->editor->withDiscount($this->tariff(), 'Remise fidélité', -4800);

        $this->assertCount(4, $priced->lines);
        $this->assertSame(61200, $priced->totalCents);
        $discount = QuoteEditor::discountLine($priced);
        $this->assertNotNull($discount);
        $this->assertSame('Remise fidélité', $discount->label);
        $this->assertSame(-4800, $discount->amountCents);
        // Never re-scaled by the final settlement.
        $this->assertTrue($discount->isManual);
    }

    public function testTheTariffsLinesAreLeftExactlyAsComputed(): void
    {
        $tariff = $this->tariff();
        $priced = $this->editor->withDiscount($tariff, 'Remise', -1000);

        $this->assertSame(
            array_map(static fn(PriceLine $l): array => $l->toArray(), $tariff->lines),
            array_map(static fn(PriceLine $l): array => $l->toArray(), array_slice($priced->lines, 0, 3))
        );
    }

    public function testThereIsNeverMoreThanOneDiscount(): void
    {
        $once = $this->editor->withDiscount($this->tariff(), 'Remise', -1000);
        $twice = $this->editor->withDiscount($once, 'Autre remise', -2000);

        $discounts = array_filter(
            $twice->lines,
            static fn(PriceLine $l): bool => $l->rule === PriceLine::RULE_DISCOUNT
        );
        $this->assertCount(1, $discounts);
        $this->assertSame(64000, $twice->totalCents);
    }

    public function testNoAmountOrAZeroOneAddsNothingAndClearsADiscount(): void
    {
        $discounted = $this->editor->withDiscount($this->tariff(), 'Remise', -1000);

        foreach ([null, 0] as $amount) {
            $plain = $this->editor->withDiscount($discounted, 'Remise', $amount);
            $this->assertNull(QuoteEditor::discountLine($plain));
            $this->assertSame(66000, $plain->totalCents);
        }
    }

    public function testAPositiveAmountIsNoDiscount(): void
    {
        $priced = $this->editor->withDiscount($this->tariff(), 'Supplément', 5000);

        $this->assertNull(QuoteEditor::discountLine($priced));
        $this->assertSame(66000, $priced->totalCents);
    }

    public function testAnUnnamedDiscountIsCalledRemise(): void
    {
        $priced = $this->editor->withDiscount($this->tariff(), '   ', -500);

        $this->assertSame(QuoteEditor::DEFAULT_DISCOUNT_LABEL, QuoteEditor::discountLine($priced)?->label);
    }

    public function testTheQuotesOtherFactsAreKept(): void
    {
        $priced = $this->editor->withDiscount($this->tariff(), 'Remise', -500);

        $this->assertSame(3, $priced->nights);
        $this->assertSame(20, $priced->persons);
        $this->assertSame(BillingUnit::PER_NIGHT, $priced->billingUnit);
    }
}
