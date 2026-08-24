<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * Drops PHP's compiled-opcode cache for files an update or a restore has
 * just rewritten under the live install root.
 *
 * Why this exists at all: OPcache does not notice a changed file the
 * moment it changes. With the usual production settings
 * (`opcache.validate_timestamps=On`, `opcache.revalidate_freq=60` — the
 * defaults on every shared host observed so far) it re-checks a given
 * file's mtime at most once per minute, so for up to a minute after
 * Task\InstallUpdateHandler has copied a new tree over the old one, PHP
 * keeps executing the PREVIOUS version of every `.php` file. Data files
 * do not participate: Markdown, Twig sources, JSON manifests and SQL are
 * read with ordinary file I/O and are new immediately.
 *
 * That mixture is the bug. A request served in that window runs old code
 * against new data, which is a state no version of the application was
 * ever tested in. It took the site down for real: a help topic gained a
 * `paths` form that the same commit had taught Core\Help\
 * HelpFrontMatterParser to accept, and for 54 seconds after the update
 * landed, the old parser met the new topic and threw on every single
 * request — the front controller builds the help panel on every GET, so
 * every page and every API endpoint returned 500. The clearCompiled
 * TemplateCache() sweep next to the caller is the same idea one layer up,
 * for Twig; this is the layer Twig sits on.
 *
 * Deliberately best-effort and silent. A host can disable OPcache
 * entirely, or set `opcache.restrict_api` so a call from this directory
 * is refused; neither is a reason to fail an update that has otherwise
 * succeeded, and neither leaves the site worse off than before this class
 * existed. Nothing here throws, and the caller is not asked to check.
 *
 * Note for readers chasing a stale-code bug: invalidating affects the
 * NEXT request, never the one running this code. The already-compiled
 * classes of the current request stay as they are — which is what makes
 * it safe to call from the middle of the very task doing the update.
 */
final class OpcodeCache
{
    /**
     * Forget the compiled form of specific files — the surgical form,
     * used when the caller knows exactly what it rewrote.
     *
     * Preferred over reset() wherever the list is known: on shared
     * hosting one pool is shared between sites, and resetting evicts
     * every neighbour's compiled code too. `force` is set because the
     * point is precisely not to trust the timestamp heuristic.
     *
     * @param iterable<string> $paths absolute paths, `.php` or otherwise —
     *        anything OPcache never cached is simply not there to drop
     * @return int how many files were actually dropped
     */
    public static function invalidateFiles(iterable $paths): int
    {
        if (!self::isAvailable() || !function_exists('opcache_invalidate')) {
            return 0;
        }

        $dropped = 0;
        foreach ($paths as $path) {
            if (@opcache_invalidate($path, true)) {
                $dropped++;
            }
        }

        return $dropped;
    }

    /**
     * Forget everything — for a caller that has rewritten an unknown set
     * of files, which is every archive extraction (Core\Maintenance\
     * BackupService::restoreFiles() hands the whole job to ZipArchive and
     * never learns which entries it wrote).
     */
    public static function reset(): bool
    {
        if (!self::isAvailable() || !function_exists('opcache_reset')) {
            return false;
        }

        return @opcache_reset() === true;
    }

    /**
     * Whether there is a cache to talk to at all: the extension can be
     * absent, compiled in but switched off, or on for the web SAPI and
     * off for CLI (`opcache.enable_cli`, off by default — which is why
     * public/cron.php sees a different answer here than a page load).
     */
    public static function isAvailable(): bool
    {
        if (!function_exists('opcache_get_status')) {
            return false;
        }

        $status = @opcache_get_status(false);

        return is_array($status) && ($status['opcache_enabled'] ?? false) === true;
    }
}
