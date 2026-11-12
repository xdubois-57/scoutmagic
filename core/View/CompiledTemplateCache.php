<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

/**
 * Empties `storage/temp/twig_cache/`, the compiled templates TwigFactory
 * writes and the next page render `include`s.
 *
 * Called after every restore, ordinary or portable (issue #1103). A file
 * in that directory is PHP the site will run — outside debug mode Twig
 * never compares it with its `.twig` source, so whatever is there IS the
 * template — and a restore is the one moment somebody else's archive is
 * written over this installation. The extraction guards are what keep an
 * archive out of that tree; this is the step that does not depend on any
 * of them having been right. TwigFactory recreates the directory lazily on
 * the next request, so the only cost is one recompilation per template.
 */
final class CompiledTemplateCache
{
    public static function clear(string $basePath): void
    {
        $dir = rtrim($basePath, '/') . '/storage/temp/twig_cache';
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() && !$item->isLink() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }
}
