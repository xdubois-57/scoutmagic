<?php

declare(strict_types=1);

namespace Tests\Modules\Covoiturage;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest of the carpool module: optional, two spaces at two floors
 * (D2), every state change a POST, every setting described.
 */
final class ModuleManifestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $manifest;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/modules/covoiturage/module.json';
        ModuleManifest::fromFile($path);
        $this->manifest = json_decode((string) file_get_contents($path), true);
    }

    public function testTheModuleIsOptionalAndOffByDefault(): void
    {
        $this->assertFalse($this->manifest['enabled_by_default']);
    }

    public function testMembersOfferAndAskWhileOnlyAChiefOrganizes(): void
    {
        foreach ($this->manifest['routes'] as $route) {
            $expected = str_starts_with($route['path'], '/covoiturage/organiser') ? 'chief' : 'identified';
            $this->assertSame($expected, $route['role_min'], $route['method'] . ' ' . $route['path']);
            $this->assertSame(
                $expected === 'chief' ? 'espace_chefs' : 'espace_animes',
                $route['menu'],
                $route['path']
            );
        }
    }

    public function testEveryStateChangeIsAPost(): void
    {
        foreach ($this->manifest['routes'] as $route) {
            $action = $route['action'];
            // locateAddress (#642) writes only its own cache and quota rows,
            // never anything a person sees: a read, like the event search.
            // travel (#703) is the same lookup followed by a route.
            $reads = in_array($action, [
                'index', 'show', 'offerForm', 'editOffer', 'create', 'edit', 'searchEvents', 'eventLocations',
                'locateAddress', 'travel',
            ], true);
            $this->assertSame($reads ? 'GET' : 'POST', $route['method'], $route['path'] . ' → ' . $action);
        }
    }

    public function testEverySettingSaysWhatItDoes(): void
    {
        foreach ($this->manifest['settings'] as $setting) {
            $this->assertNotSame('', trim($setting['description']), $setting['key']);
        }
        $this->assertSame('30', $this->manifest['settings'][0]['default_value'], 'D9: 30 days by default.');
    }

    /**
     * Issue #692: a refused form is shown again by the POST route, so the
     * POST carries the same breadcrumb as the page it re-renders — or the
     * refused page came back with an empty one.
     */
    public function testAFormShownAgainAfterARefusalKeepsItsBreadcrumb(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 3) . '/modules/covoiturage/module.json'),
            true
        );
        $byRoute = [];
        foreach ($manifest['routes'] as $route) {
            $byRoute[$route['method'] . ' ' . $route['path']] = $route;
        }

        foreach (['/covoiturage/organiser/nouveau', '/covoiturage/organiser/{id}/modifier'] as $path) {
            $this->assertArrayHasKey('breadcrumb', $byRoute['POST ' . $path], $path);
            $this->assertSame($byRoute['GET ' . $path]['breadcrumb'], $byRoute['POST ' . $path]['breadcrumb'], $path);
        }
    }
}
