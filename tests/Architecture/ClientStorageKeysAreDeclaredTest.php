<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Cookie\CookieRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Every persistent storage key the client-side scripts write is declared,
 * so the cookie preferences page and the consent banner really are the
 * complete picture of the site's local storage footprint they claim to be
 * (AGENTS.md § Cookie consent).
 *
 * Two keys were not (issue #233): the service worker's own `offline-config`
 * cache, whose two siblings WERE declared with a comment explaining that
 * they are declared « so [the page] stay[s] a complete picture », and the
 * `groups` drafts, whose module.json said `"cookies": []`. Nothing failed;
 * the page simply listed nine lines while eleven things were stored, and
 * the only way to notice was to read every client script.
 *
 * **What this scans, and what it deliberately does not.** Persistent
 * storage only: `localStorage` and Cache Storage, the two that outlive the
 * tab and that the preferences page exists to describe. `sessionStorage`
 * is not declared anywhere in this codebase and is not asked to be — it is
 * gone when the tab closes, it cannot follow a visitor across visits, and
 * treating it as a cookie would put « scoutmagic:help-assistant:question »
 * on a privacy page where it means nothing.
 */
class ClientStorageKeysAreDeclaredTest extends TestCase
{
    /**
     * Keys reached through `sessionStorage` in a file that also uses
     * persistent storage — named here rather than silently skipped, so
     * that moving one to `localStorage` has to come here and explain
     * itself.
     */
    private const SESSION_ONLY = [
        // offline-prefetch.js: « has this launch of the installed app
        // already pre-downloaded? ». A launch is exactly a tab's life.
        'scoutmagic-offline-prefetch',
    ];

    /** A separator or a fragment is not a storage key. */
    private const MINIMUM_KEY_LENGTH = 3;

    public function testEveryPersistentStorageKeyWrittenByTheClientIsDeclared(): void
    {
        $declaredHeads = self::declaredHeads();
        $undeclared = [];

        foreach (self::storageKeysInClientScripts() as $key => $files) {
            foreach ($declaredHeads as $head) {
                if ($key === $head || str_starts_with($key, $head)) {
                    continue 2;
                }
            }
            $undeclared[] = "{$key} (written by " . implode(', ', $files) . ')';
        }

        $this->assertSame(
            [],
            $undeclared,
            "These persistent storage keys are written by the client and declared nowhere:\n  "
            . implode("\n  ", $undeclared)
            . "\nDeclare each one in core/Cookie/CookieRegistry.php (core) or its module.json's `cookies`"
            . " section, so the preferences page stays the complete picture it promises."
        );
    }

    /**
     * The other direction, which is how a declaration rots: a name listed
     * on the preferences page that nothing writes any more tells a
     * visitor something untrue about their own browser.
     */
    public function testEveryDeclaredCacheOrLocalStorageEntryIsStillWritten(): void
    {
        $written = array_keys(self::storageKeysInClientScripts());
        $orphans = [];

        foreach (self::declaredHeads() as $name => $head) {
            // HTTP cookies are set server-side and are not in this scan.
            if (!self::looksClientWritten($name)) {
                continue;
            }
            foreach ($written as $key) {
                if ($key === $head || str_starts_with($key, $head)) {
                    continue 2;
                }
            }
            $orphans[] = $name;
        }

        $this->assertSame([], $orphans, 'Declared but written by no client script: ' . implode(', ', $orphans));
    }

    /**
     * The declared names that a browser script — not a Set-Cookie header —
     * is responsible for. Recognised by the placeholder syntax the Cache
     * Storage entries use, or by membership in the short list of
     * client-written localStorage keys.
     */
    private static function looksClientWritten(string $name): bool
    {
        return str_contains($name, '{')
            || in_array($name, ['theme_preference', 'camps_map_collapsed', 'offline-config'], true);
    }

    /**
     * Declared name => its literal head (everything before the first
     * placeholder), which is what a script's own concatenation produces.
     *
     * @return array<string, string>
     */
    private static function declaredHeads(): array
    {
        $names = array_column(CookieRegistry::getCoreCookies(), 'name');

        foreach (glob(dirname(__DIR__, 2) . '/modules/*/module.json') ?: [] as $manifestPath) {
            $manifest = json_decode((string) file_get_contents($manifestPath), true);
            foreach ((is_array($manifest) ? ($manifest['cookies'] ?? []) : []) as $cookie) {
                if (is_array($cookie) && is_string($cookie['name'] ?? null)) {
                    $names[] = $cookie['name'];
                }
            }
        }

        $heads = [];
        foreach ($names as $name) {
            $brace = strpos($name, '{');
            $heads[$name] = $brace === false ? $name : substr($name, 0, $brace);
        }

        return $heads;
    }

    /**
     * Every persistent storage key literal the client scripts carry, as
     * key => the files writing it.
     *
     * Three shapes, because that is how the code actually spells them: a
     * literal passed straight to `localStorage`/`caches.open()`, a literal
     * held in a `…KEY`/`…Key`/`…CACHE_NAME`/`…PREFIX` constant, and a
     * literal built inside a `…Key()` helper (the reply drafts, whose key
     * is a function of the post).
     *
     * @return array<string, list<string>>
     */
    private static function storageKeysInClientScripts(): array
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/public/assets/js/*.js') ?: [],
            [$root . '/public/sw.js'],
            glob($root . '/modules/*/assets/js/*.js') ?: []
        );

        $found = [];
        foreach ($files as $path) {
            $source = (string) file_get_contents($path);
            if (!str_contains($source, 'localStorage') && !str_contains($source, 'caches.open')) {
                continue;
            }

            foreach (self::keyLiteralsIn($source) as $key) {
                if (strlen($key) < self::MINIMUM_KEY_LENGTH || in_array($key, self::SESSION_ONLY, true)) {
                    continue;
                }
                $found[$key][] = str_replace($root . '/', '', $path);
            }
        }

        ksort($found);

        return array_map(static fn (array $paths): array => array_values(array_unique($paths)), $found);
    }

    /**
     * @return list<string>
     */
    private static function keyLiteralsIn(string $source): array
    {
        $literals = [];

        foreach ([
            "/(?:var|let|const)\\s+\\w*(?:KEY|Key|CACHE_NAME|PREFIX)\\s*=\\s*'([^']+)'/",
            "/(?:window\\.)?localStorage\\.(?:set|get|remove)Item\\(\\s*'([^']+)'/",
            "/caches\\.open\\(\\s*'([^']+)'/",
        ] as $pattern) {
            preg_match_all($pattern, $source, $matches);
            $literals = array_merge($literals, $matches[1]);
        }

        // A `…Key()` helper's own literals: the key is assembled inside
        // it, so no assignment and no call site carries the string.
        preg_match_all("/function\\s+\\w*[Kk]ey\\w*\\s*\\([^)]*\\)\\s*\\{(.*?)\\n    \\}/s", $source, $bodies);
        foreach ($bodies[1] as $body) {
            preg_match_all("/'([^']+)'/", $body, $inner);
            $literals = array_merge($literals, $inner[1]);
        }

        return array_values(array_unique($literals));
    }
}
