<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;

/**
 * public/cron.php lives inside the document root so the site's own
 * PHP-FPM/CGI runtime can run it from crontab, but a web request must never
 * reach it: public/.htaccess only rewrites paths that do NOT exist on disk,
 * so GET /cron.php would otherwise execute the whole scheduler pass
 * (backups, updates, resets, journal purge) for any anonymous visitor, with
 * none of the once-per-60s throttle the in-request scheduler tail applies.
 *
 * The guard is asserted at the source level: booting cron.php in-process to
 * observe the 404 would pull in the full service graph and a live database.
 */
class CronEntryPointTest extends TestCase
{
    private string $cron;
    private string $index;

    protected function setUp(): void
    {
        $this->cron = (string) file_get_contents(dirname(__DIR__, 2) . '/public/cron.php');
        $this->index = (string) file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
    }

    public function testCronRefusesANonCliSapiBeforeDoingAnyWork(): void
    {
        $this->assertMatchesRegularExpression(
            "/if \\(PHP_SAPI !== 'cli'\\) \\{\\s*http_response_code\\(404\\);\\s*exit;\\s*\\}/",
            $this->cron,
            'cron.php must refuse a non-CLI SAPI with a 404'
        );
    }

    public function testTheGuardRunsBeforeTheAutoloaderAndAnySchedulerCall(): void
    {
        $guardPos = strpos($this->cron, "PHP_SAPI !== 'cli'");
        $autoloadPos = strpos($this->cron, "require_once __DIR__ . '/../vendor/autoload.php'");
        $processPos = strpos($this->cron, 'processOverdue(');

        $this->assertIsInt($guardPos);
        $this->assertIsInt($autoloadPos);
        $this->assertIsInt($processPos);
        $this->assertLessThan($autoloadPos, $guardPos, 'the SAPI guard must precede the autoloader');
        $this->assertLessThan($processPos, $guardPos, 'the SAPI guard must precede the scheduler pass');
    }

    /**
     * A core task handler registered in only one of the two entry points
     * fails silently under whichever trigger is missing it — the exact bug
     * ARCHITECTURE.md §8.17 records for create_backup, which never ran under
     * a real crontab. Every core handler must appear in both files.
     *
     * @return array<int, array{0: string}>
     */
    public static function coreTaskHandlerProvider(): array
    {
        return [
            ['CreateBackupHandler'],
            ['InstallUpdateHandler'],
            ['ResetSettingsHandler'],
            ['FullResetHandler'],
            ['RestoreBackupHandler'],
            ['AutoBackupHandler'],
            ['CheckStableUpdateHandler'],
            ['CompressSectionDocumentHandler'],
            ['SendNotificationsHandler'],
            ['PurgeNotificationsHandler'],
            ['PurgeHumanCheckRateLimitsHandler'],
            ['SendStatisticsHandler'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('coreTaskHandlerProvider')]
    public function testEveryCoreTaskHandlerIsRegisteredInBothEntryPoints(string $handler): void
    {
        $this->assertMatchesRegularExpression(
            '/registerHandler\(\s*\'core\',[^)]*' . preg_quote($handler, '/') . '\(\)/',
            $this->index,
            "public/index.php must register {$handler}"
        );
        $this->assertMatchesRegularExpression(
            '/registerHandler\(\s*\'core\',[^)]*' . preg_quote($handler, '/') . '\(\)/',
            $this->cron,
            "public/cron.php must register {$handler}"
        );
    }
}
