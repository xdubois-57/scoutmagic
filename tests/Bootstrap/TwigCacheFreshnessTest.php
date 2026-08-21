<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use PHPUnit\Framework\TestCase;

/**
 * The suite must never render a template compiled by an earlier run.
 *
 * Core\View\TwigFactory caches compiled templates keyed on VERSION alone,
 * with `auto_reload` off whenever `debug` is false — which is what a test
 * builds. Editing a .twig without bumping VERSION therefore leaves the
 * compiled copy in place, and every later run asserts against the old one.
 *
 * That already cost real time once: a test failed on a working tree that
 * contained exactly the markup it was asserting on, because the compiled
 * template predated it, and the failure read as a defect in the feature.
 * The quiet direction is worse — a template whose fix is not compiled yet
 * keeps its test green.
 *
 * tests/bootstrap.php drops the cache before anything runs. This is the
 * guard that keeps that true: it fails the moment the drop stops
 * happening, whatever the reason (the bootstrap reverted, phpunit.xml
 * pointed back at vendor/autoload.php, the path drifting).
 */
class TwigCacheFreshnessTest extends TestCase
{
    public function testEveryCompiledTemplateWasProducedByThisRun(): void
    {
        $this->assertTrue(
            defined('SCOUTMAGIC_TEST_BOOTSTRAP_AT'),
            'phpunit.xml must bootstrap tests/bootstrap.php, not vendor/autoload.php directly'
        );

        $cacheRoot = dirname(__DIR__, 2) . '/storage/temp/twig_cache';
        if (!is_dir($cacheRoot)) {
            // Nothing rendered a template yet, or nothing ever does on this
            // run (--filter). Either way there is no stale copy to find.
            $this->assertTrue(true);

            return;
        }

        $stale = [];
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($cacheRoot, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($entries as $entry) {
            /** @var \SplFileInfo $entry */
            // A one-second grace: filemtime() has whole-second resolution,
            // so a template compiled in the same second the bootstrap ran
            // can legitimately stamp one second earlier.
            if ($entry->isFile() && $entry->getMTime() < SCOUTMAGIC_TEST_BOOTSTRAP_AT - 1) {
                $stale[] = $entry->getPathname();
            }
        }

        $this->assertSame(
            [],
            $stale,
            "Compiled templates predating this run are being rendered — see tests/bootstrap.php:\n"
            . implode("\n", $stale)
        );
    }
}
