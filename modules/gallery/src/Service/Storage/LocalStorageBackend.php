<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service\Storage;

/**
 * Stores derived media under storage/{subdir}/ — outside the webroot, like
 * every other upload in this app (ARCHITECTURE.md §8.3); served back to
 * the browser only through Controller\GalleryController::serveMedia(),
 * never a direct path.
 */
class LocalStorageBackend implements StorageBackendInterface
{
    public function __construct(
        private string $storagePath,
        private string $subdir
    ) {
    }

    public function put(string $key, string $contents, string $mimeType): void
    {
        $path = $this->fullPath($key);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        file_put_contents($path, $contents);
    }

    public function get(string $key): string
    {
        $path = $this->fullPath($key);
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new \RuntimeException("Gallery file not found: {$key}");
        }
        return $contents;
    }

    public function localPath(string $key): ?string
    {
        $path = $this->fullPath($key);
        return is_file($path) ? $path : null;
    }

    public function size(string $key): ?int
    {
        $path = $this->fullPath($key);
        if (!is_file($path)) {
            return null;
        }
        $size = filesize($path);
        return $size !== false ? $size : null;
    }

    public function getRange(string $key, int $offset, int $length): string
    {
        $path = $this->fullPath($key);
        if (!is_file($path)) {
            throw new \RuntimeException("Gallery file not found: {$key}");
        }
        if ($length <= 0) {
            return '';
        }

        $contents = file_get_contents($path, false, null, max(0, $offset), $length);
        if ($contents === false) {
            throw new \RuntimeException("Gallery file not readable: {$key}");
        }
        return $contents;
    }

    public function delete(string $key): void
    {
        $path = $this->fullPath($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function deletePrefix(string $prefix): void
    {
        $prefix = trim($prefix, '/');
        // An empty prefix would resolve to the location's own root and wipe
        // every album in it — callers always pass "{albumId}", never "".
        if ($prefix === '') {
            return;
        }

        $dir = $this->fullPath($prefix);
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }

    public function url(string $key, string $ttl = '+1 hour'): string
    {
        // $ttl is meaningless for local storage — this "URL" is this
        // app's own access-controlled route, not a time-limited grant.
        return '/gallery/media/' . rawurlencode($key);
    }

    public function exists(string $key): bool
    {
        return is_file($this->fullPath($key));
    }

    /**
     * Every key this backend ever receives is app-generated
     * ("{albumId}/thumb_{mediaId}.jpg"), but the base directory below it is
     * half admin-supplied (gallery_storage_locations.subdir) — so the joined
     * path is checked to actually stay under storage/{subdir}/ rather than
     * trusted to. Purely lexical (no realpath()): the target of a put()
     * doesn't exist yet, and a missing path must not silently resolve to the
     * root of the whole storage directory.
     *
     * @throws \RuntimeException on any attempt to escape the location's own directory
     */
    private function fullPath(string $key): string
    {
        $base = $this->normalize($this->storagePath . '/' . $this->subdir);
        $full = $this->normalize($base . '/' . ltrim($key, '/'));

        if ($full !== $base && !str_starts_with($full, $base . '/')) {
            throw new \RuntimeException("Gallery storage key escapes its location: {$key}");
        }

        return $full;
    }

    /**
     * Collapses "." / ".." segments and duplicate separators lexically,
     * preserving a leading "/" — realpath() can't be used here (see
     * fullPath()), and a ".." that walks past the root is clamped rather
     * than allowed to climb further.
     */
    private function normalize(string $path): string
    {
        $isAbsolute = str_starts_with($path, '/');
        $segments = [];

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return ($isAbsolute ? '/' : '') . implode('/', $segments);
    }
}
