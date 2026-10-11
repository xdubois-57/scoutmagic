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
        $this->assertSame('1.2.0', $this->manifest['version']);
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

    /**
     * The blur setting is still not editable, and its shipped default is
     * now half what it was (issue #706, IT-02).
     *
     * **What it MEANS changed**, which is why the label moved too: it was
     * « the blur applied to every gallery photo », a rule with a floor
     * under it; it is now « where the composer's slider starts », and the
     * chief decides each publication from there, « Net » included. Not
     * editable because no screen offers it — the slider is the interface.
     *
     * An existing site keeps its own stored 0.05 as its starting
     * position: `SettingRepository::updateDefaultValue()` moves a stored
     * value only for a `url`-typed setting, and the pruning that a
     * version bump drives deletes only `editable` rows. That is the
     * conservative migration, and `CardService`'s docblock says how an
     * administrator aligns it if they want to.
     */
    public function testTheBlurSettingIsNotEditable(): void
    {
        $byKey = array_column($this->manifest['settings'], null, 'key');

        $this->assertFalse($byKey['social_card_blur_ratio']['editable']);
        $this->assertSame('0.025', $byKey['social_card_blur_ratio']['default_value']);
        // The floor is gone, so no text here may promise one.
        $this->assertStringNotContainsString(
            'jamais en dessous',
            $byKey['social_card_blur_ratio']['description'],
            'the setting still promises a floor that no longer exists (issue #706, IT-02).'
        );
    }

    /**
     * The module ships the help its screens promise, and the corpus
     * actually loads it (issue #706, IT-02).
     *
     * **`HelpRegistry` swallows a malformed topic in silence** — an
     * `error_log('ScoutMagic help topic ignored: …')` and nothing else —
     * and `HelpInvariantsTest` checks whatever IS in the corpus, so a
     * topic that stopped loading would be invisible to every other test.
     * This pins the two by id, which is what a screen links to.
     *
     * `l-image-publiee` exists because `medias-sociaux` was at 494 words
     * of the charter's 500 AND had already spent its one warning
     * callout, so IT-02's blur had nowhere to go in it. Splitting was the
     * decision, not squeezing.
     */
    public function testTheModulesHelpTopicsAreAllThere(): void
    {
        $directory = \dirname(__DIR__, 3) . '/modules/social/help';
        $parser = new \Core\Help\HelpFrontMatterParser();
        $ids = [];
        foreach ((array) glob($directory . '/*.md') as $file) {
            // Parsed, not grepped: an id this throws on is exactly the
            // topic the registry would drop in silence.
            $ids[] = $parser->parse((string) $file, 'social')->id;
        }
        sort($ids);

        self::assertSame(
            ['ce-qui-est-parti', 'l-image-publiee', 'medias-sociaux', 'reseaux-sociaux'],
            $ids,
            'A social help topic stopped parsing, or one arrived without this test being told.'
        );
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
