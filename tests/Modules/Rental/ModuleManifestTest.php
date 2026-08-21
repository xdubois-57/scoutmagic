<?php

declare(strict_types=1);

namespace Tests\Modules\Rental;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest is validated at load time by Core\Module\ModuleManager, so a
 * mistake here takes the module out of every menu with only a badge on the
 * Modules page to show for it. Same precedent as
 * Tests\Modules\Groups\ModuleManifestTest.
 */
class ModuleManifestTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/rental/module.json');
    }

    public function testTheManifestParsesAndValidates(): void
    {
        $this->assertSame('rental', $this->manifest->id);
        $this->assertSame('Locations', $this->manifest->name);
        $this->assertFalse(
            $this->manifest->enabledByDefault,
            'Renting out assets is not something every unit does — this must stay opt-in.'
        );
    }

    /**
     * Pinned deliberately: ModuleManager only re-applies schema.sql when the
     * manifest version is greater than the installed one, so a schema change
     * without a bump is silently a no-op on every already-enabled install
     * (AGENTS.md). Editing schema.sql should break this test — the fix is to
     * bump module.json, which is the whole point.
     */
    public function testTheVersionIsBumpedWheneverTheSchemaChanges(): void
    {
        $this->assertSame('1.8.0', $this->manifest->version);
    }

    /**
     * Every route that CHANGES something is POST — never GET, which a
     * crawler, a prefetch or an <img src> could trigger without the visitor
     * ever meaning to, and which carries no CSRF token.
     */
    public function testEveryStateChangingRouteIsPostOnly(): void
    {
        $writePaths = [
            '/mes-locations/statut',
            '/mes-locations/option',
            '/mes-locations/commentaire',
            '/mes-locations/ligne',
            '/mes-locations/proposition',
            '/mes-locations/demande',
            '/mes-locations/blocage',
            '/mes-locations/blocage-supprimer',
            '/mes-locations/caution',
            '/mes-locations/gabarit',
            '/mes-locations/document-texte',
            '/mes-locations/document-generer',
            '/mes-locations/document-envoyer',
            '/mes-locations/document-ajouter',
            '/mes-locations/document-supprimer',
            '/mes-locations/facturation',
            '/mes-locations/compteur',
            '/mes-locations/inventaire-modele',
            '/mes-locations/releve',
            '/mes-locations/inventaire',
            '/mes-locations/incident',
            '/mes-locations/incident-decision',
            '/mes-locations/decompte',
            '/mes-locations/decompte-valider',
            '/mes-locations/calendrier-publication',
            '/locations/suivi/{id}/{token}/demande',
            '/locations/suivi/{id}/{token}/reponse',
            '/admin/locations/payments',
            '/admin/locations/create',
            '/admin/locations/general',
            '/admin/locations/managers',
            '/admin/locations/archive',
            '/admin/locations/restore',
            '/admin/locations/delete',
        ];

        $seen = [];
        foreach ($this->manifest->routes as $route) {
            if (in_array($route['path'], $writePaths, true)) {
                $seen[] = $route['path'];
                $this->assertSame('POST', $route['method'], $route['path'] . ' changes state and must be POST.');
            }
        }

        sort($seen);
        $expected = $writePaths;
        sort($expected);
        $this->assertSame($expected, $seen, 'A write route disappeared from the manifest without this list moving.');
    }

    public function testEveryRouteDeclaresARoleMin(): void
    {
        // ModuleManager rejects a route without one (fail-safe: no access by
        // default), so this catches the mistake here rather than as a module
        // that silently refuses to load.
        foreach ($this->manifest->routes as $route) {
            $this->assertArrayHasKey('role_min', $route);
            $this->assertNotSame('', $route['role_min'], $route['path'] . ' must declare a role_min.');
        }
    }

    /**
     * The two role boundaries this module's design rests on.
     *
     * `Espace chefs d'U > Locations` is `admin`: it is the structural
     * configuration of the unit's assets. The managed space is
     * `identified` — deliberately as low as a logged-in visitor gets,
     * because a manager is explicitly not required to be a chief (§6.3) —
     * and Service\RentalAuthorizationService is what actually protects it.
     * Raising it would lock ordinary managers out of their own assets;
     * lowering the config routes would hand asset configuration to anyone.
     */
    public function testTheConfigurationSpaceIsAdminAndTheManagedSpaceIsIdentified(): void
    {
        $roles = [];
        foreach ($this->manifest->routes as $route) {
            $roles[$route['path']] = $route['role_min'];
        }

        foreach ($roles as $path => $role) {
            if (str_starts_with($path, '/admin/locations')) {
                $this->assertSame('admin', $role, $path . ' configures assets and must require admin.');
            }
        }

        $this->assertSame('identified', $roles['/mes-locations']);
        // The whole managed space sits at the same floor: raising one route
        // would lock ordinary managers out of part of their own asset,
        // lowering one would open it to any logged-in visitor.
        foreach ($roles as $path => $role) {
            if (str_starts_with($path, '/mes-locations')) {
                $this->assertSame('identified', $role, $path . ' is part of the managed space.');
            }
        }

        $this->assertSame('public', $roles['/locations']);
        $this->assertSame('public', $roles['/locations/{slug}']);
    }

    /**
     * ARCHITECTURE.md §7.1: the Router matches in registration order and
     * stops at the first pattern match, so a `{param}` segment happily
     * swallows a literal word declared later. ModuleManifest validates this
     * at load time; asserting it here turns a silently unreachable route
     * into a failing test instead of a 404 nobody can explain.
     */
    public function testNoRouteIsShadowedByAnEarlierOne(): void
    {
        // fromFile() runs validateNoShadowedRoutes() (among every other
        // manifest rule) and throws on any violation, so parsing the real
        // file IS the assertion — there is no separate accessor to query,
        // and inventing one here would test a copy of the rule rather than
        // the rule the loader actually applies.
        $manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/rental/module.json');

        $this->assertNotSame([], $manifest->routes);
        $this->assertCount(
            count($manifest->routes),
            array_unique(array_map(
                fn(array $route) => $route['method'] . ' ' . $route['path'],
                $manifest->routes
            )),
            'Two routes share a method and path — the second is unreachable.'
        );
    }

    /**
     * The public asset page is `/locations/{slug}`, and the index is
     * `/locations`. Declaring the wildcard route BEFORE the index would be
     * harmless (different segment counts), but a future `/locations/xyz`
     * literal route would not be — this pins the ordering that keeps that
     * safe.
     */
    public function testTheWildcardAssetRouteIsDeclaredAfterTheIndex(): void
    {
        $paths = array_column($this->manifest->routes, 'path');

        $this->assertLessThan(
            array_search('/locations/{slug}', $paths, true),
            array_search('/locations', $paths, true)
        );
    }

    public function testEverySettingDeclaresANonEmptyDescription(): void
    {
        // AGENTS.md § Module creation checklist: description is NOT NULL on
        // every parameter, because the settings page renders it as the only
        // explanation an admin ever gets.
        $this->assertNotSame([], $this->manifest->settings);

        foreach ($this->manifest->settings as $setting) {
            $this->assertArrayHasKey('description', $setting);
            $this->assertNotSame('', trim((string) $setting['description']), $setting['key'] . ' needs a description.');
        }
    }

    public function testEveryDeclaredScheduledTaskHasARealHandlerClass(): void
    {
        // A key declared here with no class behind it fails at run time as
        // "No handler registered", from a background pass nobody is
        // watching — the worst place to discover a typo.
        $this->assertNotSame([], $this->manifest->scheduledTasks);

        foreach ($this->manifest->scheduledTasks as $task) {
            $this->assertArrayHasKey('key', $task);
            $this->assertArrayHasKey('handler', $task);
            $this->assertTrue(
                class_exists((string) $task['handler']),
                $task['handler'] . ' is declared in module.json but does not exist.'
            );
            $this->assertContains(
                \Core\Scheduler\TaskHandlerInterface::class,
                class_implements((string) $task['handler']) ?: [],
                $task['handler'] . ' must implement TaskHandlerInterface.'
            );
        }
    }

    public function testTheHoldExpiryTaskIsDeclaredUnderTheKeyItsHandlerUses(): void
    {
        // The handler re-schedules itself under its own TASK_KEY; if that
        // ever drifts from the manifest, the first run works (it was
        // bootstrapped by hand) and every later one silently fails.
        $keys = array_column($this->manifest->scheduledTasks, 'key');

        $this->assertContains(\Modules\Rental\Task\ExpireRentalHoldsHandler::TASK_KEY, $keys);
    }

    /**
     * §6.23 is explicit: the meter and inventory pages are WRITE pages and
     * must never be cached for offline use.
     *
     * The `offline` section is opt-in, so the exclusion is achieved by the
     * module declaring nothing at all — and this test is what stops a
     * future iteration from casually adding "/mes-locations" to it and
     * silently making an inventory form fillable in a cellar with no
     * signal, to be lost on the way home.
     */
    public function testNoPageOfThisModuleIsEverCachedForOfflineUse(): void
    {
        $this->assertSame([], $this->manifest->offline);
    }

    public function testTheModuleDeclaresNoCookies(): void
    {
        // Nothing here sets a cookie yet. If that changes, the declaration
        // has to change with it (AGENTS.md § Cookie consent) — this test is
        // what makes that impossible to forget silently.
        $this->assertSame([], $this->manifest->cookies);
    }
}
