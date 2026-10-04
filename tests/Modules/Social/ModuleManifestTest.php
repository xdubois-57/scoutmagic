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
            if (str_starts_with($route['path'], '/medias-sociaux')) {
                // The composer and the history: a chief at the floor,
                // narrowed by the gallery's and the news module's own rule,
                // or the communication's author (ShareSourceResolver).
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

    /**
     * The schema gained `source_kind` / `source_id` for IT-01, and the
     * chantier asks for the manifest version to go up with it.
     *
     * **Not because the bump is what ships the columns** — it is not, and
     * believing it is would be the dangerous reading of this pin.
     * `Core\Database\SchemaFiles` migrates every declared schema in one
     * pass at deploy time, precisely because the old per-module behaviour
     * applied a schema change only if somebody remembered the bump, and a
     * forgotten one stayed invisible until a query failed against a
     * column nobody had added ({@see \Core\Module\ModuleManager}, where
     * the comparison that remains drives the pruning of settings the new
     * manifest stopped declaring). The version is the module's stated
     * version, shown in the registry; this pin is meant to break, so a
     * schema change is a deliberate decision about it rather than a
     * silent omission. Raised in review on the pull request for IT-01.
     */
    public function testTheVersionRisesWithTheSchema(): void
    {
        $this->assertSame('1.1.0', $this->manifest['version']);
    }

    /**
     * There is one composer, reached from « Partager » as well as from
     * « Nouvelle communication »: the two dedicated share pages are gone,
     * and a route that brought one back would be the regression.
     */
    public function testThereIsNoDedicatedSharePageAnyMore(): void
    {
        $paths = array_column($this->manifest['routes'], 'path');

        $this->assertContains('/medias-sociaux/nouvelle/{kind}/{id}', $paths);
        foreach ($paths as $path) {
            $this->assertStringStartsNotWith('/partage/album', $path);
            $this->assertStringStartsNotWith('/partage/actualite', $path);
            $this->assertStringStartsNotWith('/communications', $path);
        }
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
                'index', 'connect', 'callback', 'show',
                'history', 'create', 'createFromSource', 'edit', 'preview', 'previewSource', 'picker', 'confirmRetry',
                // The card's background, for the browser that now draws
                // the card (issue #706, IT-02): a read, like the composed
                // preview beside it.
                'background', 'backgroundSource',
            ], true);
            $this->assertSame($reads ? 'GET' : 'POST', $route['method'], $route['path'] . ' → ' . $route['action']);
        }
    }
}
