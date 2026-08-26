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
 * Nav rail (partials/nav_rail.html.twig) — one of the site's two
 * selection components, for moving between the fixed sub-pages of one
 * page. See docs/module-development.md for the full contract.
 *
 * Bootstrap's own `nav nav-underline` + `flex-nowrap` + `overflow-auto`,
 * never wrapped and never folded: the rail hides nothing, so there is no
 * "+N" and no client-side measurement to test around. Selection is a
 * plain <a href>, so the rail works with no JavaScript at all —
 * nav-rail.js only scrolls the current tab into view.
 */
class NavRailRenderingTest extends TestCase
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
    private function render(array $items, array $extra = []): string
    {
        return $this->twig->render('partials/nav_rail.html.twig', array_merge([
            'picker_id' => 'test-rail',
            'items' => $items,
            'base_url' => '',
        ], $extra));
    }

    public function testRendersBootstrapUnderlineTabsThatNeverWrap(): void
    {
        $html = $this->render([
            ['id' => '/finance', 'label' => 'Tableau de bord', 'selected' => true],
            ['id' => '/finance/movements', 'label' => 'Mouvements'],
        ]);

        $this->assertStringContainsString('nav nav-underline', $html);
        $this->assertStringContainsString('flex-nowrap', $html);
        $this->assertStringContainsString('overflow-auto', $html);
    }

    public function testRendersOneLinkPerItemAndHidesNothing(): void
    {
        $items = [];
        for ($i = 1; $i <= 12; $i++) {
            $items[] = ['id' => "/p{$i}", 'label' => "Page {$i}"];
        }

        $html = $this->render($items);

        $this->assertSame(12, substr_count($html, 'class="nav-link'));
        for ($i = 1; $i <= 12; $i++) {
            $this->assertStringContainsString(">Page {$i}<", $html);
        }
        // No fold, no overflow chip, no panel: the rail hides nothing.
        $this->assertStringNotContainsString('+N', $html);
        $this->assertStringNotContainsString('<details', $html);
        $this->assertStringNotContainsString('offcanvas', $html);
    }

    public function testSelectedTabIsMarkedActiveAndAriaCurrentPage(): void
    {
        $html = $this->render([
            ['id' => '/finance', 'label' => 'Tableau de bord', 'selected' => false],
            ['id' => '/finance/movements', 'label' => 'Mouvements', 'selected' => true],
        ]);

        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
        $this->assertSame(1, substr_count($html, ' active'));
        // The marked tab is the selected one, not merely some tab.
        $this->assertMatchesRegularExpression(
            '~href="/finance/movements"[^>]*aria-current="page"~',
            $html
        );
    }

    public function testLinksAreRealHrefsSoNavigationNeedsNoJavaScript(): void
    {
        $html = $this->render([
            ['id' => '/finance', 'label' => 'Tableau de bord'],
        ]);

        $this->assertStringContainsString('href="/finance"', $html);
        $this->assertStringNotContainsString('<button', $html);
    }

    public function testAppendsExtraQueryToEveryLink(): void
    {
        $html = $this->render([
            ['id' => '/finance', 'label' => 'Tableau de bord'],
            ['id' => '/finance/movements', 'label' => 'Mouvements'],
        ], ['extra_query' => '?account_id=3']);

        $this->assertStringContainsString('href="/finance?account_id=3"', $html);
        $this->assertStringContainsString('href="/finance/movements?account_id=3"', $html);
    }

    public function testCombinesBaseUrlAndNumericIdForAFilterStyleRail(): void
    {
        // The camps status filter's shape: a base_url plus a short code.
        $html = $this->render([
            ['id' => '', 'label' => 'Tous', 'selected' => true],
            ['id' => 'confirme', 'label' => 'Confirmé'],
        ], ['base_url' => '/chefs/camps/lieux/4?statut=']);

        $this->assertStringContainsString('href="/chefs/camps/lieux/4?statut="', $html);
        $this->assertStringContainsString('href="/chefs/camps/lieux/4?statut=confirme"', $html);
    }

    public function testZeroItemsRendersNothingAtAll(): void
    {
        $this->assertSame('', trim($this->render([])));
    }

    public function testOptionalColourDotRendersWhenProvided(): void
    {
        $withDot = $this->render([['id' => '/a', 'label' => 'A', 'color' => '#4a90d9']]);
        $without = $this->render([['id' => '/a', 'label' => 'A']]);

        $this->assertStringContainsString('background-color:#4a90d9', $withDot);
        $this->assertStringNotContainsString('background-color:', $without);
    }

    public function testNavLandmarkIsNamed(): void
    {
        $html = $this->render([['id' => '/a', 'label' => 'A']], ['aria_label' => 'Pages Finances']);

        $this->assertStringContainsString('aria-label="Pages Finances"', $html);
    }

    /**
     * design.md §7.2 — touch sizing lives in app.css's `pointer: coarse`
     * block and nowhere else.
     */
    public function testTemplateCarriesNoInlineTouchSizePatch(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/core/View/templates/partials/nav_rail.html.twig'
        );

        $this->assertStringNotContainsString('min-height:44px', $template);
        $this->assertStringNotContainsString('min-height: 44px', $template);
    }

    public function testTabsTakeTheirHeightFromTheSharedTapTargetClass(): void
    {
        $html = $this->render([['id' => '/a', 'label' => 'A']]);

        $this->assertMatchesRegularExpression('/<a[^>]*\btap-target\b/', $html);
    }

    /**
     * design.md §7.8 — dark mode is live.
     */
    public function testUsesSemanticColourUtilitiesOnly(): void
    {
        $template = (string) file_get_contents(
            dirname(__DIR__, 3) . '/core/View/templates/partials/nav_rail.html.twig'
        );

        $this->assertStringNotContainsString('bg-white', $template);
        $this->assertStringNotContainsString('text-dark', $template);
    }
}
