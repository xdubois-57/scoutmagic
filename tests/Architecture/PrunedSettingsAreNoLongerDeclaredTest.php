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
