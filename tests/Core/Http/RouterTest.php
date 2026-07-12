<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\Http\Request;
use Core\Http\ResolvedRoute;
use Core\Http\Router;
use PHPUnit\Framework\TestCase;

class RouterTest extends TestCase
{
    public function testExactPathMatchResolvesCorrectly(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/home', 'App\\Controller\\HomeController', 'index');

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
        $router->addRoute('GET', '/members/{id}', 'App\\Controller\\MemberController', 'show');

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
        $router->addRoute('GET', '/sections/{section}/members/{id}', 'App\\Controller\\MemberController', 'show');

        $request = new Request('GET', '/sections/baladins/members/7', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame(['section' => 'baladins', 'id' => '7'], $resolved->params);
    }

    public function testUnknownPathReturnsNull(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/home', 'App\\Controller\\HomeController', 'index');

        $request = new Request('GET', '/unknown', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertNull($resolved);
    }

    public function testMethodMismatchReturnsNull(): void
    {
        $router = new Router();
        $router->addRoute('POST', '/submit', 'App\\Controller\\FormController', 'submit');

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

    public function testDefaultRoleMinIsPublic(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/page', 'App\\Controller\\PageController', 'index');

        $request = new Request('GET', '/page', [], [], [], []);
        $resolved = $router->resolve($request);

        $this->assertInstanceOf(ResolvedRoute::class, $resolved);
        $this->assertSame('public', $resolved->roleMin);
    }
}
