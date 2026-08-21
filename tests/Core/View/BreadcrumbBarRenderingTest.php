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
     * @param array<int, array{id: string, label: string}> $menus Same shape as
     *   Core\View\MenuBuilder::build() (id/label only matter here) — the breadcrumb
     *   partial matches a parent label against menu.label to decide whether it can
     *   render as a button opening that menu (by id), never a link to a specific page.
     */
    /**
     * @param ?array<int, array{label: string, url: string}> $breadcrumbTrail
     */
    private function render(
        ?array $routeBreadcrumb,
        string $currentPath = '/some-page',
        ?string $breadcrumbCurrent = null,
        array $menus = [],
        ?array $breadcrumbTrail = null
    ): string {
        $context = [
            'route_breadcrumb' => $routeBreadcrumb,
            'current_path' => $currentPath,
            'menus' => $menus,
        ];
        if ($breadcrumbCurrent !== null) {
            $context['breadcrumb_current'] = $breadcrumbCurrent;
        }
        if ($breadcrumbTrail !== null) {
            $context['breadcrumb_trail'] = $breadcrumbTrail;
        }

        return $this->twig->render('partials/breadcrumb_bar.html.twig', $context);
    }

    /**
     * @return array{id: string, label: string}
     */
    private function menu(string $id, string $label): array
    {
        return ['id' => $id, 'label' => $label];
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
        // No `menus` supplied at all — nothing to open, and a parent must
        // never invent a menu id for a label that doesn't actually exist.
        $html = $this->render(
            ['label' => 'Staffs', 'parents' => ['Espace chefs']],
            '/chefs/staffs'
        );

        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-body-secondary">Espace chefs<\/li>/',
            $html
        );
        $this->assertStringNotContainsString('breadcrumb-parent-btn', $html);
    }

    /**
     * A parent whose label matches a real menu is always a button that
     * opens that menu's own section (public/assets/js/breadcrumb.js) —
     * never a link to one arbitrarily-chosen page within it. This holds
     * regardless of what pages the menu has, since the button no longer
     * needs to resolve a landing page at all.
     */
    public function testParentSegmentIsAButtonThatOpensTheMatchingMenu(): void
    {
        $html = $this->render(
            ['label' => 'Journal', 'parents' => ['Espace chefs d\'U']],
            '/admin/journal',
            null,
            [$this->menu('espace_admin', 'Espace chefs d\'U')]
        );

        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item">\s*<button type="button" class="[^"]*breadcrumb-parent-btn[^"]*" data-open-menu="espace_admin">Espace chefs d&#039;U<\/button>\s*<\/li>/',
            $html
        );
    }

    /**
     * Opening the menu the current page already belongs to is a legitimate
     * action now (pick a different sub-page) — unlike the old "link to a
     * landing page" behavior, there is no "dead click"/self-link concern
     * anymore, so the button still renders even when viewing that menu's
     * only page.
     */
    public function testParentButtonStillRendersWhenViewingTheOnlyPageInThatMenu(): void
    {
        $html = $this->render(
            ['label' => 'Staffs', 'parents' => ['Espace chefs']],
            '/chefs/staffs',
            null,
            [$this->menu('espace_chefs', 'Espace chefs')]
        );

        $this->assertMatchesRegularExpression(
            '/data-open-menu="espace_chefs">Espace chefs<\/button>/',
            $html
        );
    }

    /**
     * The button never needs a menu's `pages` at all anymore — matching by
     * label is enough, whether the menu has dynamic entries, a placeholder,
     * or nothing rendered here (Core\View\MenuBuilder::build() never
     * actually emits an empty menu, but the template itself has no reason
     * to depend on `pages` being present to decide button vs. plain text).
     */
    public function testParentButtonRendersRegardlessOfTheMenusPages(): void
    {
        $html = $this->render(
            ['label' => 'Membre', 'parents' => ['Espace animés']],
            '/members/1',
            null,
            [$this->menu('espace_animes', 'Espace animés')]
        );

        $this->assertMatchesRegularExpression(
            '/data-open-menu="espace_animes">Espace animés<\/button>/',
            $html
        );
    }

    public function testCurrentPageSegmentIsNeverALink(): void
    {
        $html = $this->render(
            ['label' => 'Import Desk', 'parents' => ['Espace chefs d\'U']],
            '/admin/import',
            null,
            [$this->menu('espace_admin', 'Espace chefs d\'U')]
        );

        $this->assertDoesNotMatchRegularExpression('/<a[^>]*>\s*Import Desk\s*<\/a>/', $html);
        $this->assertDoesNotMatchRegularExpression('/<button[^>]*>\s*Import Desk\s*<\/button>/', $html);
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

    /**
     * The home route declares its own breadcrumb (label "Accueil", no
     * parents) so the trail ends the same way every other page's does —
     * an active, non-link current-page item after the icon — rather than
     * stopping bare at the icon the way a route with no breadcrumb at all
     * does (see testRouteWithoutBreadcrumbStopsAtHomeIconWithoutError).
     */
    public function testHomePageShowsAccueilAsTheActiveCurrentPage(): void
    {
        $html = $this->render(
            ['label' => 'Accueil', 'parents' => []],
            '/'
        );

        $this->assertSame(2, substr_count($html, '<li class="breadcrumb-item'));
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertMatchesRegularExpression('/aria-current="page">\s*Accueil\s*<\/li>/', $html);
    }

    public function testMissingContextRendersHomeIconOnlyWithoutError(): void
    {
        // Simulates rendering outside FrontController::handle() (e.g. an
        // existing test that renders base.html.twig directly without ever
        // setting the route_breadcrumb global) — must not throw.
        $html = $this->twig->render('partials/breadcrumb_bar.html.twig');

        $this->assertStringContainsString('bi-house-door', $html);
    }

    /**
     * `breadcrumb_trail` renders as a real link — deliberately different
     * from `parents`, which never does (see the partial's own docblock
     * for why: a trail entry names a specific, unambiguous ancestor page
     * within the same controller's page family, not a menu category).
     */
    public function testBreadcrumbTrailEntriesRenderAsRealLinksBeforeTheCurrentPage(): void
    {
        $html = $this->render(
            ['label' => 'Membres', 'parents' => ['Espace animés']],
            '/groups/5/members',
            null,
            [],
            [['label' => 'Groupes', 'url' => '/groups'], ['label' => 'Louveteaux', 'url' => '/groups/5']]
        );

        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-truncate">\s*<a href="\/groups" class="text-decoration-none">Groupes<\/a>\s*<\/li>/',
            $html
        );
        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-truncate">\s*<a href="\/groups\/5" class="text-decoration-none">Louveteaux<\/a>\s*<\/li>/',
            $html
        );
        $this->assertMatchesRegularExpression('/aria-current="page">\s*Membres\s*<\/li>/', $html);
    }

    public function testAbsentBreadcrumbTrailRendersNothingExtra(): void
    {
        $html = $this->render(['label' => 'Staffs', 'parents' => ['Espace chefs']], '/chefs/staffs');

        // Home icon + the one plain-text parent + the active current page
        // — no extra <li> for a trail that was never passed.
        $this->assertSame(3, substr_count($html, '<li class="breadcrumb-item'));
    }

    public function testMultipleParentsEachBecomeTheirOwnButtonWithTheCorrectMenuId(): void
    {
        $html = $this->render(
            ['label' => 'Staffs', 'parents' => ['Espace chefs', 'Espace chefs d\'U']],
            '/chefs/staffs',
            null,
            [
                $this->menu('espace_chefs', 'Espace chefs'),
                $this->menu('espace_admin', 'Espace chefs d\'U'),
            ]
        );

        $this->assertMatchesRegularExpression('/data-open-menu="espace_chefs">Espace chefs<\/button>/', $html);
        $this->assertMatchesRegularExpression('/data-open-menu="espace_admin">Espace chefs d&#039;U<\/button>/', $html);
    }
}
