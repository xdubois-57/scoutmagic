<?php

declare(strict_types=1);

namespace Tests\Core\Page;

use Core\Http\Controller\TextPageController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Page\TextPageRepository;
use Core\Page\TextPageService;
use Core\Page\TextPageRouteRegistrar;
use Core\Security\Role;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The route half of issue #368, and the reason it is a route at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class TextPageRouteRegistrarTest extends TestCase
{
    private \PDO $pdo;
    private TextPageRepository $repository;
    private TextPageService $service;

    private static function get(string $path): Request
    {
        return new Request('GET', $path, [], [], [], []);
    }

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new TextPageRepository($this->pdo);
        $this->service = new TextPageService($this->repository);
    }

    /**
     * **The point of the whole design**, asserted on the router's own
     * resolution rather than on anything this feature owns.
     *
     * A single `/pages/{slug}` declared `public` with the role checked
     * afterwards in the controller would satisfy no part of this test:
     * the floor `match()` reports would be `public` for every page.
     */
    public function testEachPageRegistersItsOwnRouteCarryingItsSectionsFloor(): void
    {
        $expected = [
            MenuBuilder::MENU_NOTRE_UNITE => 'public',
            MenuBuilder::MENU_ESPACE_ANIMES => 'identified',
            MenuBuilder::MENU_ESPACE_CHEFS => 'intendant',
            MenuBuilder::MENU_ESPACE_ADMIN => 'admin',
            MenuBuilder::MENU_CONFIGURATION => 'superadmin',
        ];

        foreach (array_keys($expected) as $menuId) {
            $this->service->create('Page', 'Page ' . $menuId, $menuId, $this->service->defaultGroupFor($menuId));
        }

        $router = new Router();
        $pages = TextPageRouteRegistrar::register($router, $this->repository);
        $this->assertCount(5, $pages);

        foreach ($pages as $page) {
            $route = $router->resolve(self::get($page->path()));

            $this->assertNotNull($route, "no route for {$page->path()}");
            $this->assertSame(TextPageController::class, $route->controllerClass);
            $this->assertSame('show', $route->action);
            $this->assertSame(
                $expected[$page->menuId],
                $route->roleMin,
                "{$page->path()} must carry the floor of {$page->menuId}"
            );
        }
    }

    /**
     * The frontier, both ways, for every section — what the chantier
     * asks for explicitly: allowed at the floor, refused one rung below.
     *
     * Asked of `Core\Security\Role::hasAccess()`, the same predicate the
     * RBAC guard uses, against the `role_min` the router actually holds
     * for the page's own path.
     */
    public function testEverySectionAdmitsItsFloorAndRefusesTheRungBelow(): void
    {
        $below = [
            MenuBuilder::MENU_ESPACE_ANIMES => Role::PUBLIC,
            MenuBuilder::MENU_ESPACE_CHEFS => Role::IDENTIFIED,
            MenuBuilder::MENU_ESPACE_ADMIN => Role::CHIEF,
            MenuBuilder::MENU_CONFIGURATION => Role::ADMIN,
        ];

        $router = new Router();
        $paths = [];
        foreach (array_keys($below) as $menuId) {
            $page = $this->service->create('Page', 'Page ' . $menuId, $menuId, $this->service->defaultGroupFor($menuId));
            $paths[$menuId] = $page->path();
        }
        TextPageRouteRegistrar::register($router, $this->repository);

        foreach ($below as $menuId => $insufficientRole) {
            $roleMin = $router->roleMinForPath($paths[$menuId]);
            $this->assertNotNull($roleMin);
            $required = Role::fromString($roleMin);

            $this->assertFalse(
                $insufficientRole->hasAccess($required),
                "{$menuId}: one rung below the floor must be refused"
            );
        }
    }

    /**
     * A page that is switched off has no route at all — which is what
     * makes it answer 404 and not 403. It does not exist; it is not
     * forbidden, and nothing confirms to a passer-by that there is
     * something behind the address.
     */
    public function testAHiddenPageRegistersNoRouteAtAll(): void
    {
        $page = $this->service->create('ASBL', 'Notre ASBL', MenuBuilder::MENU_NOTRE_UNITE, null);

        $router = new Router();
        TextPageRouteRegistrar::register($router, $this->repository);
        $this->assertNotNull($router->resolve(self::get($page->path())));

        $this->service->setActive($page->id, false);

        $afterHiding = new Router();
        $registered = TextPageRouteRegistrar::register($afterHiding, $this->repository);

        $this->assertSame([], $registered);
        $this->assertNull(
            $afterHiding->resolve(self::get($page->path())),
            'a hidden page must not be a route the guard can refuse — it must be nothing at all'
        );
    }

    /**
     * Trap two of the chantier.
     *
     * This registrar runs in the front controller on every request. An
     * installation whose database cannot be read — the first install
     * before any schema exists, rotated credentials, a restore in
     * progress — must still be able to answer, `/setup` and the page
     * that explains the failure included. So the read failing means no
     * pages, never a throwable escaping into boot.
     */
    public function testAnUnreadableDatabaseCostsThePagesAndNotTheRequest(): void
    {
        $broken = new class ($this->pdo) extends TextPageRepository {
            public function findActive(): array
            {
                throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused');
            }
        };

        $router = new Router();
        $pages = TextPageRouteRegistrar::register($router, $broken);

        $this->assertSame([], $pages);
        $this->assertNull($router->resolve(self::get('/pages/quoi-que-ce-soit')));
    }

    /**
     * A missing table is the same answer as a missing server: the
     * feature is absent, the request is not.
     *
     * This is the shape of the very first request after a deployment
     * that has not migrated yet.
     */
    public function testAMissingTableIsNotAFatalEither(): void
    {
        $this->pdo->exec('DROP TABLE text_pages');

        $router = new Router();
        $pages = TextPageRouteRegistrar::register($router, new TextPageRepository($this->pdo));

        $this->assertSame([], $pages);
    }

    /**
     * The breadcrumb names the menu the page was filed in, so a page
     * reached directly says where it lives.
     */
    public function testTheBreadcrumbNamesThePageAndItsSection(): void
    {
        $this->service->create('Bulle Safe', 'La Bulle Safe', MenuBuilder::MENU_NOTRE_UNITE, null);

        $router = new Router();
        TextPageRouteRegistrar::register($router, $this->repository);
        $route = $router->resolve(self::get('/pages/la-bulle-safe'));

        $this->assertNotNull($route);
        $this->assertSame('Bulle Safe', $route->breadcrumb['label'] ?? null);
        $this->assertSame(
            [MenuBuilder::labelFor(MenuBuilder::MENU_NOTRE_UNITE)],
            $route->breadcrumb['parents'] ?? null
        );
    }
}
