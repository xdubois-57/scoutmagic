<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use PHPUnit\Framework\TestCase;

/**
 * Issue #1015: the suite writes into a temporary directory of its own,
 * removed when the run ends — never into the system's, which a developer's
 * machine or a long-lived container keeps from one run to the next.
 *
 * tests/bootstrap.php points TMPDIR at `scoutmagic-phpunit-<pid>-<random>`
 * before anything calls sys_get_temp_dir(), whose first answer PHP keeps
 * for the rest of the process. This is the guard that keeps the redirect
 * from disappearing without a sound: the bootstrap reverted, phpunit.xml
 * pointed back at vendor/autoload.php, or a call to sys_get_temp_dir()
 * moved ahead of the redirect.
 */
final class TestTemporaryDirectoryTest extends TestCase
{
    public function testTheSuiteWritesIntoItsOwnTemporaryDirectory(): void
    {
        $this->assertTrue(
            defined('SCOUTMAGIC_TEST_TEMP_DIR'),
            'phpunit.xml must bootstrap tests/bootstrap.php, which gives the run its temporary directory'
        );
        $runDirectory = (string) constant('SCOUTMAGIC_TEST_TEMP_DIR');

        $this->assertSame($runDirectory, sys_get_temp_dir());
        $this->assertSame($runDirectory, getenv('TMPDIR'));
        $this->assertDirectoryExists($runDirectory);
        $this->assertMatchesRegularExpression('#/scoutmagic-phpunit-\d+-[0-9a-f]{8}$#', $runDirectory);
    }

    /**
     * tempnam() and a process a test starts follow TMPDIR too, not only
     * sys_get_temp_dir().
     */
    public function testTempnamAndAChildProcessLandInIt(): void
    {
        $runDirectory = (string) constant('SCOUTMAGIC_TEST_TEMP_DIR');

        $file = tempnam(sys_get_temp_dir(), 'probe');
        $this->assertIsString($file);
        $this->assertSame($runDirectory, dirname((string) realpath($file)));
        unlink($file);

        $child = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('echo sys_get_temp_dir();'));
        $this->assertSame($runDirectory, $child);
    }
}
