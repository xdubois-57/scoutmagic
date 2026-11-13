<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\CompiledTemplateCache;
use PHPUnit\Framework\TestCase;

/**
 * The compiled templates are PHP the next page runs, so a restore ends by
 * removing them all (issue #1103) — and only them.
 */
final class CompiledTemplateCacheTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/compiled_cache_' . bin2hex(random_bytes(6));
        mkdir($this->base . '/storage/temp/twig_cache/1.0.0/ab', 0755, true);
        file_put_contents($this->base . '/storage/temp/twig_cache/1.0.0/ab/abcdef.php', '<?php // compiled');
        mkdir($this->base . '/storage/temp/twig_cache/dev-123', 0755, true);
        file_put_contents($this->base . '/storage/temp/twig_cache/dev-123/x.php', '<?php // planted');
        file_put_contents($this->base . '/storage/temp/autre.txt', 'not a template');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    private function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            unlink($dir);
            return;
        }
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

    public function testEveryCompiledTemplateIsRemoved(): void
    {
        CompiledTemplateCache::clear($this->base);

        $this->assertDirectoryDoesNotExist($this->base . '/storage/temp/twig_cache');
    }

    public function testNothingElseInTheTemporaryTreeIsTouched(): void
    {
        CompiledTemplateCache::clear($this->base . '/');

        $this->assertFileExists($this->base . '/storage/temp/autre.txt');
    }

    /**
     * A link standing in for the cache directory is removed as a link, and
     * what it points at is left alone (CodeRabbit on #1175): is_dir()
     * follows it, and walking it would empty a directory outside the cache.
     */
    public function testALinkedCacheRootIsUnlinkedAndItsTargetKept(): void
    {
        $this->removeTree($this->base . '/storage/temp/twig_cache');
        mkdir($this->base . '/elsewhere', 0755, true);
        file_put_contents($this->base . '/elsewhere/sentinel.txt', 'keep me');
        symlink($this->base . '/elsewhere', $this->base . '/storage/temp/twig_cache');

        CompiledTemplateCache::clear($this->base);

        $this->assertFalse(is_link($this->base . '/storage/temp/twig_cache'));
        $this->assertFileExists($this->base . '/elsewhere/sentinel.txt');
    }

    public function testAnInstallationWithoutACacheIsLeftAsItIs(): void
    {
        CompiledTemplateCache::clear($this->base);
        CompiledTemplateCache::clear($this->base);

        $this->assertDirectoryExists($this->base . '/storage/temp');
    }
}
