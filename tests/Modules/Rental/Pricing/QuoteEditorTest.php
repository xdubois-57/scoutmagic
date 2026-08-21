<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Pricing;

use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\QuoteEditor;
use PHPUnit\Framework\TestCase;

/**
 * Hand-edited price lines (§6.12).
 *
 * The rule under test throughout: **a line a manager touched is never
 * recalculated again**. A manager who negotiated 300 € for a week and then
 * corrects the arrival date must not find their 300 € quietly replaced by
 * the grid's answer.
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
    private function quote(array $lines, int $nights = 3, int $persons = 20): PriceQuote
    {
        $total = 0;
        foreach ($lines as $line) {
            if (!$line->isInformational) {
                $total += $line->amountCents;
            }
        }

        return new PriceQuote(
            lines: $lines,
            totalCents: $total,
            nights: $nights,
            persons: $persons,
            quantity: $nights,
            billingUnit: BillingUnit::PER_NIGHT
        );
    }

    private function baseLine(int $amount = 36000, int $quantity = 3): PriceLine
    {
        return new PriceLine('3 nuits', $quantity, 12000, $amount, PriceLine::RULE_BASE);
    }

    private function feeLine(int $amount = 2500): PriceLine
    {
        return new PriceLine('Forfait nettoyage', 1, null, $amount, PriceLine::RULE_FEE_FIXED);
    }

    // ── Editing ─────────────────────────────────────────────────────────

    public function testEditingALineMarksItManual(): void
    {
        $edited = $this->editor->editLine($this->quote([$this->baseLine()]), 0, null, null, 30000);

        $this->assertTrue($edited->lines[0]->isManual);
        $this->assertSame(30000, $edited->lines[0]->amountCents);
    }

    public function testTypingAnAmountDropsTheUnitPriceSoTheLineStaysArithmeticallyHonest(): void
    {
        // Keeping "3 × 120,00 €" next to an amount of 300,00 € would show a
        // line whose own arithmetic does not work.
        $edited = $this->editor->editLine($this->quote([$this->baseLine()]), 0, null, null, 30000);

        $this->assertNull($edited->lines[0]->unitPriceCents);
    }

    public function testAnUnchangedAmountKeepsTheUnitPrice(): void
    {
        $edited = $this->editor->editLine($this->quote([$this->baseLine()]), 0, 'Trois nuits', null, null);

        $this->assertSame(12000, $edited->lines[0]->unitPriceCents);
        $this->assertSame('Trois nuits', $edited->lines[0]->label);
    }

    public function testANullFieldLeavesThatFieldAlone(): void
    {
        $edited = $this->editor->editLine($this->quote([$this->baseLine()]), 0, null, null, null);

        $this->assertSame('3 nuits', $edited->lines[0]->label);
        $this->assertSame(3, $edited->lines[0]->quantity);
        $this->assertSame(36000, $edited->lines[0]->amountCents);
    }

    public function testAnEmptyLabelIsIgnoredRatherThanBlankingTheLine(): void
    {
        $edited = $this->editor->editLine($this->quote([$this->baseLine()]), 0, '   ', null, null);

        $this->assertSame('3 nuits', $edited->lines[0]->label);
    }

    public function testEditingAnIndexThatDoesNotExistChangesNothing(): void
    {
        $quote = $this->quote([$this->baseLine()]);

        $this->assertEquals($quote, $this->editor->editLine($quote, 7, 'X', 1, 100));
    }

    public function testTheTotalIsRecomputedFromTheLines(): void
    {
        $edited = $this->editor->editLine($this->quote([$this->baseLine(), $this->feeLine()]), 0, null, null, 30000);

        $this->assertSame(32500, $edited->totalCents);
    }

    // ── Adding and removing ─────────────────────────────────────────────

    public function testAFreeLineIsAddedAtTheEndAndMarkedManual(): void
    {
        $edited = $this->editor->addLine($this->quote([$this->baseLine()]), 'Prêt de la remorque', 1, 5000);

        $this->assertCount(2, $edited->lines);
        $this->assertTrue($edited->lines[1]->isManual);
        $this->assertSame(PriceLine::RULE_MANUAL, $edited->lines[1]->rule);
        $this->assertSame(41000, $edited->totalCents);
    }

    public function testANegativeAmountIsAllowedSoADiscountStaysItsOwnVisibleLine(): void
    {
        $edited = $this->editor->addLine($this->quote([$this->baseLine()]), 'Remise exceptionnelle', 1, -5000);

        $this->assertSame(-5000, $edited->lines[1]->amountCents);
        $this->assertSame(31000, $edited->totalCents);
    }

    public function testAnEmptyLabelAddsNothing(): void
    {
        $quote = $this->quote([$this->baseLine()]);

        $this->assertEquals($quote, $this->editor->addLine($quote, '  ', 1, 5000));
    }

    public function testRemovingALineReindexesAndRetotals(): void
    {
        $edited = $this->editor->removeLine($this->quote([$this->baseLine(), $this->feeLine()]), 0);

        $this->assertCount(1, $edited->lines);
        $this->assertArrayHasKey(0, $edited->lines);
        $this->assertSame(2500, $edited->totalCents);
    }

    public function testRemovingAnIndexThatDoesNotExistChangesNothing(): void
    {
        $quote = $this->quote([$this->baseLine()]);

        $this->assertEquals($quote, $this->editor->removeLine($quote, 9));
    }

    // ── Re-quoting: the rule that matters ───────────────────────────────

    public function testRecomputingKeepsEveryManualLineExactlyAsItWas(): void
    {
        $current = $this->editor->addLine(
            $this->editor->editLine($this->quote([$this->baseLine(), $this->feeLine()]), 0, null, null, 30000),
            'Remise négociée',
            1,
            -2000
        );

        // The tariff answers differently now — four nights instead of three.
        $recomputed = $this->quote([$this->baseLine(48000, 4), $this->feeLine()], nights: 4);

        $merged = $this->editor->merge($recomputed, $current);

        $labels = array_map(static fn(PriceLine $l) => $l->label, $merged->lines);
        $this->assertContains('Remise négociée', $labels);

        $manual = array_values(array_filter($merged->lines, static fn(PriceLine $l) => $l->isManual));
        $this->assertCount(2, $manual);
        // The edited base line kept the manager's 300,00 €, not the grid's
        // 480,00 €.
        $this->assertSame(30000, $manual[0]->amountCents);
        $this->assertSame(-2000, $manual[1]->amountCents);
    }

    public function testRecomputingReplacesEveryAutomaticLine(): void
    {
        $current = $this->quote([$this->baseLine(), $this->feeLine()]);
        $recomputed = $this->quote([$this->baseLine(48000, 4)], nights: 4);

        $merged = $this->editor->merge($recomputed, $current);

        $this->assertCount(1, $merged->lines);
        $this->assertSame(48000, $merged->lines[0]->amountCents);
        // The fee disappeared from the tariff, so it disappears from the
        // quote — an automatic line is never kept alive by an old snapshot.
        $this->assertSame(48000, $merged->totalCents);
    }

    public function testRecomputingTakesTheStayFactsFromTheNewQuoteNotTheOldOne(): void
    {
        $current = $this->quote([$this->baseLine()], nights: 3, persons: 20);
        $recomputed = $this->quote([$this->baseLine(48000, 4)], nights: 4, persons: 25);

        $merged = $this->editor->merge($recomputed, $current);

        $this->assertSame(4, $merged->nights);
        $this->assertSame(25, $merged->persons);
    }

    public function testManualLinesLandAfterTheAutomaticOnesInTheirOwnOrder(): void
    {
        $current = $this->editor->addLine(
            $this->editor->addLine($this->quote([$this->baseLine()]), 'Première', 1, 100),
            'Seconde',
            1,
            200
        );

        $merged = $this->editor->merge($this->quote([$this->baseLine(), $this->feeLine()]), $current);

        $labels = array_map(static fn(PriceLine $l) => $l->label, $merged->lines);
        $this->assertSame(['3 nuits', 'Forfait nettoyage', 'Première', 'Seconde'], $labels);
    }

    public function testMergingIntoAQuoteWithNoManualLinesIsJustTheRecomputedQuote(): void
    {
        $recomputed = $this->quote([$this->baseLine(48000, 4)], nights: 4);

        $merged = $this->editor->merge($recomputed, $this->quote([$this->baseLine()]));

        $this->assertEquals($recomputed->lines, $merged->lines);
        $this->assertSame($recomputed->totalCents, $merged->totalCents);
    }

    // ── Informational lines ─────────────────────────────────────────────

    public function testAnInformationalLineNeverEntersTheTotalEvenAfterAnEdit(): void
    {
        // A meter fee is unknowable before the stay; editing its label must
        // not turn it into a billed line.
        $meter = new PriceLine('Électricité', 1, 35, 0, PriceLine::RULE_FEE_METER, isInformational: true);

        $edited = $this->editor->editLine($this->quote([$this->baseLine(), $meter]), 1, 'Électricité (kWh)', null, null);

        $this->assertTrue($edited->lines[1]->isInformational);
        $this->assertSame(36000, $edited->totalCents);
    }

    public function testHasManualLinesReportsWhatTheInterfaceWarnsAbout(): void
    {
        $plain = $this->quote([$this->baseLine()]);

        $this->assertFalse($this->editor->hasManualLines($plain));
        $this->assertTrue($this->editor->hasManualLines($this->editor->editLine($plain, 0, 'X', null, null)));
    }

    public function testAnEditedQuoteSurvivesASnapshotRoundTrip(): void
    {
        // The whole mechanism depends on `isManual` surviving serialisation:
        // the flag lives on the line precisely because that is what gets
        // stored.
        $edited = $this->editor->addLine(
            $this->editor->editLine($this->quote([$this->baseLine()]), 0, null, null, 30000),
            'Remise',
            1,
            -1000
        );

        $restored = PriceQuote::fromArray($edited->toArray());

        $this->assertTrue($restored->lines[0]->isManual);
        $this->assertTrue($restored->lines[1]->isManual);
        $this->assertSame(29000, $restored->totalCents);
    }
}
