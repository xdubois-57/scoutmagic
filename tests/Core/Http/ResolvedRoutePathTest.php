<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Request;
use Core\Http\Router;
use PHPUnit\Framework\TestCase;

/**
 * A resolved route carries the PATTERN it was declared as, not the URL
 * that matched it.
 *
 * Nothing on a request keeps that distinction otherwise: by the time a
 * controller has run, `/members/42` is all there is, and « the page of a
 * member » has become « member 42 ». Modules\UsageStats counts the former
 * and must never be able to see the latter (ARCHITECTURE.md §8.93), which
 * is why the pattern is carried here rather than reconstructed afterwards.
 */
class ResolvedRoutePathTest extends TestCase
{
    public function testAResolvedRouteCarriesItsDeclaredPatternRatherThanTheRequestedUrl(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/members/{id}', 'X', 'show', 'identified');

        $resolved = $router->resolve(new Request('GET', '/members/42', [], [], [], []));

        $this->assertNotNull($resolved);
        $this->assertSame('/members/{id}', $resolved->path);
        $this->assertSame(['id' => '42'], $resolved->params);
    }

    public function testAStaticRouteCarriesItsOwnPath(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/calendar', 'X', 'index', 'identified');

        $resolved = $router->resolve(new Request('GET', '/calendar', [], [], [], []));

        $this->assertNotNull($resolved);
        $this->assertSame('/calendar', $resolved->path);
    }

    /**
     * The pattern is also the key Router::getModuleForPath() is registered
     * under, which is what makes « which module does this page belong to »
     * a free dimension rather than a second lookup table.
     *
     * **Asserted through addRoute(), deliberately.** An earlier version of
     * this test called `registerModuleRoutes()` — the manifest-shaped
     * method two other suites also exercise, and that the application has
     * never called once. It passed, and production filed every module page
     * view under `core` for as long as it did. A test that reaches for the
     * convenient entry point instead of the real one proves the entry
     * point works, and nothing about the application.
     */
    public function testAModuleRouteRemembersItsModuleUnderItsPattern(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/calendar/event/{id}', 'X', 'show', 'identified', null, 'calendar');
        $router->addRoute('GET', '/', 'X', 'home', 'public');

        $resolved = $router->resolve(new Request('GET', '/calendar/event/7', [], [], [], []));
        $this->assertNotNull($resolved);
        $this->assertSame('calendar', $router->getModuleForPath($resolved->path));

        // A core route answers null, which is what the recorder turns into
        // `core` — the distinction has to survive, or every page becomes a
        // module page instead.
        $home = $router->resolve(new Request('GET', '/', [], [], [], []));
        $this->assertNotNull($home);
        $this->assertNull($router->getModuleForPath($home->path));
    }
}
