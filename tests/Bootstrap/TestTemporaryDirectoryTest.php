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

    /**
     * The run's directory is gone once the run that made it ends — a
     * read-only directory a test left inside it included (review of #1199).
     * Played in a child process, which bootstraps, writes, and exits: this
     * run's own directory cannot be watched disappearing from inside it.
     */
    public function testTheRunDirectoryIsRemovedWhenTheRunEnds(): void
    {
        $script = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . '$dir = constant("SCOUTMAGIC_TEST_TEMP_DIR");'
            . 'mkdir($dir . "/locked/inner", 0700, true);'
            . 'file_put_contents($dir . "/locked/inner/left-behind.txt", "x");'
            . 'chmod($dir . "/locked", 0500);'
            . 'echo $dir;';
        $output = [];
        $exitCode = null;
        exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($script), $output, $exitCode);

        $this->assertSame(0, $exitCode);
        $childDirectory = implode('', $output);
        $this->assertMatchesRegularExpression('#/scoutmagic-phpunit-\d+-[0-9a-f]{8}$#', $childDirectory);
        $this->assertFalse(file_exists($childDirectory), 'The child run left its temporary directory behind.');
    }
}
