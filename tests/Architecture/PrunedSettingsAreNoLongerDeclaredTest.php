<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * A key a boot-time cleanup deletes may not still be declared anywhere.
 *
 * `SettingRepository::deleteCoreSettings()` is the one way a core setting
 * is ever removed, and `public/index.php` calls it from one-time blocks
 * that prune the rows a retired feature left behind — nothing else does,
 * because a `module_id IS NULL` row survives its `register()` call being
 * deleted and would otherwise sit on Configuration > Réglages for ever.
 *
 * The danger is the mirror image of `drops.sql`'s, and the same shape as
 * {@see ExplicitDropsTargetRetiredColumnsTest} guards there: a list is a
 * literal, the code around it moves on, and nothing reads the two
 * together. A key that is pruned at boot AND still registered by live
 * code is a setting an operator changes and finds reset — on exactly the
 * installations that have not run that cleanup yet, so it works
 * everywhere it is tested and fails on the sites that upgrade.
 *
 * The keys are read from the cleanups themselves rather than listed here:
 * a list repeated in a test is the copy that goes stale.
 */
final class PrunedSettingsAreNoLongerDeclaredTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testEveryKeyAOneTimeCleanupDeletesIsDeclaredByNothing(): void
    {
        $keys = $this->prunedKeys();
        $this->assertNotEmpty($keys, 'No deleteCoreSettings() list was found — has the cleanup moved?');

        foreach ($keys as $key) {
            $this->assertSame(
                [],
                $this->declaringFiles($key),
                sprintf(
                    'public/index.php prunes the setting "%s" at boot while live code still declares it: '
                    . 'an installation that has not run that cleanup loses the value on its next request.',
                    $key
                )
            );
        }
    }

    /**
     * And the marker each cleanup writes goes through the REPOSITORY.
     *
     * `SettingService::set()` asserts the setting is `editable` first, and
     * every one of these markers is registered with `editable = false`
     * precisely because nobody types into them — so writing one that way
     * throws `SettingException` and takes the whole request with it. Not
     * the cleanup, not the page: **every request**, since this runs at boot
     * before anything is routed.
     *
     * It happened on #336, and how it was found is the reason this is a
     * guard rather than a comment. The PHP suite was green — 20 833 tests —
     * because nothing under `tests/` boots `public/index.php`; `phpstan`
     * was green too, the call being perfectly well typed. What reported it
     * was `Checks / Authorization matrix` and `Checks / Dynamic scan
     * (passive)`, the two jobs that drive a real browser at a real server,
     * each failing inside two minutes with `GET /api/version [500]` and the
     * same exception once per request. That is the blind spot AGENTS.md
     * § Static analysis was written for, arriving through another door.
     *
     * Zero is the honest floor: the eleven other markers in that file all
     * use `$settingRepo->updateValue(null, …)`, so this was one line out of
     * step rather than a convention being introduced.
     */
    public function testTheMarkerAOneTimeCleanupWritesDoesNotGoThroughTheEditableGuard(): void
    {
        $bootstrap = $this->frontController();

        $this->assertSame(
            0,
            preg_match_all('/\$settingService->set\(/', $bootstrap),
            'public/index.php writes a setting through SettingService::set(), which refuses any '
                . 'setting registered as non-editable — and every marker this file maintains is. '
                . 'Use $settingRepo->updateValue(null, key, value) as the eleven others do, or '
                . 'setInternal() when the cache must be dropped in the same request. Written the '
                . 'other way it is a 500 on every request, and no PHP test boots this file.'
        );

        // The floor. `assertSame(0, …)` is perfectly satisfied by a reader
        // pointed at an empty string, so the writes it is meant to have
        // walked past have to be counted too.
        $this->assertGreaterThanOrEqual(
            10,
            preg_match_all('/\$settingRepo->updateValue\(/', $bootstrap),
            'far fewer repository writes than this file holds — the reader is broken, not the file clean'
        );
    }

    private function frontController(): string
    {
        $source = file_get_contents(self::ROOT . '/public/index.php');
        self::assertIsString($source, 'public/index.php is unreadable');

        return $source;
    }

    /**
     * Every key named inside a `deleteCoreSettings([...])` literal in the
     * front controller.
     *
     * @return list<string>
     */
    private function prunedKeys(): array
    {
        $source = (string) file_get_contents(self::ROOT . '/public/index.php');

        $keys = [];
        if (preg_match_all('/deleteCoreSettings\(\[(.*?)\]\)/s', $source, $blocks) === false) {
            return [];
        }
        foreach ($blocks[1] as $block) {
            preg_match_all("/'([a-z0-9_]+)'/", $block, $found);
            foreach ($found[1] as $key) {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * The application files still naming $key, front controller aside —
     * it is where the pruning lives and says the name by construction.
     *
     * @return list<string>
     */
    private function declaringFiles(string $key): array
    {
        $found = [];
        foreach (['/core', '/modules'] as $tree) {
            $directory = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::ROOT . $tree, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($directory as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains((string) file_get_contents($file->getPathname()), "'" . $key . "'")) {
                    $found[] = $file->getPathname();
                }
            }
        }

        return $found;
    }
}
