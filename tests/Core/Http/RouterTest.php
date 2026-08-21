<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Request;
use Core\Http\ResolvedRoute;
use Core\Http\Router;
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
     * Documents Router::resolve()'s actual matching semantics (first
     * pattern match wins, no literal-vs-wildcard specificity preference) —
     * the mechanism behind the real incident this test's sibling in
     * Tests\Core\Module\ModuleManifestTest guards against at the module.json
     * level. A wildcard segment matches a literal path segment just as
     * happily as it matches a "real" id, so an earlier all-wildcard-tail
     * route silently absorbs every request meant for a later, more
     * specific route sharing the same segment count.
     */
    public function testAnEarlierWildcardRouteShadowsALaterLiteralRoute(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/x/{id}/{token}', 'App\\Controller\\ByTokenController', 'show', 'public');
        $router->addRoute('GET', '/x/demande/{id}', 'App\\Controller\\LinkedController', 'show', 'public');

        $resolved = $router->resolve(new Request('GET', '/x/demande/42', [], [], [], []));

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        // The wrong controller wins, with "demande" bound to {id} instead
        // of ever reaching LinkedController — exactly what a real visitor
        // hit as a plain 404 with no clue why.
        $this->assertSame('App\\Controller\\ByTokenController', $resolved->controllerClass);
        $this->assertSame(['id' => 'demande', 'token' => '42'], $resolved->params);
    }

    /**
     * The real incident, end to end, through the real module.json: a
     * family clicking their pending request in "Espace animés" hit
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
            ['label' => 'Staffs', 'parents' => ['Espace chefs']]
        );

        $request = new Request('GET', '/chefs/staffs', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame(['label' => 'Staffs', 'parents' => ['Espace chefs']], $resolved->breadcrumb);
    }
}
