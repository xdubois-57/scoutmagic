<?php

declare(strict_types=1);

namespace Tests\Modules\Registration;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Where « Réinscription » lives, and at what floor.
 *
 * It sat under **Configuration** at `superadmin`, which put a once-a-year
 * campaign behind the one role a unit may not have at hand — and put it
 * three menus away from « Inscriptions », which is the same job a year
 * apart: deciding when the unit asks the families a question, and
 * watching the answers come in. Both belong to the chef d'unité.
 *
 * The path is deliberately unchanged. A page that moves menu is not a
 * page that moves address: the bookmarks, the forms' own `action`s and
 * the help topic's `paths` all point at `/config/reinscription`, and
 * renaming it would break the three for a menu decision.
 *
 * A menu placement is exactly the kind of thing that drifts back without
 * anybody deciding it should, which is why it is pinned here rather than
 * only in a manifest nobody re-reads.
 */
class ReenrollmentCampaignPlacementTest extends TestCase
{
    /** @var array<int, array<string, mixed>> */
    private array $routes = [];

    protected function setUp(): void
    {
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/registration/module.json');
        foreach ($manifest->routes as $route) {
            if (str_starts_with($route['path'], '/config/reinscription')) {
                $this->routes[] = $route;
            }
        }

        $this->assertNotSame([], $this->routes, 'la page de campagne a disparu du manifeste');
    }

    /**
     * @return array<string, mixed>
     */
    private function page(): array
    {
        foreach ($this->routes as $route) {
            if ($route['path'] === '/config/reinscription' && $route['method'] === 'GET') {
                return $route;
            }
        }

        $this->fail('GET /config/reinscription is not declared.');
    }

    public function testTheCampaignPageSitsInTheChefsDUniteSpace(): void
    {
        $page = $this->page();

        $this->assertSame('espace_admin', $page['menu']);
        $this->assertSame('services', $page['menu_group'], 'beside « Inscriptions », which is the same job a year apart');
        $this->assertSame("Espace chefs d'U", $page['breadcrumb']['parents'][0]);
    }

    /**
     * A page a chef d'unité can open and not submit is worse than one
     * they cannot open: the floor has to be the same on every route of
     * the screen, the reminder included.
     */
    public function testEveryRouteOfTheScreenCarriesTheSameFloor(): void
    {
        foreach ($this->routes as $route) {
            $this->assertSame(
                'admin',
                $route['role_min'],
                "{$route['method']} {$route['path']} n'est pas au même étage que la page"
            );
        }
    }

    /**
     * Its neighbour, and the reason the placement is what it is — if
     * « Inscriptions » ever moves, this test says so rather than leaving
     * the two apart.
     */
    public function testItLandsWhereInscriptionsAlreadyIs(): void
    {
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/registration/module.json');

        foreach ($manifest->routes as $route) {
            if ($route['path'] === '/config/inscriptions' && $route['method'] === 'GET') {
                $page = $this->page();
                $this->assertSame($route['menu'], $page['menu']);
                $this->assertSame($route['menu_group'], $page['menu_group']);
                $this->assertSame($route['role_min'], $page['role_min']);

                return;
            }
        }

        $this->fail('GET /config/inscriptions is not declared.');
    }

    /**
     * The help topic follows the page: a reader who opens the panel from
     * the campaign screen must be offered it, and a `role_min` above the
     * page's own would hide it from exactly the people the page is for.
     */
    public function testTheHelpTopicFollowedThePage(): void
    {
        $topic = (string) file_get_contents(
            dirname(__DIR__, 3) . '/modules/registration/help/config-reinscription.md'
        );

        $this->assertStringContainsString('paths: /config/reinscription', $topic);
        $this->assertStringContainsString("category: Espace chefs d'U", $topic);
        $this->assertStringContainsString('role_min: admin', $topic);
    }
}
