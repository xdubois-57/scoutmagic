<?php

declare(strict_types=1);

namespace Tests\Modules\Finance;

use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/**
 * Full-page render of modules/finance/views/movements/list.html.twig.
 *
 * Regression coverage for the inert mobile cards: the page renders the
 * movements twice (desktop <tr class="movement-row">, mobile card
 * <button class="movement-row">), but the inline script used to bind
 * click handlers via querySelectorAll('tr.movement-row') — table rows
 * only. The mobile cards showed a pointer cursor and did nothing, so a
 * phone user could not categorize or comment a movement. The fix is a
 * single delegated listener resolving e.target.closest('.movement-row'),
 * which covers both views, and a card that is a real <button> so it is
 * reachable and activable from the keyboard with nothing added.
 */
class MovementsListRenderingTest extends TestCase
{
    private function render(): string
    {
        $templateDir = dirname(__DIR__, 3) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 3) . '/modules/finance/views';
        $twig = TwigFactory::create($templateDir, true, ['finance' => $moduleViews]);
        $twig->addGlobal('site_name', 'Test Unité');
        $twig->addGlobal('menus', null);
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@example.com');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('current_path', '/finance/movements');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('csp_nonce', 'n');
        $twig->addGlobal('effective_scout_year_id', 1);

        $account = (object) ['id' => 1, 'name' => 'Compte courant'];
        $movement = (object) [
            'id' => 42,
            'transactionDate' => '2026-08-01',
            'label' => 'VIREMENT SEPA',
            'bankReference' => 'REF-1',
            'amount' => -12.5,
            'source' => 'import',
            'categoryId' => null,
            'categorySource' => null,
            'comment' => '',
            'fiscalYearId' => 1,
            'counterpartyName' => 'Boulangerie',
            'counterpartyAccount' => 'BE00 0000 0000 0000',
            'extraDetails' => '',
            'isCategoryAutoAssigned' => false,
        ];

        return $twig->render('@finance/movements/list.html.twig', [
            'no_accounts' => false,
            'accounts' => [$account],
            'selected_account' => $account,
            'fiscal_years' => [(object) ['id' => 1, 'label' => '2025-2026']],
            'filter_fiscal_year_id' => null,
            'categories' => [(object) ['id' => 3, 'name' => 'Intendance']],
            'categories_by_id' => [3 => (object) ['id' => 3, 'name' => 'Intendance']],
            'filter_category_id' => 'all',
            'filter_search' => '',
            'rows' => [[
                'movement' => $movement,
                'counterparty' => 'Boulangerie',
                'description' => 'VIREMENT SEPA',
                'attachment_count' => 0,
                'suggested_receipt' => null,
            ]],
            'total_pages' => 1,
            'page' => 1,
            'pending_receipts' => [],
        ]);
    }

    public function testBothViewsRenderRowsWithTheMovementRowClass(): void
    {
        $html = $this->render();

        // Mobile card variant.
        $this->assertMatchesRegularExpression(
            '/<button[^>]*class="[^"]*\bmovement-row\b[^"]*"/s',
            $html
        );
        // Desktop table row variant.
        $this->assertMatchesRegularExpression(
            '/<tr[^>]*class="[^"]*\bmovement-row\b[^"]*"/s',
            $html
        );
    }

    public function testMobileCardIsKeyboardReachable(): void
    {
        $html = $this->render();

        // A real <button>, not a div wearing role="button" and a tabindex:
        // focus, Enter and Space then come with the element (Sonar
        // Web:S6819).
        $this->assertMatchesRegularExpression(
            '/<button type="button"\s+class="[^"]*\bmovement-row\b[^"]*"/s',
            $html
        );
        $this->assertStringNotContainsString('role="button"', $html);
        // The pointer affordance lives on the class now, not on an inline style.
        $this->assertStringNotContainsString('style="cursor:pointer', $html);
        $this->assertStringNotContainsString('style="cursor: pointer', $html);
        $this->assertStringContainsString('.movement-row { cursor: pointer; }', $html);
    }

    public function testClickHandlingIsDelegatedAndNotBoundToTableRowsOnly(): void
    {
        $html = $this->render();

        // The old selector only matched the desktop <tr> rows, leaving
        // mobile cards inert — it must not come back.
        $this->assertStringNotContainsString('tr.movement-row', $html);
        // The handlers themselves moved out of this template into
        // public/assets/js/finance-movements.js, where Vitest can reach
        // them: tests/js/finance-movements.test.js opens the dialog from a
        // desktop <tr> AND from a mobile <div> card, and covers Enter and
        // Space. What this page still has to prove is that it loads the
        // script that carries them.
        // Through the asset() cache-busting helper, so the src carries ?v=….
        $this->assertMatchesRegularExpression('~<script src="/assets/js/finance-movements\.js\?v=[^"]+" defer></script>~', $html);
        // And that both views still carry the class the delegation resolves.
        $this->assertMatchesRegularExpression('~<tr[^>]*class="[^"]*\bmovement-row\b~', $html);
        $this->assertMatchesRegularExpression('~<button[^>]*class="[^"]*\bmovement-row\b~s', $html);
    }
}
