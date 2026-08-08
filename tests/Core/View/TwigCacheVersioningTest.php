<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Maintenance\VersionFile;
use Core\View\TwigFactory;
use PHPUnit\Framework\TestCase;

/**
 * Outside debug mode Twig compiles templates to disk with `auto_reload`
 * off, and its cache key derives from the template *name*, not its
 * contents — so a flat, never-cleared cache directory serves the
 * pre-deployment compiled class indefinitely after a template changes.
 * TwigFactory namespaces the cache directory by Core\Maintenance\
 * VersionFile so every deployment lands in a fresh directory.
 */
class TwigCacheVersioningTest extends TestCase
{
    public function testCacheDirectoryIsNamespacedByTheInstalledVersion(): void
    {
        $basePath = dirname(__DIR__, 3);
        $twig = TwigFactory::create($basePath . '/core/View/templates');

        $cache = $twig->getCache(true);

        $this->assertIsString($cache);
        $this->assertSame(
            $basePath . '/storage/temp/twig_cache/' . VersionFile::read($basePath),
            $cache
        );
    }

    public function testDebugModeUsesNoCacheAtAll(): void
    {
        // In debug mode templates must never be served from a compiled
        // cache at all (auto_reload is on and cache is false).
        $twig = TwigFactory::create(dirname(__DIR__, 3) . '/core/View/templates', true);

        $this->assertFalse($twig->getCache(true));
    }

    /**
     * The behaviour the version namespacing actually buys, reproduced
     * against Twig itself rather than asserted on a path string: with a
     * flat cache a changed template keeps rendering its old compiled
     * output, and a version-scoped directory doesn't.
     *
     * Each render runs in a *separate PHP process* on purpose — within one
     * process Twig reuses an already-declared __TwigTemplate_* class and
     * never re-reads any cache directory, which would mask the very
     * difference under test (and, being a single-request artifact, says
     * nothing about production, where every request is a fresh process).
     */
    public function testChangedTemplateIsStaleUnderAFlatCacheButFreshUnderAVersionedOne(): void
    {
        $templateDir = sys_get_temp_dir() . '/twig_cache_versioning_tpl_' . uniqid();
        $cacheRoot = sys_get_temp_dir() . '/twig_cache_versioning_cache_' . uniqid();
        mkdir($templateDir, 0755, true);

        try {
            file_put_contents($templateDir . '/p.html.twig', 'BEFORE');
            $this->assertSame('BEFORE', $this->renderInFreshProcess($templateDir, $cacheRoot . '/flat'));
            $this->assertSame('BEFORE', $this->renderInFreshProcess($templateDir, $cacheRoot . '/scoped/1.0.22'));

            // A deployment changes the template on disk.
            file_put_contents($templateDir . '/p.html.twig', 'AFTER');

            // Flat cache: still the pre-deployment compiled output.
            $this->assertSame('BEFORE', $this->renderInFreshProcess($templateDir, $cacheRoot . '/flat'));

            // Same version: still cached, which is the point of caching.
            $this->assertSame('BEFORE', $this->renderInFreshProcess($templateDir, $cacheRoot . '/scoped/1.0.22'));

            // Version bumped by the deployment: compiled fresh.
            $this->assertSame('AFTER', $this->renderInFreshProcess($templateDir, $cacheRoot . '/scoped/1.0.23'));
        } finally {
            $this->removeDirectory($templateDir);
            $this->removeDirectory($cacheRoot);
        }
    }

    private function renderInFreshProcess(string $templateDir, string $cacheDir): string
    {
        $script = sprintf(
            'require %s; $e = new Twig\Environment(new Twig\Loader\FilesystemLoader(%s), '
                . "['cache' => %s, 'debug' => false, 'auto_reload' => false, 'autoescape' => 'html']); "
                . "echo \$e->render('p.html.twig');",
            var_export(dirname(__DIR__, 3) . '/vendor/autoload.php', true),
            var_export($templateDir, true),
            var_export($cacheDir, true)
        );

        $output = shell_exec(escapeshellcmd(PHP_BINARY) . ' -r ' . escapeshellarg($script));

        return trim((string) $output);
    }

    private function removeDirectory(string $dir): void
    {
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
}
