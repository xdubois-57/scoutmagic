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
     */
    private function render(?array $routeBreadcrumb, string $currentPath = '/some-page', ?string $breadcrumbCurrent = null): string
    {
        $context = [
            'route_breadcrumb' => $routeBreadcrumb,
            'current_path' => $currentPath,
        ];
        if ($breadcrumbCurrent !== null) {
            $context['breadcrumb_current'] = $breadcrumbCurrent;
        }

        return $this->twig->render('partials/breadcrumb_bar.html.twig', $context);
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
            ['label' => 'Staffs', 'parents' => ['Espace des chefs']],
            '/chefs/staffs'
        );

        $this->assertStringContainsString('Espace des chefs', $html);
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

    public function testParentSegmentsAreNotClickable(): void
    {
        $html = $this->render(
            ['label' => 'Staffs', 'parents' => ['Espace des chefs']],
            '/chefs/staffs'
        );

        // The parent label must appear as plain text, never inside an <a>.
        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-body-secondary">Espace des chefs<\/li>/',
            $html
        );
    }

    public function testBreadcrumbCurrentOverridesStaticLabel(): void
    {
        $html = $this->render(
            ['label' => 'Membre', 'parents' => ['Espace des animés']],
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
