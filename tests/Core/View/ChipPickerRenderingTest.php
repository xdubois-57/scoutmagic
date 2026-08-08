<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Core, content-agnostic chip picker (partials/chip_picker.html.twig) —
 * see docs/module-development.md for the full contract. Covers everything
 * verifiable server-side; the 2-line truncation, "+N" overflow chip, and
 * selected-items-stay-visible behavior are pure client-side DOM
 * measurement (public/assets/js/chip-picker.js) with no server equivalent
 * to assert on here — this partial always renders every item,
 * unconditionally, by design (progressive enhancement: truncation must
 * still work with JS disabled).
 */
class ChipPickerRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $extra
     */
    private function render(array $items, string $mode = 'single', array $extra = []): string
    {
        return $this->twig->render('partials/chip_picker.html.twig', array_merge([
            'picker_id' => 'test-picker',
            'items' => $items,
            'mode' => $mode,
            'base_url' => '/x?y=',
        ], $extra));
    }

    public function testRendersOneChipPerItem(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'Louveteaux'],
            ['id' => 2, 'label' => 'Éclaireurs'],
            ['id' => 3, 'label' => 'Pionniers'],
        ]);

        $this->assertSame(3, substr_count($html, 'chip-picker-item chip-picker-chip'));
        $this->assertStringContainsString('Louveteaux', $html);
        $this->assertStringContainsString('Éclaireurs', $html);
        $this->assertStringContainsString('Pionniers', $html);
    }

    public function testNoServerSideOverflowChipEverRendered(): void
    {
        // Truncation is entirely client-side (progressive enhancement) —
        // the server must never render a "+N" chip itself, regardless of
        // item count, or a no-JS visitor would lose access to hidden items.
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = ['id' => $i, 'label' => "Item {$i}"];
        }

        $html = $this->render($items);

        $this->assertSame(12, substr_count($html, 'chip-picker-item chip-picker-chip'));
        $this->assertStringNotContainsString('chip-picker-overflow', $html);
    }

    public function testSingleModeChipAndSheetRowShareTheExactSameHref(): void
    {
        // "Chemin de sélection unique chip / sheet" for mode:single: both
        // must be the literal same link, not two implementations.
        $html = $this->render([
            ['id' => 42, 'label' => 'Éclaireurs', 'selected' => false],
        ], 'single', ['base_url' => '/chefs/staffs?section=']);

        $this->assertSame(2, substr_count($html, 'href="/chefs/staffs?section=42"'));
    }

    public function testSingleModeAppendsExtraQueryToBothChipAndSheetRow(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'Mes évènements'],
        ], 'single', ['base_url' => '/calendar?calendar=', 'extra_query' => '&month=2026-08']);

        // "&" is HTML-escaped by Twig's autoescape — correct for an href attribute.
        $this->assertSame(2, substr_count($html, 'href="/calendar?calendar=1&amp;month=2026-08"'));
    }

    public function testSelectedSingleItemGetsBtnPrimaryAndAriaCurrent(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'A', 'selected' => true],
            ['id' => 2, 'label' => 'B', 'selected' => false],
        ]);

        $this->assertStringContainsString('btn-primary', $html);
        $this->assertStringContainsString('aria-current="true"', $html);
        $this->assertStringContainsString('btn-outline-secondary', $html);
    }

    public function testMultiModeRendersButtonsNotLinks(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'Calendrier A', 'selected' => true],
            ['id' => 2, 'label' => 'Calendrier B', 'selected' => false],
        ], 'multi');

        $this->assertStringNotContainsString('<a href', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('data-selected="true"', $html);
        $this->assertStringContainsString('data-selected="false"', $html);
    }

    public function testMultiModeSelectableButtonsNeverAutoDismissTheSheetButTheCloseButtonDoes(): void
    {
        // "La sheet reste ouverte" on a chip/row toggle, "plus un bouton de
        // fermeture explicite" — Bootstrap only auto-dismisses an offcanvas
        // via data-bs-dismiss, so the selectable rows must never carry it
        // while the dedicated close button must.
        $html = $this->render([
            ['id' => 1, 'label' => 'A', 'selected' => false],
        ], 'multi');

        // Every <button ...>-opening chunk belonging to a selectable chip
        // or sheet row (identified by the chip-picker-item class) must not
        // carry data-bs-dismiss, so a toggle never auto-closes the sheet.
        foreach (explode('<button', $html) as $chunk) {
            $tag = strstr($chunk, '>', true) ?: $chunk;
            if (str_contains($tag, 'chip-picker-item')) {
                $this->assertStringNotContainsString('data-bs-dismiss', $tag);
            }
        }

        $this->assertMatchesRegularExpression(
            '/chip-picker-sheet-close" data-bs-dismiss="offcanvas"/',
            $html
        );
    }

    public function testSingleModeHasNoCloseButtonInTheSheet(): void
    {
        // Single mode: tap selects AND navigates away, which already
        // "closes" the sheet — no separate close button is meaningful.
        $html = $this->render([
            ['id' => 1, 'label' => 'A'],
        ], 'single');

        $this->assertStringNotContainsString('chip-picker-sheet-close', $html);
    }

    public function testSublabelAndBadgeNeverAppearOnTheChipOnlyInTheSheet(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'Éclaireurs', 'sublabel' => 'Troupe', 'badge' => 'Non configurée'],
        ]);

        [$chipsHtml, $sheetHtml] = explode('chip-picker-sheet', $html, 2);

        $this->assertStringNotContainsString('Troupe', $chipsHtml);
        $this->assertStringNotContainsString('Non configurée', $chipsHtml);
        $this->assertStringContainsString('Troupe', $sheetHtml);
        $this->assertStringContainsString('Non configurée', $sheetHtml);
    }

    public function testColorDotRendersOnBothChipAndSheetRowWhenProvided(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'A', 'color' => '#f5a623'],
        ]);

        $this->assertSame(2, substr_count($html, 'background-color:#f5a623'));
    }

    public function testNoColorDotWhenNotProvided(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'A'],
        ]);

        $this->assertStringNotContainsString('background-color:', $html);
    }

    public function testEmptyItemsRendersEmptyTextAndNoSheet(): void
    {
        $html = $this->render([], 'single', ['empty_text' => 'Aucun élément disponible.']);

        $this->assertStringContainsString('Aucun élément disponible.', $html);
        $this->assertStringNotContainsString('offcanvas', $html);
        $this->assertStringNotContainsString('btn-primary', $html);
    }

    public function testEmptyItemsFallsBackToDefaultEmptyText(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('Aucun élément.', $html);
    }

    public function testTouchTargetsAreAtLeast44px(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'A'],
        ], 'multi');

        $this->assertStringContainsString('min-height:44px', $html);
    }

    public function testMultiplePickerInstancesGetDistinctSheetIds(): void
    {
        $htmlA = $this->render([['id' => 1, 'label' => 'A']], 'single', ['picker_id' => 'picker-a']);
        $htmlB = $this->render([['id' => 1, 'label' => 'A']], 'single', ['picker_id' => 'picker-b']);

        $this->assertStringContainsString('id="picker-a-sheet"', $htmlA);
        $this->assertStringContainsString('id="picker-b-sheet"', $htmlB);
        $this->assertStringContainsString('data-sheet-target="#picker-a-sheet"', $htmlA);
        $this->assertStringContainsString('data-sheet-target="#picker-b-sheet"', $htmlB);
    }

    public function testSheetTitleDefaultsWhenNotProvided(): void
    {
        $html = $this->render([['id' => 1, 'label' => 'A']]);

        $this->assertStringContainsString('Choisir', $html);
    }
}
