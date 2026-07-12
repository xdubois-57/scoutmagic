<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Request;
use Core\Http\Router;
use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

class RouterModuleTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router();
    }

    public function testRegisterModuleRoutesAddsRoutesToRouter(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'calendar',
            'name' => 'Calendrier',
            'version' => '1.0.0',
            'routes' => [
                [
                    'path' => '/calendar',
                    'method' => 'GET',
                    'controller' => 'Modules\\Calendar\\Controller\\CalendarController',
                    'action' => 'index',
                    'menu' => 'espace_animes',
                    'role_min' => 'identified',
                    'label' => 'Calendrier',
                ],
            ],
        ]);

        $this->router->registerModuleRoutes($manifest);

        $request = new Request('GET', '/calendar', [], [], [], []);
        $resolved = $this->router->resolve($request);

        $this->assertNotNull($resolved);
        $this->assertSame('Modules\\Calendar\\Controller\\CalendarController', $resolved->controllerClass);
        $this->assertSame('index', $resolved->action);
        $this->assertSame('identified', $resolved->roleMin);
    }

    public function testCoreRoutesTakePriorityOverModuleRoutes(): void
    {
        // Core route registered first
        $this->router->addRoute('GET', '/test', 'CoreController', 'index', 'public');

        // Module tries same route
        $manifest = ModuleManifest::fromArray([
            'id' => 'test_module',
            'name' => 'Test',
            'version' => '1.0.0',
            'routes' => [
                [
                    'path' => '/test',
                    'method' => 'GET',
                    'controller' => 'ModuleController',
                    'action' => 'index',
                    'menu' => 'notre_unite',
                    'role_min' => 'public',
                    'label' => 'Test',
                ],
            ],
        ]);

        $this->router->registerModuleRoutes($manifest);

        $request = new Request('GET', '/test', [], [], [], []);
        $resolved = $this->router->resolve($request);

        // Core route wins (first match)
        $this->assertSame('CoreController', $resolved->controllerClass);
    }

    public function testGetModuleForPathReturnsModuleId(): void
    {
        $manifest = ModuleManifest::fromArray([
            'id' => 'calendar',
            'name' => 'Calendrier',
            'version' => '1.0.0',
            'routes' => [
                [
                    'path' => '/calendar',
                    'method' => 'GET',
                    'controller' => 'Modules\\Calendar\\Controller\\CalendarController',
                    'action' => 'index',
                    'menu' => 'espace_animes',
                    'role_min' => 'identified',
                    'label' => 'Calendrier',
                ],
            ],
        ]);

        $this->router->registerModuleRoutes($manifest);

        $this->assertSame('calendar', $this->router->getModuleForPath('/calendar'));
        $this->assertNull($this->router->getModuleForPath('/nonexistent'));
    }
}
