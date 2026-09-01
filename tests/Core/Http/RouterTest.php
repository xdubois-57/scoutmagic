<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Request;
use Core\Http\ResolvedRoute;
use Core\Http\Router;
use Core\Security\Role;
use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testExactPathMatchResolvesCorrectly(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/home', 'App\\Controller\\HomeController', 'index', 'public');

        $request = new Request('GET', '/home', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame('App\\Controller\\HomeController', $resolved->controllerClass);
        $this->assertSame('index', $resolved->action);
        $this->assertSame([], $resolved->params);
    }

    public function testPathWithParameterExtractsTheParameter(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/members/{id}', 'App\\Controller\\MemberController', 'show', 'public');

        $request = new Request('GET', '/members/42', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame('App\\Controller\\MemberController', $resolved->controllerClass);
        $this->assertSame('show', $resolved->action);
        $this->assertSame(['id' => '42'], $resolved->params);
    }

    public function testMultipleParametersExtracted(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/sections/{section}/members/{id}', 'App\\Controller\\MemberController', 'show', 'public');

        $request = new Request('GET', '/sections/baladins/members/7', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame(['section' => 'baladins', 'id' => '7'], $resolved->params);
    }

    public function testUnknownPathReturnsNull(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/home', 'App\\Controller\\HomeController', 'index', 'public');

        $request = new Request('GET', '/unknown', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertNull($resolved);
    }

    public function testMethodMismatchReturnsNull(): void
    {
        $router = new Router();
        $router->addRoute('POST', '/submit', 'App\\Controller\\FormController', 'submit', 'public');

        $request = new Request('GET', '/submit', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertNull($resolved);
    }

    public function testRoleMinIsPreservedInResolvedRoute(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/admin', 'App\\Controller\\AdminController', 'dashboard', 'admin');

        $request = new Request('GET', '/admin', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame('admin', $resolved->roleMin);
    }

    /**
     * role_min is mandatory, not defaulted. SECURITY.md §3 promises a route
     * without one is rejected at load time — ModuleManifest enforced that
     * for module routes, but a core route registered straight on the Router
     * used to silently fall back to 'public', so a forgotten argument
     * published the route to anonymous visitors instead of failing loudly.
     */
    public function testRoleMinIsMandatory(): void
    {
        $router = new Router();

        $this->expectException(\ArgumentCountError::class);
        // @phpstan-ignore-next-line arguments.count — that is exactly the mistake under test
        $router->addRoute('GET', '/page', 'App\\Controller\\PageController', 'index');
    }

    public function testAnUnknownRoleMinIsRejectedRatherThanTreatedAsPublic(): void
    {
        $router = new Router();

        $this->expectException(\InvalidArgumentException::class);
        // Role::fromString() maps anything unrecognised to PUBLIC, so a typo
        // in a role name would otherwise open the route to everyone.
        $router->addRoute('GET', '/page', 'App\\Controller\\PageController', 'index', 'cheif');
    }

    public function testAValidRoleMinIsCarriedOntoTheResolvedRoute(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/page', 'App\\Controller\\PageController', 'index', 'chief');

        $resolved = $router->resolve(new Request('GET', '/page', [], [], [], []));

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame('chief', $resolved->roleMin);
    }

    public function testBreadcrumbDefaultsToNull(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/page', 'App\\Controller\\PageController', 'index', 'public');

        $request = new Request('GET', '/page', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertNull($resolved->breadcrumb);
    }

    /**
     * Router::resolve() still takes the FIRST pattern that matches, with
     * no literal-vs-wildcard preference — so an earlier all-wildcard-tail
     * route can still absorb a request meant for a later, more specific
     * one. That is the mechanism behind the real incident this test's
     * sibling in Tests\Core\Module\ModuleManifestTest rejects at the
     * module.json level.
     *
     * It survives only for placeholders that are NOT named like row
     * identifiers — see the next test for why, and
     * Core\Http\Router::placeholderPattern for the rule.
     */
    public function testAnEarlierWildcardRouteStillShadowsALaterLiteralOne(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/x/{slug}/{token}', 'App\\Controller\\ByTokenController', 'show', 'public');
        $router->addRoute('GET', '/x/demande/{slug}', 'App\\Controller\\LinkedController', 'show', 'public');

        $resolved = $router->resolve(new Request('GET', '/x/demande/42', [], [], [], []));

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        // The wrong controller wins, with "demande" bound to {slug}
        // instead of ever reaching LinkedController — exactly what a real
        // visitor hit as a plain 404 with no clue why.
        $this->assertSame('App\\Controller\\ByTokenController', $resolved->controllerClass);
        $this->assertSame(['slug' => 'demande', 'token' => '42'], $resolved->params);
    }

    /**
     * ...and does NOT survive when the wildcard is named like an
     * identifier, which is the shape the real incident had:
     * `/inscriptions/suivi/{id}/{token}` swallowing
     * `/inscriptions/suivi/demande/{id}`.
     *
     * An identifier-named placeholder matches digits only, so "demande"
     * is not a candidate `{id}` at all and the literal route it was meant
     * for wins on its own. That is a side effect of a rule introduced for
     * a different reason — an id must be digits so `(int) '2-1'` cannot
     * silently pick row 2 (SECURITY.md § 35) — and it is worth a test of
     * its own, because it is the kind of property that gets removed by
     * accident when somebody loosens the pattern for an unrelated need.
     */
    public function testAnIdentifierWildcardCannotShadowALiteralSegment(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/x/{id}/{token}', 'App\\Controller\\ByTokenController', 'show', 'public');
        $router->addRoute('GET', '/x/demande/{id}', 'App\\Controller\\LinkedController', 'show', 'public');

        $resolved = $router->resolve(new Request('GET', '/x/demande/42', [], [], [], []));

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame('App\\Controller\\LinkedController', $resolved->controllerClass);
        $this->assertSame(['id' => '42'], $resolved->params);

        // And the wildcard route still works for what it was for.
        $byToken = $router->resolve(new Request('GET', '/x/7/abc', [], [], [], []));
        $this->assertInstanceOf(ResolvedRoute::class, $byToken);
        $this->assertSame('App\\Controller\\ByTokenController', $byToken->controllerClass);
    }

    /**
     * The real incident, end to end, through the real module.json: a
     * family clicking their pending request in "Espace membres" hit
     * "/inscriptions/suivi/demande/{id}", and until module.json's routes
     * were reordered, that request was silently swallowed by "/inscriptions/
     * suivi/{id}/{token}" (declared earlier at the time) — bound to
     * TrackingController::showByToken with id="demande", producing a plain
     * 404 that gave no hint the actual route existed and was misrouted.
     */
    public function testRegistrationModuleDoesNotShadowItsOwnLinkedTrackingRoute(): void
    {
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/registration/module.json');
        $router = new Router();
        $router->registerModuleRoutes($manifest);

        $resolved = $router->resolve(new Request('GET', '/inscriptions/suivi/demande/42', [], [], [], []));

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame('Modules\\Registration\\Controller\\TrackingController', $resolved->controllerClass);
        $this->assertSame('showLinked', $resolved->action);
        $this->assertSame(['id' => '42'], $resolved->params);
    }

    public function testBreadcrumbIsPreservedInResolvedRoute(): void
    {
        $router = new Router();
        $router->addRoute(
            'GET',
            '/chefs/staffs',
            'App\\Controller\\StaffsController',
            'index',
            'intendant',
            ['label' => 'Staffs', 'parents' => ['Espace animateurs']]
        );

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame(['label' => 'Staffs', 'parents' => ['Espace animateurs']], $resolved->breadcrumb);
    }

    /**
     * A declared ancestor page becomes a real breadcrumb link once the
     * reader's role clears the ancestor route's own floor.
     */
    public function testAncestorTrailResolvesADeclaredAncestorPage(): void
    {
        $router = $this->routerWithNewsRoutes();

        $trail = $router->ancestorTrailFor(
            ['label' => 'Actualité', 'parents' => [], 'ancestors' => [['label' => 'Actualités', 'path' => '/news/manage']]],
            Role::CHIEF
        );

        $this->assertSame([['label' => 'Actualités', 'url' => '/news/manage']], $trail);
    }

    /**
     * Visibility is a convenience, never a boundary (SECURITY.md §3): a
     * reader below the ancestor's role_min loses the STEP, not the page —
     * a link to a 403 would be worse than no link at all.
     */
    public function testAncestorTheReaderCannotReachDisappearsRatherThanLinkingToA403(): void
    {
        $router = $this->routerWithNewsRoutes();

        $trail = $router->ancestorTrailFor(
            ['label' => 'Actualité', 'parents' => [], 'ancestors' => [['label' => 'Actualités', 'path' => '/news/manage']]],
            Role::IDENTIFIED
        );

        $this->assertSame([], $trail);
    }

    /**
     * Fail-safe direction: an ancestor whose module was disabled (so its
     * route is not registered at all) stops being a link rather than
     * becoming a link to a 404.
     */
    public function testAncestorPointingAtNoDeclaredRouteDisappears(): void
    {
        $router = $this->routerWithNewsRoutes();

        $trail = $router->ancestorTrailFor(
            ['label' => 'Actualité', 'parents' => [], 'ancestors' => [['label' => 'Galerie', 'path' => '/gallery']]],
            Role::SUPERADMIN
        );

        $this->assertSame([], $trail);
    }

    public function testAncestorTrailKeepsTheDeclaredOrderAndDropsOnlyWhatIsUnreachable(): void
    {
        $router = $this->routerWithNewsRoutes();

        $trail = $router->ancestorTrailFor(
            ['label' => 'Réponses', 'parents' => [], 'ancestors' => [
                ['label' => 'Actualités', 'path' => '/news'],
                ['label' => 'Gestion', 'path' => '/news/manage'],
            ]],
            Role::IDENTIFIED
        );

        $this->assertSame([['label' => 'Actualités', 'url' => '/news']], $trail);
    }

    public function testARouteDeclaringNoAncestorsGetsAnEmptyTrail(): void
    {
        $router = $this->routerWithNewsRoutes();

        $this->assertSame([], $router->ancestorTrailFor(['label' => 'Actualité', 'parents' => []], Role::ADMIN));
        $this->assertSame([], $router->ancestorTrailFor(null, Role::ADMIN));
    }

    public function testRoleMinForPathMatchesTheDeclaredPathOnly(): void
    {
        $router = $this->routerWithNewsRoutes();

        $this->assertSame('public', $router->roleMinForPath('/news'));
        $this->assertSame('chief', $router->roleMinForPath('/news/manage'));
        // A concrete URL that a pattern would match is not a declared
        // path — an ancestor is always a real list page.
        $this->assertNull($router->roleMinForPath('/news/12'));
    }

    public function testPageAtPathAnswersOnlyForALabelledGetPage(): void
    {
        // The narrow accessor Core\Help\HelpPageLinkResolver reads: what
        // to call the page, and who may open it. Nothing else about the
        // route table leaves this class.
        $router = new Router();
        $router->addRoute('GET', '/news', 'App\\Controller\\NewsController', 'index', 'public', ['label' => 'Actualités', 'parents' => []]);
        $router->addRoute('GET', '/news/{id}', 'App\\Controller\\NewsController', 'show', 'public', ['label' => 'Actualité', 'parents' => []]);
        $router->addRoute('GET', '/api/news', 'App\\Controller\\NewsController', 'feed', 'public');
        $router->addRoute('POST', '/news/save', 'App\\Controller\\NewsController', 'save', 'chief', ['label' => 'Actualités', 'parents' => []]);

        $this->assertSame(['label' => 'Actualités', 'roleMin' => 'public'], $router->pageAtPath('/news'));
        // A pattern is matched textually — there is no one article to open.
        $this->assertNull($router->pageAtPath('/news/12'));
        // No breadcrumb: an API endpoint, not a page.
        $this->assertNull($router->pageAtPath('/api/news'));
        // A POST route is not somewhere to send a reader.
        $this->assertNull($router->pageAtPath('/news/save'));
        $this->assertNull($router->pageAtPath('/nowhere'));
    }

    private function routerWithNewsRoutes(): Router
    {
        $router = new Router();
        $router->addRoute('GET', '/news', 'App\\Controller\\NewsController', 'index', 'public');
        $router->addRoute('GET', '/news/manage', 'App\\Controller\\NewsController', 'manage', 'chief');
        $router->addRoute('GET', '/news/{id}', 'App\\Controller\\NewsController', 'show', 'public');

        return $router;
    }
}
