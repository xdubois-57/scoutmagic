<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * Select bar (partials/select_bar.html.twig) — one of the site's two
 * selection components, for picking a piece of data. See
 * docs/module-development.md for the full contract.
 *
 * The load-bearing property here is that the component needs no
 * JavaScript to be operable: the panel is a native <details>, and in
 * mode 'single' its rows are plain <a href>. /calendar, /trombinoscope
 * and /notifications are in Core\Offline\OfflineWhitelist, so a selector
 * that needed JS to show or choose its options would be broken offline.
 * Several tests below exist only to pin that down.
 */
class SelectBarRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $extra
     */
    private function render(array $items, string $mode = 'single', array $extra = []): string
    {
        return $this->twig->render('partials/select_bar.html.twig', array_merge([
            'picker_id' => 'test-bar',
            'label' => 'Section',
            'items' => $items,
            'mode' => $mode,
            'base_url' => '/x?y=',
        ], $extra));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function items(int $count): array
    {
        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = ['id' => $i, 'label' => "Item {$i}"];
        }

        return $items;
    }

    public function testPanelIsANativeDetailsNotAnOffcanvasOrModal(): void
    {
        // Decision: a Bootstrap offcanvas would silently lose the JS-off
        // guarantee — with JS disabled the panel could never open at all.
        $html = $this->render($this->items(3));

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('<summary', $html);
        $this->assertStringNotContainsString('offcanvas', $html);
        $this->assertStringNotContainsString('data-bs-toggle', $html);
        $this->assertStringNotContainsString('<select', $html);
    }

    public function testSingleModeRowsAreRealLinksSoSelectionNeedsNoJavaScript(): void
    {
        $html = $this->render([
            ['id' => 42, 'label' => 'Éclaireurs'],
            ['id' => 43, 'label' => 'Pionniers'],
        ], 'single', ['base_url' => '/chefs/staffs?section=']);

        $this->assertStringContainsString('href="/chefs/staffs?section=42"', $html);
        $this->assertStringContainsString('href="/chefs/staffs?section=43"', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function testSingleModeAppendsExtraQueryToEveryRow(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'Mes évènements'],
            ['id' => 2, 'label' => 'Unité'],
        ], 'single', ['base_url' => '/calendar?calendar=', 'extra_query' => '&month=2026-08']);

        // "&" is HTML-escaped by Twig's autoescape — correct in an href.
        $this->assertStringContainsString('href="/calendar?calendar=1&amp;month=2026-08"', $html);
        $this->assertStringContainsString('href="/calendar?calendar=2&amp;month=2026-08"', $html);
    }

    public function testSelectedRowCarriesAriaCurrent(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'A', 'selected' => true],
            ['id' => 2, 'label' => 'B', 'selected' => false],
        ]);

        $this->assertSame(1, substr_count($html, 'aria-current="true"'));
    }

    public function testEveryItemIsRenderedServerSideWithNoFoldAndNoOverflowAffordance(): void
    {
        // No "+N", no client-side fold, no post-render measurement: the
        // whole point of this component is that it hides nothing.
        $html = $this->render($this->items(30));

        for ($i = 1; $i <= 30; $i++) {
            $this->assertStringContainsString(">Item {$i}<", $html);
        }
        $this->assertStringNotContainsString('+N', $html);
        $this->assertStringNotContainsString('overflow', $html);
    }

    public function testTriggerShowsTheLabelCaptionAndTheSelectedValue(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'Louveteaux', 'selected' => true],
            ['id' => 2, 'label' => 'Éclaireurs'],
        ], 'single', ['label' => 'Section']);

        $summary = substr($html, (int) strpos($html, '<summary'), (int) strpos($html, '</summary>') - (int) strpos($html, '<summary'));

        $this->assertStringContainsString('Section', $summary);
        $this->assertStringContainsString('Louveteaux', $summary);
        $this->assertStringContainsString('bi-chevron-down', $summary);
    }

    public function testTriggerFallsBackToTheNoneSelectedTextWhenNothingIsSelected(): void
    {
        $html = $this->render($this->items(3), 'single', ['none_selected_text' => 'Choisir une section']);

        $this->assertStringContainsString('Choisir une section', $html);
    }

    public function testZeroItemsRendersEmptyTextAndNoControlAtAll(): void
    {
        $html = $this->render([], 'single', ['empty_text' => 'Aucune section disponible.']);

        $this->assertStringContainsString('Aucune section disponible.', $html);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('<summary', $html);
    }

    public function testZeroItemsFallsBackToADefaultEmptyText(): void
    {
        $html = $this->render([]);

        $this->assertStringContainsString('Aucun élément.', $html);
    }

    public function testSingleItemInSingleModeRendersAsStaticTextWithNoChevronAndNoDetails(): void
    {
        // Nothing to choose from is not a control: navigating to the only
        // option is a no-op.
        $html = $this->render([['id' => 7, 'label' => 'Louveteaux']], 'single');

        $this->assertStringContainsString('Louveteaux', $html);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('bi-chevron-down', $html);
        $this->assertStringNotContainsString('<a href', $html);
    }

    /**
     * The static-text rule is about having nothing to choose. In multi
     * mode a lone item still has two states — assigned or not — so it
     * keeps its control; rendering it as static text would make a
     * one-badge unit unable to assign that badge at all.
     */
    public function testSingleItemInMultiModeKeepsItsControl(): void
    {
        $html = $this->render([['id' => 7, 'label' => 'Infirmier']], 'multi');

        $this->assertStringContainsString('<details', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
    }

    public function testMultiModeRendersToggleButtonsRatherThanLinks(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'Infirmier', 'selected' => true],
            ['id' => 2, 'label' => 'Trésorier', 'selected' => false],
        ], 'multi');

        $this->assertStringNotContainsString('<a href', $html);
        $this->assertStringContainsString('aria-pressed="true"', $html);
        $this->assertStringContainsString('aria-pressed="false"', $html);
        $this->assertStringContainsString('data-selected="true"', $html);
        $this->assertStringContainsString('data-selected="false"', $html);
    }

    public function testMultiModeTriggerSummarisesTheSelectionRatherThanDrawingEveryPick(): void
    {
        $twoPicked = $this->render([
            ['id' => 1, 'label' => 'Infirmier', 'selected' => true],
            ['id' => 2, 'label' => 'Trésorier', 'selected' => true],
            ['id' => 3, 'label' => 'Nage', 'selected' => false],
        ], 'multi', ['count_label' => 'badges', 'none_selected_text' => 'Aucun badge']);

        $onePicked = $this->render([
            ['id' => 1, 'label' => 'Infirmier', 'selected' => true],
            ['id' => 2, 'label' => 'Trésorier', 'selected' => false],
        ], 'multi', ['count_label' => 'badges', 'none_selected_text' => 'Aucun badge']);

        $nonePicked = $this->render([
            ['id' => 1, 'label' => 'Infirmier', 'selected' => false],
        ], 'multi', ['count_label' => 'badges', 'none_selected_text' => 'Aucun badge']);

        $this->assertStringContainsString('>2 badges<', $twoPicked);
        $this->assertStringContainsString('>Infirmier</span>', $onePicked);
        $this->assertStringContainsString('>Aucun badge<', $nonePicked);
    }

    /**
     * select-bar.js rebuilds the trigger after a toggle. Both summary
     * texts must reach it from the server, so no user-facing French is
     * ever invented in JavaScript.
     */
    public function testTheTwoSummaryTextsTravelToTheClientAsDataAttributes(): void
    {
        $html = $this->render($this->items(2), 'multi', [
            'count_label' => 'badges',
            'none_selected_text' => 'Aucun badge',
        ]);

        $this->assertStringContainsString('data-none-text="Aucun badge"', $html);
        $this->assertStringContainsString('data-count-label="badges"', $html);
    }

    public function testSublabelBadgeAndColourDotAllRenderInThePanel(): void
    {
        $html = $this->render([
            ['id' => 1, 'label' => 'Éclaireurs', 'sublabel' => 'Troupe', 'badge' => 'Non configurée', 'color' => '#f5a623'],
            ['id' => 2, 'label' => 'Pionniers'],
        ]);

        $this->assertStringContainsString('Troupe', $html);
        $this->assertStringContainsString('Non configurée', $html);
        $this->assertStringContainsString('background-color:#f5a623', $html);
    }

    public function testNoColourDotWhenNoItemProvidesOne(): void
    {
        $html = $this->render($this->items(3));

        $this->assertStringNotContainsString('background-color:', $html);
    }

    public function testTwoInstancesOnOnePageGetDistinctDomIds(): void
    {
        $a = $this->render($this->items(2), 'single', ['picker_id' => 'bar-a']);
        $b = $this->render($this->items(2), 'single', ['picker_id' => 'bar-b']);

        $this->assertStringContainsString('id="bar-a"', $a);
        $this->assertStringContainsString('id="bar-b"', $b);
    }

    /**
     * design.md §7.2 — touch sizing lives in app.css's `pointer: coarse`
     * block and nowhere else. The component this replaced carried five
     * inline patches; they are not coming back through the new one.
     */
    public function testTemplateCarriesNoInlineTouchSizePatch(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/core/View/templates/partials/select_bar.html.twig'
        );

        $this->assertStringNotContainsString('min-height:44px', $template);
        $this->assertStringNotContainsString('min-height: 44px', $template);
    }

    public function testTriggerTakesItsHeightFromTheSharedTapTargetClass(): void
    {
        $html = $this->render($this->items(3));

        $this->assertMatchesRegularExpression('/<summary[^>]*\btap-target\b/', $html);
    }

    /**
     * design.md §7.8 — dark mode is live; bg-white/text-dark are the same
     * colour in both themes and produce black-on-black.
     */
    public function testUsesSemanticColourUtilitiesOnly(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/core/View/templates/partials/select_bar.html.twig'
        );

        $this->assertStringNotContainsString('bg-white', $template);
        $this->assertStringNotContainsString('text-dark', $template);
    }
}
