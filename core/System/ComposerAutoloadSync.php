<?php

declare(strict_types=1);

namespace Core\System;

use Composer\Autoload\ClassLoader;

/**
 * Re-registers every autoload["psr-4"] mapping declared in composer.json onto
 * the already-booted Composer ClassLoader, on top of whatever
 * vendor/composer/autoload_psr4.php (the compiled map) already contains.
 *
 * Why this exists: this app's "Update from GitHub" auto-update
 * (Core\Maintenance\Task\InstallUpdateHandler) copies the tracked
 * repository files over the live install but never runs `composer
 * install`/`composer dump-autoload` — vendor/ is git-ignored, so it's
 * outside the update's reach entirely, and this app deliberately targets
 * fully-managed hosting where shelling out to composer may not even be
 * possible (Core\System\ShellExecutor::isAvailable() can be false). A
 * module's very first commit always adds a new PSR-4 entry to
 * composer.json (e.g. "Modules\\Registration\\": "modules/registration/src/")
 * — without this, that entry is silently missing from the compiled map on
 * every host whose vendor/ predates that commit, and the module 500s with
 * "Class not found" the moment it's enabled, no matter how the update was
 * installed. ClassLoader::addPsr4() is safe to call for an
 * already-registered prefix (it merges paths rather than erroring), so
 * this only ever adds coverage on top of the compiled map, never conflicts
 * with it — cheap enough (one small JSON file, parsed once per request) to
 * just always run rather than trying to detect when it's "needed".
 *
 * Deliberately silent on any failure (missing/unreadable/malformed
 * composer.json): this runs at the very start of the request, before any
 * logging infrastructure exists, and a bootstrap safety net must never
 * itself become a new source of fatal errors — the compiled map still
 * covers every namespace it already knew about either way.
 */
final class ComposerAutoloadSync
{
    public static function apply(ClassLoader $loader, string $composerJsonPath): void
    {
        $raw = @file_get_contents($composerJsonPath);
        if ($raw === false) {
            return;
        }

        $decoded = json_decode($raw, true);
        $psr4 = $decoded['autoload']['psr-4'] ?? null;
        if (!is_array($psr4)) {
            return;
        }

        $baseDir = rtrim(dirname($composerJsonPath), '/');
        foreach ($psr4 as $prefix => $paths) {
            if (!is_string($prefix) || $prefix === '') {
                continue;
            }

            $resolved = array_map(
                static fn($path) => rtrim($baseDir . '/' . ltrim((string) $path, '/'), '/'),
                is_array($paths) ? $paths : [$paths]
            );

            try {
                $loader->addPsr4($prefix, $resolved);
            } catch (\InvalidArgumentException) {
                // A malformed prefix (doesn't end with '\') in composer.json
                // — nothing this safety net can do about it, skip it.
                continue;
            }
        }
    }
}
