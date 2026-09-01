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
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
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
        ?array $breadcrumbTrail = null,
        ?array $routeAncestors = null
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
        if ($routeAncestors !== null) {
            $context['route_breadcrumb_ancestors'] = $routeAncestors;
        }

        return $this->twig->render('partials/breadcrumb_bar.html.twig', $context);
    }

    /**
     * The route-declared ancestor page is a real link, exactly like a
     * controller-supplied trail entry — and unlike a `parents` menu
     * label, which is the whole point of the distinction.
     */
    public function testRouteDeclaredAncestorRendersAsARealLink(): void
    {
        $html = $this->render(
            ['label' => 'Actualité', 'parents' => ['Notre unité']],
            '/news/12',
            'Camp 2026',
            [],
            null,
            [['label' => 'Actualités', 'url' => '/news']]
        );

        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-truncate">\s*<a href="\/news" class="text-decoration-none">Actualités<\/a>\s*<\/li>/',
            $html
        );
        $this->assertMatchesRegularExpression('/aria-current="page">\s*Camp 2026\s*<\/li>/', $html);
    }

    /**
     * The static steps come first, the dynamic ones after: on a rental
     * request form the module declares « Locations » on the route and the
     * controller resolves the asset itself, and the reader has to walk
     * them outermost-first.
     */
    public function testRouteDeclaredAncestorsRenderBeforeAControllerSuppliedTrail(): void
    {
        $html = $this->render(
            ['label' => 'Demande de location', 'parents' => ['Notre unité']],
            '/locations/le-chalet/demande',
            null,
            [],
            [['label' => 'Le Chalet', 'url' => '/locations/le-chalet']],
            [['label' => 'Locations', 'url' => '/locations']]
        );

        $this->assertLessThan(
            strpos($html, 'Le Chalet'),
            strpos($html, '>Locations</a>'),
            'The statically declared ancestor must render before the dynamic one.'
        );
    }

    public function testAnAncestorDroppedForTheReadersRoleLeavesNoEmptyStep(): void
    {
        // FrontController hands the partial only the steps that survived
        // the role filter, so an empty list must simply render nothing.
        $html = $this->render(
            ['label' => 'Actualité', 'parents' => ['Notre unité']],
            '/news/12',
            null,
            [],
            null,
            []
        );

        $this->assertSame(3, substr_count($html, '<li class="breadcrumb-item'));
        $this->assertStringNotContainsString('breadcrumb-bar--has-trail', $html);
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
            ['label' => 'Staffs', 'parents' => ['Espace animateurs']],
            '/chefs/staffs'
        );

        $this->assertStringContainsString('Espace animateurs', $html);
        $this->assertStringContainsString('Staffs', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
    }

    /**
     * Bootstrap draws the "/" separator as `::before` padding on every
     * breadcrumb item after the first. Any whitespace between an item's
     * `<li>` and its content collapses into a real space that lands right
     * after that separator, so the trail read « / &nbsp;Statistiques »
     * with a visible double gap — on the last crumb and on every
     * ancestor-page link, the two the template indented. No breadcrumb
     * item may start with whitespace.
     */
    public function testNoBreadcrumbItemStartsWithAWhitespaceTextNode(): void
    {
        $html = $this->render(
            ['label' => 'Louveteaux', 'parents' => ['Espace chefs d\'U']],
            '/groups/5',
            'Louveteaux',
            [$this->menu('espace_admin', 'Espace chefs d\'U')],
            [['label' => 'Groupes', 'url' => '/groups']]
        );

        $this->assertDoesNotMatchRegularExpression('/<li class="breadcrumb-item[^"]*"[^>]*>\s/', $html);
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
            ['label' => 'Staffs', 'parents' => ['Espace animateurs']],
            '/chefs/staffs'
        );

        $this->assertMatchesRegularExpression(
            '/<li class="breadcrumb-item text-body-secondary">Espace animateurs<\/li>/',
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
            ['label' => 'Staffs', 'parents' => ['Espace animateurs']],
            '/chefs/staffs',
            null,
            [$this->menu('espace_chefs', 'Espace animateurs')]
        );

        $this->assertMatchesRegularExpression(
            '/data-open-menu="espace_chefs">Espace animateurs<\/button>/',
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
            ['label' => 'Membre', 'parents' => ['Espace membres']],
            '/members/1',
            null,
            [$this->menu('espace_animes', 'Espace membres')]
        );

        $this->assertMatchesRegularExpression(
            '/data-open-menu="espace_animes">Espace membres<\/button>/',
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
            ['label' => 'Membre', 'parents' => ['Espace membres']],
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
            ['label' => 'Membres', 'parents' => ['Espace membres']],
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
        $html = $this->render(['label' => 'Staffs', 'parents' => ['Espace animateurs']], '/chefs/staffs');

        // Home icon + the one plain-text parent + the active current page
        // — no extra <li> for a trail that was never passed.
        $this->assertSame(3, substr_count($html, '<li class="breadcrumb-item'));
    }

    public function testMultipleParentsEachBecomeTheirOwnButtonWithTheCorrectMenuId(): void
    {
        $html = $this->render(
            ['label' => 'Staffs', 'parents' => ['Espace animateurs', 'Espace chefs d\'U']],
            '/chefs/staffs',
            null,
            [
                $this->menu('espace_chefs', 'Espace animateurs'),
                $this->menu('espace_admin', 'Espace chefs d\'U'),
            ]
        );

        $this->assertMatchesRegularExpression('/data-open-menu="espace_chefs">Espace animateurs<\/button>/', $html);
        $this->assertMatchesRegularExpression('/data-open-menu="espace_admin">Espace chefs d&#039;U<\/button>/', $html);
    }

    /**
     * « Camps / Camps ». The route declared « Camps » as its static
     * ancestor and the controller passed the very same page again as a
     * trail entry; the bar rendered the step twice, one after the other.
     *
     * Both declarations were individually reasonable and neither author
     * could see the other, so the bar is where they have to be reconciled
     * — even now that the duplicated declarations themselves are gone.
     */
    public function testTheSameAncestorPageDeclaredByBothSourcesRendersOnce(): void
    {
        $html = $this->render(
            ['label' => 'Courrier des camps', 'parents' => ['Espace animateurs']],
            '/chefs/camps/courrier',
            'Courrier des camps',
            [],
            [['label' => 'Camps', 'url' => '/chefs/camps']],
            [['label' => 'Camps', 'url' => '/chefs/camps']]
        );

        $this->assertSame(1, substr_count($html, 'href="/chefs/camps"'));
        // Home icon + the parent + the single « Camps » + the current page.
        $this->assertSame(4, substr_count($html, '<li class="breadcrumb-item'));
    }

    /**
     * De-duplication is on the url, because the url is what a step IS.
     * Two steps that merely share a label are two different pages and the
     * visitor needs both.
     */
    public function testTwoDifferentPagesSharingALabelBothStay(): void
    {
        $html = $this->render(
            ['label' => 'Documents', 'parents' => []],
            '/chefs/camps/sejours/7/documents',
            null,
            [],
            [['label' => 'Été', 'url' => '/chefs/camps/lieux/3'], ['label' => 'Été', 'url' => '/chefs/camps/sejours/7']],
            [['label' => 'Camps', 'url' => '/chefs/camps']]
        );

        $this->assertSame(1, substr_count($html, 'href="/chefs/camps/lieux/3"'));
        $this->assertSame(1, substr_count($html, 'href="/chefs/camps/sejours/7"'));
    }

    /**
     * The route's static ancestors stay ahead of the controller's dynamic
     * ones, and the outermost occurrence is the one that survives — the
     * trail is read from the top down, so a duplicate cannot be allowed to
     * pull a step further in than where it belongs.
     */
    public function testTheOutermostOccurrenceOfADuplicatedStepIsTheOneKept(): void
    {
        $html = $this->render(
            ['label' => 'Message capturé', 'parents' => []],
            '/test-tools/mail-sandbox/9',
            null,
            [],
            [['label' => 'Outils de test', 'url' => '/test-tools'], ['label' => 'Bac à sable', 'url' => '/test-tools/mail-sandbox']],
            [['label' => 'Outils de test', 'url' => '/test-tools']]
        );

        $this->assertLessThan(
            strpos($html, 'href="/test-tools/mail-sandbox"'),
            strpos($html, 'href="/test-tools"')
        );
    }
}
