<?php

declare(strict_types=1);

namespace Tests\Core\System;

use Core\System\ExecutableLocator;
use PHPUnit\Framework\TestCase;

class ExecutableLocatorTest extends TestCase
{
    public function testFindReturnsAnAbsolutePathForACommonlyAvailableBinary(): void
    {
        // `ls` is available in every environment this test suite runs in
        // (macOS dev machines and Linux CI/hosting alike) and reliably on
        // PATH, unlike mysqldump — a good stand-in that doesn't depend on
        // any of the shared-hosting quirks this class exists to work around.
        $path = ExecutableLocator::find('ls');

        $this->assertNotNull($path);
        $this->assertTrue(is_executable($path));
    }

    public function testFindReturnsNullForANameThatDoesNotExistAnywhere(): void
    {
        $this->assertNull(ExecutableLocator::find('this-binary-does-not-exist-anywhere-12345'));
    }

    /**
     * Regression: the fallback probe (used once `command -v` finds nothing
     * on $PATH) must verify a candidate by actually running it, not by
     * calling is_file()/is_executable() — shared hosting commonly
     * restricts PHP's own filesystem functions to the user's home
     * directory via open_basedir, which makes those calls return false for
     * anything under /usr even though the binary is right there and a
     * spawned subprocess (unaffected by open_basedir) can run it fine.
     * Reproduced here by breaking $PATH so `command -v` can't find `git`,
     * forcing the fallback loop to be what finds it. `git` rather than
     * `ls`: the probe itself runs `<candidate> --version`, which BSD/macOS
     * `ls` doesn't support (unlike GNU coreutils) — `git --version` is
     * portable across every environment this suite runs in, same as the
     * real binaries this locator is used for (mysqldump, mysql, timeout).
     */
    public function testFindStillLocatesABinaryViaTheFallbackDirsWhenPathLookupFails(): void
    {
        $originalPath = getenv('PATH');
        putenv('PATH=/nonexistent-path-for-test');

        $reflection = new \ReflectionClass(ExecutableLocator::class);
        $cache = $reflection->getProperty('cache');
        $cache->setValue(null, []);

        try {
            $path = ExecutableLocator::find('git');
        } finally {
            putenv('PATH=' . $originalPath);
            $cache->setValue(null, []);
        }

        $this->assertNotNull($path);
        $this->assertStringEndsWith('/git', $path);
    }
}
