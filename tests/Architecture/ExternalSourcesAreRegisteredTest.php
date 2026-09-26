<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\ExternalSource\ExternalSourceKind;
use Core\ExternalSource\ExternalSources;
use PHPUnit\Framework\TestCase;

/**
 * Every federation page and every provider console or legal page the
 * shipped code names is in the register of external sources — or the
 * weekly check and the sixth release gate never look at it (issue #355).
 *
 * A URL added to a template without a line in
 * Core\ExternalSource\ExternalSources is exactly how the site came to depend
 * on five lesscouts.be pages that nothing watched: each was right the day
 * it was written, and none said anything the day it moved.
 *
 * Three directions, because the register is only worth what it matches:
 *
 * - forwards: a guarded URL in shipped code must be registered, AND the
 *   file must be listed in that entry's `usedIn`, so the "where is it
 *   used" column a divergence report prints stays true;
 * - backwards: a file listed in `usedIn` must still name its URL (or the
 *   registry constant carrying it), and every content source that is not
 *   marked upcoming must be used somewhere;
 * - the two shipped defaults that cannot reference PHP — `module.json`
 *   and `schema/core.sql` — must equal the registry value.
 */
final class ExternalSourcesAreRegisteredTest extends TestCase
{
    /** Shipped code: what an installed site runs or renders. */
    private const SCANNED = ['core', 'modules', 'public', 'schema', 'config', 'docs/help'];

    private const EXTENSIONS = ['php', 'twig', 'json', 'sql', 'html', 'js', 'md', 'dist', 'css'];

    /** Third-party code, and the register itself. */
    private const SKIPPED_PREFIXES = [
        'public/assets/vendor/',
        'modules/llm_connector/vendor/',
        'core/ExternalSource/ExternalSources.php',
    ];

    /**
     * Hosts (and, where the host also serves API endpoints or unrelated
     * pages, path prefixes) whose URLs must be registered. API endpoints,
     * map tiles and example URLs are out of scope on purpose (issue #355):
     * a dead endpoint fails its own feature loudly.
     *
     * @var array<string, string>
     */
    private const GUARDED = [
        'lesscouts.be' => '',
        'console.anthropic.com' => '',
        'www.anthropic.com' => '/legal',
        'console.mistral.ai' => '',
        'legal.mistral.ai' => '',
        'console.scaleway.com' => '',
        'www.scaleway.com' => '/en/',
        'console.cloud.google.com' => '',
        'console.hetzner.cloud' => '',
        'dash.cloudflare.com' => '',
        'www.ovh.com' => '/manager',
        'eu.api.ovh.com' => '/createApp',
        'myaccount.google.com' => '',
    ];

    /**
     * Legal pages that sit on a host guarded only by path above, or on a
     * host the list does not guard at all, and that the register lists
     * anyway because a help text or the RGPD content sends a reader there.
     * Matched exactly, so the register and this scan agree on them.
     */
    private const ALSO_GUARDED = [
        'https://www.anthropic.com/privacy',
        'https://mistral.ai/terms/#privacy-policy',
        'https://www.cloudflare.com/privacypolicy/',
    ];

    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * @return array<string, string> repository-relative path => contents
     */
    private static function shippedFiles(): array
    {
        $files = [];
        foreach (self::SCANNED as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root() . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                /** @var \SplFileInfo $file */
                if (!$file->isFile() || !in_array($file->getExtension(), self::EXTENSIONS, true)) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen(self::root()) + 1);
                foreach (self::SKIPPED_PREFIXES as $prefix) {
                    if (str_starts_with($relative, $prefix)) {
                        continue 2;
                    }
                }
                $files[$relative] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertNotEmpty($files, 'No shipped file was found, so this test would pass over anything.');

        return $files;
    }

    /**
     * Every guarded URL in one file's contents.
     *
     * An input's `placeholder="https://lesscouts.be/..."` is an example of
     * what to type, not a page anybody is sent to, so it is skipped by its
     * context rather than by a list of exceptions.
     *
     * @return list<string>
     */
    public static function guardedUrlsIn(string $contents): array
    {
        preg_match_all('#https?://[^\s"\'<>()`\\\\{}|^]+#', $contents, $matches, PREG_OFFSET_CAPTURE);

        $urls = [];
        foreach ($matches[0] as [$raw, $offset]) {
            if (str_ends_with(substr($contents, max(0, $offset - 13), min(13, $offset)), 'placeholder="')) {
                continue;
            }
            $url = rtrim($raw, '.,;:');
            if (self::isGuarded($url)) {
                $urls[] = $url;
            }
        }

        return $urls;
    }

    private static function isGuarded(string $url): bool
    {
        if (in_array($url, self::ALSO_GUARDED, true)) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach (self::GUARDED as $guardedHost => $pathPrefix) {
            $hostMatches = $host === $guardedHost || str_ends_with($host, '.' . $guardedHost);
            if ($hostMatches && ($pathPrefix === '' || str_starts_with($path, $pathPrefix))) {
                return true;
            }
        }

        return false;
    }

    /**
     * The registry constant whose value is this URL, if one exists — a
     * file that reads `ExternalSources::FEDERATION_PAGE` uses the page
     * without spelling its address.
     */
    private static function constantFor(string $url): ?string
    {
        $constants = (new \ReflectionClass(ExternalSources::class))->getConstants(\ReflectionClassConstant::IS_PUBLIC);
        $name = array_search($url, $constants, true);

        return is_string($name) ? 'ExternalSources::' . $name : null;
    }

    private static function names(string $contents, string $url): bool
    {
        $constant = self::constantFor($url);

        return str_contains($contents, $url) || ($constant !== null && str_contains($contents, $constant));
    }

    public function testEveryGuardedUrlInShippedCodeIsRegisteredForThatFile(): void
    {
        $unregistered = [];
        foreach (self::shippedFiles() as $path => $contents) {
            foreach (self::guardedUrlsIn($contents) as $url) {
                $source = ExternalSources::byUrl($url);
                if ($source === null) {
                    $unregistered[] = "{$path}: {$url} is not in Core\\ExternalSource\\ExternalSources";
                } elseif (!in_array($path, $source->usedIn, true)) {
                    $unregistered[] = "{$path}: {$url} is registered as '{$source->id}', whose usedIn omits this file";
                }
            }
        }

        $this->assertSame(
            [],
            array_values(array_unique($unregistered)),
            "External pages named by shipped code but not watched by the external sources check. Register "
            . "each one (with this file in its usedIn) so the weekly check and the release gate see it."
        );
    }

    public function testEveryFileARegisteredSourceListsStillNamesIt(): void
    {
        $stale = [];
        foreach (ExternalSources::all() as $source) {
            foreach ($source->usedIn as $path) {
                $contents = @file_get_contents(self::root() . '/' . $path);
                if ($contents === false) {
                    $stale[] = "{$source->id}: {$path} does not exist";
                } elseif (!self::names($contents, $source->url)) {
                    $stale[] = "{$source->id}: {$path} no longer names {$source->url}";
                }
            }
        }

        $this->assertSame([], $stale, 'The register says a page is used where it no longer is.');
    }

    public function testEveryContentSourceIsUsedUnlessMarkedUpcoming(): void
    {
        $unused = [];
        foreach (ExternalSources::all() as $source) {
            if ($source->kind !== ExternalSourceKind::Content || $source->upcoming) {
                continue;
            }
            if ($source->usedIn === []) {
                $unused[] = $source->id;
            }
        }

        $this->assertSame(
            [],
            $unused,
            'A content source nothing uses is checked every week for no reader. Mark it upcoming, or remove it.'
        );
    }

    /**
     * The register itself: one entry per id and per URL, https, and a
     * content source that says what it must contain.
     */
    public function testTheRegisterIsWellFormed(): void
    {
        $ids = [];
        $urls = [];
        foreach (ExternalSources::all() as $source) {
            $this->assertArrayNotHasKey($source->id, $ids, "Duplicate id {$source->id}");
            $this->assertArrayNotHasKey($source->url, $urls, "Duplicate URL {$source->url}");
            $ids[$source->id] = true;
            $urls[$source->url] = true;

            $this->assertStringStartsWith('https://', $source->url, "{$source->id} is not https");
            $this->assertMatchesRegularExpression('/^[a-z0-9-]+$/', $source->id);
            if ($source->kind === ExternalSourceKind::Content) {
                $this->assertNotSame([], $source->expectedContent, "{$source->id} checks no content");
            }
        }

        $this->assertNotNull(ExternalSources::byId(ExternalSources::FEES_PAGE_ID));
        $this->assertTrue(ExternalSources::byId('federal-badge-placement')?->upcoming);
    }

    // ── The shipped defaults that cannot read PHP ──────────────────────

    public function testTheFeesSettingDefaultIsTheRegisteredFeesPage(): void
    {
        $manifest = json_decode((string) file_get_contents(self::root() . '/modules/fees/module.json'), true);
        $this->assertIsArray($manifest);

        $defaults = array_column($manifest['settings'] ?? [], 'default_value', 'key');

        $this->assertSame(
            ExternalSources::FEES_PAGE,
            $defaults['fees_federal_scale_url'] ?? null,
            'modules/fees/module.json and the register disagree on the federal fees page.'
        );
    }

    public function testTheAgeBranchExplanationDefaultIsTheRegisteredScoutPathPage(): void
    {
        $schema = (string) file_get_contents(self::root() . '/schema/core.sql');

        $this->assertSame(
            1,
            preg_match("/explanation_url\\s+VARCHAR\\(\\d+\\)\\s+NOT NULL\\s+DEFAULT\\s+'([^']*)'/", $schema, $matches),
            'age_branches.explanation_url no longer declares a default this test can read.'
        );
        $this->assertSame(
            ExternalSources::SCOUT_PATH_PAGE,
            $matches[1],
            'schema/core.sql and the register disagree on the scout path page.'
        );
    }

    // ── The scanner itself ─────────────────────────────────────────────

    public function testTheScannerFindsWhatItGuardsAndNothingElse(): void
    {
        $contents = <<<'TXT'
            <a href="https://lesscouts.be/fr/nouvelle-page">x</a>
            <input placeholder="https://lesscouts.be/...">
            <a href="https://console.hetzner.cloud/projects">console</a>
            endpoint https://api.anthropic.com/v1/messages and https://www.anthropic.com/legal/aup.
            tiles https://tile.openstreetmap.org/{z}/{x}/{y}.png, api https://eu.api.ovh.com/1.0
            TXT;

        $this->assertSame(
            [
                'https://lesscouts.be/fr/nouvelle-page',
                'https://console.hetzner.cloud/projects',
                'https://www.anthropic.com/legal/aup',
            ],
            self::guardedUrlsIn($contents)
        );
    }
}
