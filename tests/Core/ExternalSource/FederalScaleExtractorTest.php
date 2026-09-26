<?php

declare(strict_types=1);

namespace Tests\Core\ExternalSource;

use Core\ExternalSource\FederalScaleExtractor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The deterministic reading of the federal fees page (issue #355).
 */
final class FederalScaleExtractorTest extends TestCase
{
    /**
     * @return array<string, array{string, int, int, int}>
     */
    public static function pages(): array
    {
        return [
            'decimals optional, as on 24/09/2026' => [
                '<li>Cotisation normale : 57,50 €.</li>'
                    . '<li>Cotisation couple pour deux membres : 46 € par personne.</li>'
                    . '<li>Cotisation familiale pour trois membres : 39 € par personne.</li>',
                5750, 4600, 3900,
            ],
            'last year in parentheses, as in 2025' => [
                '<p>Cotisation normale : 56,25 € (54,25 € en 2024-2025)</p><p>Cotisation couple : 45,25 € (43 €)</p>'
                    . '<p>Cotisation familiale : 38,50 € (37 €)</p>',
                5625, 4525, 3850,
            ],
            'split across tags, non-breaking spaces, one decimal' => [
                '<li><strong>Cotisation normale</strong> : <strong>60&nbsp;€</strong></li>'
                    . '<li><strong>Cotisation couple</strong>&nbsp;: 48,5&nbsp;€</li>'
                    . '<li><strong>Cotisation familiale</strong> : 41 €</li>',
                6000, 4850, 4100,
            ],
        ];
    }

    #[DataProvider('pages')]
    public function testTheThreeAmountsAreRead(string $html, int $normal, int $couple, int $family): void
    {
        $scale = FederalScaleExtractor::extract($html);

        $this->assertNotNull($scale);
        $this->assertSame([$normal, $couple, $family], [$scale->normalCents, $scale->coupleCents, $scale->familyCents]);
    }

    public function testTheOtherAmountsOnThePageAreNeverTaken(): void
    {
        $html = '<p>Cotisation de solidarité : 5 € minimum.</p><p>Cotisation invités : 18,25 €.</p>';

        $this->assertNull(FederalScaleExtractor::extract($html));
    }

    public function testAMissingCategoryMeansNoScale(): void
    {
        $html = '<p>Cotisation normale : 57,50 €</p><p>Cotisation couple : 46 €</p>';

        $this->assertNull(FederalScaleExtractor::extract($html));
    }

    public function testTheYearIsReadFromTheHeading(): void
    {
        $html = '<h3>COTISATIONS 2026 – 2027</h3><p>Cotisation normale : 57,50 €</p>'
            . '<p>Cotisation couple : 46 €</p><p>Cotisation familiale : 39 €</p>';

        $this->assertSame('2026-2027', FederalScaleExtractor::extract($html)?->year);
    }

    /**
     * Around a rollover the page can carry two seasons: the most recent one
     * is read, whichever comes first, and only its own amounts.
     */
    public function testTheMostRecentSeasonIsReadWhateverItsPlace(): void
    {
        $old = '<h3>Cotisations 2025-2026</h3><p>Cotisation normale : 56,25 €</p>'
            . '<p>Cotisation couple : 45 €</p><p>Cotisation familiale : 38 €</p>';
        $new = '<h3>Cotisations 2026-2027</h3><p>Cotisation normale : 57,50 €</p>'
            . '<p>Cotisation couple : 46 €</p><p>Cotisation familiale : 39 €</p>';

        foreach ([$old . $new, $new . $old] as $html) {
            $scale = FederalScaleExtractor::extract($html);
            $this->assertSame('2026-2027', $scale?->year);
            $this->assertSame([5750, 4600, 3900], [$scale->normalCents, $scale->coupleCents, $scale->familyCents]);
        }
    }

    /**
     * The live page's defects, which cut `strip_tags()` short before the
     * amounts — seen again on the first live run of this checker.
     */
    public function testMalformedMarkupBeforeTheAmountsDoesNotHideThem(): void
    {
        $html = '<div class="banner bg--color5""><div class="deco"></div <h3>COTISATIONS 2026-2027</h3>'
            . '<p>Cotisation normale : 57,50 €</p><p>Cotisation couple : 46 €</p>'
            . '<p>Cotisation familiale : 39 €</p></div>';

        $this->assertSame(5750, FederalScaleExtractor::extract($html)?->normalCents);
    }
}
