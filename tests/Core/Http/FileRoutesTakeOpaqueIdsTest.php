<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use Core\File\FilePublicIds;
use Core\Http\Request;
use Core\Http\Router;
use PHPUnit\Framework\TestCase;

if (!defined('AUTHZ_SUPPORT_TEST')) {
    define('AUTHZ_SUPPORT_TEST', true);
}
require_once dirname(__DIR__, 3) . '/scripts/authz-support.php';

/**
 * Issue #1109, through the router rather than a direct controller call:
 * the `/files/…` routes the application really registers accept an opaque
 * public id. Named `{id}`, their segment was held to digits by
 * Router::placeholderPattern(), and every opaque address 404ed before the
 * controller ever saw it.
 */
final class FileRoutesTakeOpaqueIdsTest extends TestCase
{
    public function testTheRegisteredFileRoutesResolveAnOpaqueAddress(): void
    {
        $router = new Router();
        $registered = 0;
        foreach (\authzCoreRoutes() as $route) {
            if (str_starts_with($route['path'], '/files/')) {
                $router->addRoute($route['method'], $route['path'], 'FileController', 'action', $route['role_min']);
                $registered++;
            }
        }
        $this->assertSame(3, $registered, 'the three /files/ routes of public/index.php');

        $publicId = FilePublicIds::generate();
        foreach (['', '/thumbnail', '/md', '/thumb'] as $suffix) {
            $resolved = $router->resolve(new Request('GET', '/files/' . $publicId . $suffix, [], [], [], []));

            $this->assertNotNull($resolved, '/files/{public id}' . $suffix);
            $this->assertSame($publicId, $resolved->params['file'] ?? null);
        }
    }
}
