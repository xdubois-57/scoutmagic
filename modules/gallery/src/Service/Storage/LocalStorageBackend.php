<?php

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

    public function delete(string $key): void
    {
        $path = $this->fullPath($key);
        if (is_file($path)) {
            unlink($path);
        }
    }

    public function deletePrefix(string $prefix): void
    {
        $dir = $this->fullPath(rtrim($prefix, '/'));
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

    public function url(string $key): string
    {
        return '/gallery/media/' . rawurlencode($key);
    }

    public function exists(string $key): bool
    {
        return is_file($this->fullPath($key));
    }

    private function fullPath(string $key): string
    {
        return $this->storagePath . '/' . $this->subdir . '/' . ltrim($key, '/');
    }
}
