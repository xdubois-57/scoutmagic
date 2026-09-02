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
     */
    public function testThePatternIsWhatResolvesTheOwningModule(): void
    {
        $router = new Router();
        $router->registerModuleRoutes(new \Core\Module\ModuleManifest(
            'calendar',
            'Calendrier',
            '1.0.0',
            [[
                'path' => '/calendar/event/{id}',
                'method' => 'GET',
                'controller' => 'X',
                'action' => 'show',
                'menu' => 'espace_animes',
                'role_min' => 'identified',
                'label' => '',
                'menu_order' => 100,
                'menu_order_explicit' => false,
                'menu_icon' => null,
                'menu_group' => null,
                'breadcrumb' => null,
            ]],
            [],
            [],
            [],
            []
        ));

        $resolved = $router->resolve(new Request('GET', '/calendar/event/7', [], [], [], []));

        $this->assertNotNull($resolved);
        $this->assertSame('calendar', $router->getModuleForPath($resolved->path));
    }
}
