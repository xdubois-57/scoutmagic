<?php

declare(strict_types=1);

namespace Tests\Modules\Finance;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * modules/finance/views/_nav.html.twig — the page picker (5 static
 * finance pages) and the account picker (dynamic accounts list) both
 * used to be raw overflow-x-auto/flex-nowrap button rows with no scroll
 * affordance on mobile. Migrated to partials/chip_picker.html.twig
 * (mode: single), same component/style as the section and calendar
 * pickers — see ARCHITECTURE.md §8.30.
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

    public function testPagePickerIsAChipPickerNotAHorizontalScrollRow(): void
    {
        $html = $this->render(['current_path' => '/finance']);

        $this->assertStringContainsString('id="finance-page-picker"', $html);
        $this->assertStringContainsString('data-mode="single"', $html);
        $this->assertStringNotContainsString('overflow-x-auto', $html);
        $this->assertStringNotContainsString('flex-nowrap', $html);
    }

    public function testPagePickerHighlightsTheCurrentPage(): void
    {
        $html = $this->render(['current_path' => '/finance/movements']);

        $this->assertMatchesRegularExpression(
            '/href="\/finance\/movements"[^>]*btn-primary/s',
            $html
        );
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
