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
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($this->base);
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

    public function testAnInstallationWithoutACacheIsLeftAsItIs(): void
    {
        CompiledTemplateCache::clear($this->base);
        CompiledTemplateCache::clear($this->base);

        $this->assertDirectoryExists($this->base . '/storage/temp');
    }
}
