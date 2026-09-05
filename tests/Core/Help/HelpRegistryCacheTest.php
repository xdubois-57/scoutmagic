<?php

declare(strict_types=1);

namespace Tests\Core\Help;

use Core\Help\HelpFrontMatterParser;
use Core\Help\HelpRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The serialized-index escape hatch ARCHITECTURE.md §8.64 planned for a
 * 100+ topic corpus: keyed on installed version + registered module set,
 * disabled on dev builds. Every miss falls back to the plain scan, so
 * nothing here can ever take the help down.
 */
class HelpRegistryCacheTest extends TestCase
{
    private string $topicsDir;
    private string $cacheDir;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/scoutmagic-help-cache-' . bin2hex(random_bytes(4));
        $this->topicsDir = $base . '/topics';
        $this->cacheDir = $base . '/cache';
        mkdir($this->topicsDir, 0777, true);

        file_put_contents($this->topicsDir . '/premier.md', <<<MD
        ---
        id: premier
        title: Premier sujet
        summary: Une phrase.
        category: Premiers pas
        role_min: public
        paths: /premier
        ---
        Le corps du sujet.
        MD);
    }

    protected function tearDown(): void
    {
        foreach ([$this->topicsDir, $this->cacheDir] as $dir) {
            foreach (glob($dir . '/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
        @rmdir(dirname($this->topicsDir));
    }

    private function registry(string $version): HelpRegistry
    {
        return new HelpRegistry($this->topicsDir, new HelpFrontMatterParser(), $this->cacheDir, $version);
    }

    public function testASecondRegistryServesTheIndexWithoutRescanning(): void
    {
        $this->assertArrayHasKey('premier', $this->registry('1.0.0')->all());
        $this->assertFileExists($this->cacheDir . '/help-index.cache');

        // Proven by deleting the corpus behind the cache's back: a fresh
        // scan would come back empty, the cached index must not.
        unlink($this->topicsDir . '/premier.md');

        $this->assertArrayHasKey('premier', $this->registry('1.0.0')->all());
    }

    public function testAVersionChangeInvalidatesTheIndex(): void
    {
        $this->registry('1.0.0')->all();
        unlink($this->topicsDir . '/premier.md');

        $this->assertSame([], $this->registry('1.0.1')->all());
    }

    public function testADifferentModuleSetInvalidatesTheIndex(): void
    {
        $this->registry('1.0.0')->all();
        unlink($this->topicsDir . '/premier.md');

        $withModule = $this->registry('1.0.0');
        $withModule->registerModuleTopics('calendar', $this->topicsDir);

        $this->assertSame([], $withModule->all());
    }

    public function testADevBuildNeverCaches(): void
    {
        $this->registry('dev')->all();

        $this->assertFileDoesNotExist($this->cacheDir . '/help-index.cache');
    }

    public function testAnUnreadableCacheFallsBackToTheScan(): void
    {
        mkdir($this->cacheDir, 0777, true);
        file_put_contents($this->cacheDir . '/help-index.cache', 'not-a-serialized-index');

        $this->assertArrayHasKey('premier', $this->registry('1.0.0')->all());
    }

    public function testTheCacheDirectoryIsOnlyHandedOutWhenCachingIsUsable(): void
    {
        $this->assertSame($this->cacheDir, $this->registry('1.0.0')->cacheDirectory());
        $this->assertNull($this->registry('dev')->cacheDirectory());
        $this->assertNull((new HelpRegistry($this->topicsDir, new HelpFrontMatterParser()))->cacheDirectory());
    }
}
