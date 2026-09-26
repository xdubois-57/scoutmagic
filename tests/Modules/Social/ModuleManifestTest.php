<?php

declare(strict_types=1);

namespace Tests\Modules\Social;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * The manifest of the social module: optional, superadmin only, every
 * state change a POST — except the two GETs a browser round trip through
 * Meta needs.
 */
final class ModuleManifestTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $manifest;

    protected function setUp(): void
    {
        $path = dirname(__DIR__, 3) . '/modules/social/module.json';
        ModuleManifest::fromFile($path);
        $this->manifest = json_decode((string) file_get_contents($path), true);
    }

    public function testTheModuleIsOptionalAndOffByDefault(): void
    {
        $this->assertFalse($this->manifest['enabled_by_default']);
    }

    public function testConfigurationIsSuperadminAndSharingIsForChiefs(): void
    {
        foreach ($this->manifest['routes'] as $route) {
            if ($route['path'] === '/partage/carte/{token}') {
                continue;
            }
            if (str_starts_with($route['path'], '/partage/')) {
                // Sharing: a chief at the floor, narrowed by the gallery's
                // and the news module's own rule (ShareSourceResolver).
                $this->assertSame('chief', $route['role_min'], $route['path']);
                $this->assertSame('espace_chefs', $route['menu'], $route['path']);
                continue;
            }
            $this->assertSame('superadmin', $route['role_min'], $route['path']);
            $this->assertSame('configuration', $route['menu'], $route['path']);
            $this->assertStringStartsWith('/config/reseaux-sociaux', $route['path']);
        }
    }

    /**
     * The one public route: Meta's servers fetch the card anonymously.
     * Anything else public in this module would be a mistake.
     */
    public function testTheCardRouteIsTheOnlyPublicOne(): void
    {
        $public = array_values(array_filter(
            $this->manifest['routes'],
            static fn (array $route): bool => $route['role_min'] === 'public'
        ));

        $this->assertCount(1, $public);
        $this->assertSame(['GET', '/partage/carte/{token}'], [$public[0]['method'], $public[0]['path']]);
    }

    public function testTheBlurSettingIsNotEditable(): void
    {
        $byKey = array_column($this->manifest['settings'], null, 'key');

        $this->assertFalse($byKey['social_card_blur_ratio']['editable']);
        $this->assertSame('0.05', $byKey['social_card_blur_ratio']['default_value']);
    }

    public function testEveryStateChangeIsAPost(): void
    {
        foreach ($this->manifest['routes'] as $route) {
            $reads = in_array($route['action'], [
                'index', 'connect', 'callback', 'show', 'showAlbum', 'showArticle', 'previewAlbum', 'previewArticle',
            ], true);
            $this->assertSame($reads ? 'GET' : 'POST', $route['method'], $route['path'] . ' → ' . $route['action']);
        }
    }
}
