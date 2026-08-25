<?php

declare(strict_types=1);

namespace Tests\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * modules/finance/views/_nav.html.twig — the page picker (6 static
 * finance pages) and the account picker (dynamic accounts list).
 *
 * They are deliberately two different components now. The pages are a
 * fixed set declared in code with short labels, so they are a nav rail
 * (through partials/page_picker.html.twig); the accounts are an
 * open-ended list from the database, so they are a select bar. See
 * docs/module-development.md for the rule.
 */
class FinanceNavRenderingTest extends TestCase
{
    private Environment $twig;

    protected function setUp(): void
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 3) . '/modules/finance/views';
        $loader = new FilesystemLoader($templateDir);
        $loader->addPath($moduleViews, 'finance');

        $this->twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function render(array $context): string
    {
        return $this->twig->render('@finance/_nav.html.twig', array_merge([
            'current_path' => '/finance',
            'accounts' => [],
        ], $context));
    }

    public function testPagePickerIsANavRailNotTheOldHorizontalScrollRow(): void
    {
        $html = $this->render(['current_path' => '/finance']);

        $this->assertStringContainsString('id="finance-page-picker"', $html);
        // Bootstrap's own underlined tabs. flex-nowrap IS the rail here
        // (it is what stops the tabs wrapping to a second line), which is
        // the opposite of what the raw overflow-x-auto button row this
        // replaced was doing — that one had no scroll affordance at all.
        $this->assertStringContainsString('nav nav-underline', $html);
        $this->assertStringContainsString('flex-nowrap', $html);
        $this->assertStringNotContainsString('overflow-x-auto', $html);
        // A rail never folds anything away behind an overflow control.
        $this->assertStringNotContainsString('offcanvas', $html);
        $this->assertStringContainsString('href="/finance/movements"', $html);
    }

    public function testPagePickerHighlightsTheCurrentPage(): void
    {
        $html = $this->render(['current_path' => '/finance/movements']);

        $this->assertMatchesRegularExpression(
            '/href="\/finance\/movements"[^>]*aria-current="page"/s',
            $html
        );
        $this->assertSame(1, substr_count($html, 'aria-current="page"'));
    }

    /**
     * This nav deliberately does not pass `match_prefix`, so a sub-route
     * selects no tab at all rather than its parent — unchanged from when
     * this was a chip picker. The point being pinned is the one that
     * would be a real bug: /finance/movements/12 must never light up
     * « Tableau de bord » for happening to sit under /finance.
     */
    public function testASubRouteNeverSelectsTheParentPage(): void
    {
        $html = $this->render(['current_path' => '/finance/movements/12']);

        $this->assertStringNotContainsString('aria-current="page"', $html);
    }

    public function testAccountPickerHiddenWhenFlagSet(): void
    {
        $html = $this->render([
            'accounts' => [(object) ['id' => 1, 'name' => 'Compte courant']],
            'hide_account_picker' => true,
        ]);

        $this->assertStringNotContainsString('finance-account-picker', $html);
    }

    public function testAccountPickerIsAChipPickerAndHighlightsTheSelectedAccount(): void
    {
        $html = $this->render([
            'accounts' => [
                (object) ['id' => 1, 'name' => 'Compte courant'],
                (object) ['id' => 2, 'name' => 'Compte épargne'],
            ],
            'selected_account' => (object) ['id' => 2, 'name' => 'Compte épargne'],
        ]);

        $this->assertStringContainsString('id="finance-account-picker"', $html);
        $this->assertStringContainsString('data-mode="single"', $html);
        $this->assertStringNotContainsString('overflow-x-auto', $html);
        $this->assertStringContainsString('href="/finance?account_id=2"', $html);
    }
}
