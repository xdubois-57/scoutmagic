<?php

declare(strict_types=1);

namespace Tests\Core\Config;

use Core\Config\AppClock;
use PHPUnit\Framework\TestCase;

/**
 * The application clock, and the guarantee that every entry point actually
 * declares it. A timezone that only public/index.php sets is not an
 * application clock: a scheduled action written by a web request and
 * claimed by public/cron.php would be read on a different one, and the test
 * suite would be proving things about a regime the application never runs
 * in.
 */
class AppClockTest extends TestCase
{
    public function testTheApplicationClockIsBelgianLocalTime(): void
    {
        $this->assertSame('Europe/Brussels', AppClock::TIMEZONE);
        $this->assertSame('Europe/Brussels', AppClock::zone()->getName());
    }

    public function testApplySetsPhpsDefaultTimezone(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Kiritimati');
            AppClock::apply();

            $this->assertSame('Europe/Brussels', date_default_timezone_get());
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * now() names its zone rather than reading the ambient default, so the
     * handful of callers that must not drift (the groups module's edit
     * windows, the auto-update slot) keep working even inside a process
     * that changed the default underneath them.
     */
    public function testNowIsOnTheApplicationClockWhateverTheAmbientTimezoneIs(): void
    {
        $original = date_default_timezone_get();

        try {
            date_default_timezone_set('Pacific/Niue');

            $this->assertSame('Europe/Brussels', AppClock::now()->getTimezone()->getName());
        } finally {
            date_default_timezone_set($original);
        }
    }

    /**
     * The suite itself runs on it — tests/bootstrap.php calls apply(), so
     * an assertion about "now" in any test means the same thing it means in
     * production.
     */
    public function testTheTestSuiteRunsOnTheApplicationClock(): void
    {
        $this->assertSame(AppClock::TIMEZONE, date_default_timezone_get());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function entryPoints(): iterable
    {
        yield 'web' => ['public/index.php'];
        yield 'cron' => ['public/cron.php'];
        yield 'tests' => ['tests/bootstrap.php'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testEveryEntryPointDeclaresTheApplicationClock(string $relativePath): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/' . $relativePath);

        $this->assertIsString($source);
        $this->assertStringContainsString('AppClock::apply()', $source, $relativePath . ' must apply the application clock.');
    }

    /**
     * bootstrap/bootstrap.php is the standalone FTP installer — it has no
     * part in the running application, so it must not pretend to configure
     * it. Pinned so nobody "fixes" the omission by adding a call there and
     * concludes the entry points are covered.
     */
    public function testTheStandaloneInstallerIsNotAnEntryPoint(): void
    {
        $source = file_get_contents(dirname(__DIR__, 3) . '/bootstrap/bootstrap.php');

        $this->assertIsString($source);
        $this->assertStringNotContainsString('AppClock', $source);
    }
}
