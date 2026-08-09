<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class BreadcrumbBarRenderingTest extends TestCase
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
     * @param ?array{label: string, parents: array<string>} $routeBreadcrumb
     * @param array<int, array{label: string, pages: array<int, array<string, mixed>>}> $menus Same shape as
     *   Core\View\MenuBuilder::build() — the breadcrumb partial matches a parent label against menu.label to
     *   decide whether it can link to that menu's first real page.
     */
    private function render(?array $routeBreadcrumb, string $currentPath = '/some-page', ?string $breadcrumbCurrent = null, array $menus = []): string
    {
        $context = [
            'route_breadcrumb' => $routeBreadcrumb,
            'current_path' => $currentPath,
            'menus' => $menus,
        ];
        if ($breadcrumbCurrent !== null) {
            $context['breadcrumb_current'] = $breadcrumbCurrent;
        }

        return $this->twig->render('partials/breadcrumb_bar.html.twig', $context);
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array{label: string, pages: array<int, array<string, mixed>>}
     */
    private function menu(string $label, array $pages): array
    {
        return ['label' => $label, 'pages' => $pages];
    }

    /**
     * @return array<string, mixed>
     */
    private function page(string $url, bool $isDynamic = false): array
    {
        return ['label' => 'x', 'url' => $url, 'isDynamic' => $isDynamic];
    }

    public function testHomeIconAlwaysPresentAndHardcodedToRoot(): void
    {
        $html = $this->render(null);
        $this->assertStringContainsString('href="/"', $html);
        $this->assertStringContainsString('bi-house-door', $html);
    }

    public function testRouteWithBreadcrumbRendersFullTrail(): void
    {
        $html = $this->render(
            ['label' => 'Staffs', 'parents' => ['Espace chefs']],
            '/chefs/staffs'
        );

        $this->assertStringContainsString('Espace chefs', $html);
        $this->assertStringContainsString('Staffs', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    public function testRouteWithoutBreadcrumbStopsAtHomeIconWithoutError(): void
    {
        $html = $this->render(null, '/contact');

        $this->assertStringContainsString('bi-house-door', $html);
        // Only the home <li> — no orphan separator or empty active item.
        $this->assertSame(1, substr_count($html, '<li class="breadcrumb-item'));
        $this->assertStringNotContainsString('aria-current="page"', $html);
    }

    public function testParentSegmentIsPlainTextWhenNoMenuMatchesItsLabel(): void
    {
        // No `menus` supplied at all — nothing to link to, and a parent
        // must never invent a URL (e.g. "/" or "#") for a menu category
        // that has no real landing page of its own.
        $html = $this->render(
            ['label' => 'Staffs', 'parents' => ['Espace chefs']],
            '/chefs/staffs'
        );

        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-body-secondary">Espace chefs<\/li>/',
            $html
        );
    }

    public function testParentSegmentIsClickableWhenItsMenuHasARealFirstPage(): void
    {
        // Viewing Journal, whose parent "Espace chefs d'U" resolves to that
        // menu's first real page — Import Desk, a genuinely different page.
        $html = $this->render(
            ['label' => 'Journal', 'parents' => ['Espace chefs d\'U']],
            '/admin/journal',
            null,
            [$this->menu('Espace chefs d\'U', [$this->page('/admin/import'), $this->page('/admin/journal')])]
        );

        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item"><a href="\/admin\/import"[^>]*>Espace chefs d&#039;U<\/a><\/li>/',
            $html
        );
    }

    public function testParentSegmentIsNotLinkedBackToTheCurrentPageItself(): void
    {
        // Mirrors the real Staffs page: "Espace chefs" currently has
        // only one static page (Staffs itself). Linking the parent back to
        // the page already being viewed would be a dead click, so it falls
        // back to plain text rather than a self-link.
        $html = $this->render(
            ['label' => 'Staffs', 'parents' => ['Espace chefs']],
            '/chefs/staffs',
            null,
            [$this->menu('Espace chefs', [$this->page('/chefs/staffs')])]
        );

        $this->assertStringNotContainsString('<a href="/chefs/staffs"', $html);
        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-body-secondary">Espace chefs<\/li>/',
            $html
        );
    }

    public function testParentSegmentSkipsDynamicAndPlaceholderPagesWhenPickingALink(): void
    {
        // Mirrors Espace animés: dynamic per-member entries first (sorted
        // there by Core\View\MenuBuilder's group-based sort, no separator
        // involved anymore), then a real static page. The link must be the
        // first genuinely navigable page, not the placeholder/dynamic ones
        // ahead of it. Viewing a member page here (not Notifications
        // itself) so the resolved link is a genuinely different page, not
        // a self-link.
        $html = $this->render(
            ['label' => 'Membre', 'parents' => ['Espace animés']],
            '/members/1',
            null,
            [$this->menu('Espace animés', [
                $this->page('#'),
                $this->page('/members/1', true),
                $this->page('/notifications'),
            ])]
        );

        $this->assertMatchesRegularExpression(
            '/<a href="\/notifications"[^>]*>Espace animés<\/a>/',
            $html
        );
    }

    public function testParentSegmentStaysPlainTextWhenItsMenuHasOnlyDynamicOrPlaceholderPages(): void
    {
        // A menu can exist and still have no landing page worth linking to
        // (e.g. Espace animés with no linked members — only the "#"
        // empty-state entry and dynamic per-member ones) — never invented.
        $html = $this->render(
            ['label' => 'Membre', 'parents' => ['Espace animés']],
            '/members/1',
            null,
            [$this->menu('Espace animés', [$this->page('#'), $this->page('/members/1', true)])]
        );

        $this->assertStringNotContainsString('<a href="#"', $html);
        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-body-secondary">Espace animés<\/li>/',
            $html
        );
    }

    public function testCurrentPageSegmentIsNeverALink(): void
    {
        $html = $this->render(
            ['label' => 'Import Desk', 'parents' => ['Espace chefs d\'U']],
            '/admin/import',
            null,
            [$this->menu('Espace chefs d\'U', [$this->page('/admin/import')])]
        );

        $this->assertDoesNotMatchRegularExpression('/<a[^>]*>\s*Import Desk\s*<\/a>/', $html);
    }

    public function testBreadcrumbCurrentOverridesStaticLabel(): void
    {
        $html = $this->render(
            ['label' => 'Membre', 'parents' => ['Espace animés']],
            '/members/42',
            'Jean Dupont'
        );

        $this->assertStringContainsString('Jean Dupont', $html);
        $this->assertStringNotContainsString('>Membre<', $html);
    }

    public function testHomePageHasNoBreadcrumbContentBeyondTheIcon(): void
    {
        $html = $this->render(
            ['label' => 'Accueil', 'parents' => []],
            '/'
        );

        $this->assertSame(1, substr_count($html, '<li class="breadcrumb-item'));
        $this->assertStringNotContainsString('aria-current="page"', $html);
    }

    public function testMissingContextRendersHomeIconOnlyWithoutError(): void
    {
        // Simulates rendering outside FrontController::handle() (e.g. an
        // existing test that renders base.html.twig directly without ever
        // setting the route_breadcrumb global) — must not throw.
        $html = $this->twig->render('partials/breadcrumb_bar.html.twig');

        $this->assertStringContainsString('bi-house-door', $html);
    }
}
