<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Scheduler\CronHealth;
use Core\Scheduler\CronRunHistory;
use Core\Scheduler\CronStatus;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The three sources CronHealth reads, and the one question they answer
 * together: is a real crontab actually driving this installation?
 *
 * Each source is exercised on its own, because each one exists for a case
 * the other two cannot cover — the heartbeat because the setup gate runs
 * before there is any database, `cron_last_run` because the heartbeat file
 * lives under `storage/temp` and does not survive everything, and the ring
 * buffer because one stamp is not an interval.
 */
class CronHealthTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/cron_health_' . uniqid();
        mkdir($this->storagePath . '/temp', 0755, true);
    }

    protected function tearDown(): void
    {
        $heartbeat = $this->storagePath . '/' . CronHealth::HEARTBEAT_FILE;
        if (is_file($heartbeat)) {
            unlink($heartbeat);
        }
        if (is_dir($this->storagePath . '/temp')) {
            rmdir($this->storagePath . '/temp');
        }
        if (is_dir($this->storagePath)) {
            rmdir($this->storagePath);
        }
    }

    private function writeHeartbeat(string $content): void
    {
        file_put_contents($this->storagePath . '/' . CronHealth::HEARTBEAT_FILE, $content);
    }

    private function settings(): SettingService
    {
        $settings = new SettingService(new SettingRepository(DatabaseTestHelper::createTestDatabase()));
        $settings->register('cron_last_run', '0', 'number', 'L', 'D', null, null, null, false, 999);
        CronRunHistory::register($settings);

        return $settings;
    }

    // --- never ------------------------------------------------------------

    public function testAnInstallationWithNoTraceAtAllReportsNever(): void
    {
        $status = (new CronHealth($this->storagePath))->status();

        $this->assertSame(CronStatus::STATE_NEVER, $status->state);
        $this->assertFalse($status->isActive());
        $this->assertNull($status->lastHeartbeatAt);
        $this->assertNull($status->lastFullPassAt);
        $this->assertNull($status->secondsSinceLastSeen());
    }

    /**
     * The whole point of the file source: the setup gate has to reach a
     * verdict while the site is NOT initialized, where there is no
     * settings table to read `cron_last_run` from.
     */
    public function testItAnswersWithoutAnySettingServiceAtAll(): void
    {
        $now = time();
        $this->writeHeartbeat((string) $now);

        $status = (new CronHealth($this->storagePath, null))->status($now);

        $this->assertSame(CronStatus::STATE_ACTIVE, $status->state);
        $this->assertSame($now, $status->lastHeartbeatAt);
        $this->assertNull($status->lastFullPassAt);
    }

    /**
     * A storage directory that does not exist (or is unreadable) is a
     * "no heartbeat" answer, never an error — this runs on a page an
     * operator is trying to load, and on a first install where half the
     * tree may not exist yet.
     */
    public function testAMissingStorageDirectoryIsAnAnswerNotAFailure(): void
    {
        $status = (new CronHealth('/nonexistent/scoutmagic/storage'))->status();

        $this->assertSame(CronStatus::STATE_NEVER, $status->state);
    }

    public function testAnEmptyStoragePathIsAcceptedAndReadsAsNoHeartbeat(): void
    {
        $status = (new CronHealth(''))->status();

        $this->assertSame(CronStatus::STATE_NEVER, $status->state);
        $this->assertNull($status->lastHeartbeatAt);
    }

    // --- active / stale, from the file ------------------------------------

    public function testAHeartbeatWithinThreeMinutesIsActive(): void
    {
        $now = time();
        $this->writeHeartbeat((string) ($now - (CronHealth::ACTIVE_WITHIN_SECONDS - 10)));

        $this->assertTrue((new CronHealth($this->storagePath))->status($now)->isActive());
    }

    public function testAHeartbeatOlderThanThreeMinutesIsStale(): void
    {
        $now = time();
        $this->writeHeartbeat((string) ($now - (CronHealth::ACTIVE_WITHIN_SECONDS + 60)));

        $status = (new CronHealth($this->storagePath))->status($now);

        $this->assertSame(CronStatus::STATE_STALE, $status->state);
        $this->assertSame(CronHealth::ACTIVE_WITHIN_SECONDS + 60, $status->secondsSinceLastSeen());
    }

    /**
     * A cron killed mid-write leaves a file with no usable content. The
     * file existing at all is still evidence the crontab fired, so the
     * mtime stands in rather than the whole trace being discarded.
     */
    public function testAnUnreadableHeartbeatContentFallsBackToTheFileMtime(): void
    {
        $this->writeHeartbeat("\x00garbage");
        $path = $this->storagePath . '/' . CronHealth::HEARTBEAT_FILE;
        touch($path, time() - 30);

        $status = (new CronHealth($this->storagePath))->status();

        $this->assertTrue($status->isActive());
        $this->assertNotNull($status->lastHeartbeatAt);
    }

    // --- active / stale, from the setting ---------------------------------

    public function testARecentFullPassIsActiveEvenWithNoHeartbeatFile(): void
    {
        $now = time();
        $settings = $this->settings();
        $settings->setInternal('cron_last_run', (string) ($now - 30));

        $status = (new CronHealth($this->storagePath, $settings))->status($now);

        $this->assertSame(CronStatus::STATE_ACTIVE, $status->state);
        $this->assertSame($now - 30, $status->lastFullPassAt);
        $this->assertNull($status->lastHeartbeatAt);
    }

    /**
     * The distinction a single stamp could never make: the crontab fires
     * (heartbeat present) but nothing ever reaches the database.
     */
    public function testAHeartbeatWithoutAFullPassStillCountsAsALiveCron(): void
    {
        $now = time();
        $this->writeHeartbeat((string) $now);
        $settings = $this->settings();

        $status = (new CronHealth($this->storagePath, $settings))->status($now);

        $this->assertTrue($status->isActive());
        $this->assertNull($status->lastFullPassAt);
    }

    public function testALongSilenceIsReportedBeyondTheStaleThreshold(): void
    {
        $now = time();
        $settings = $this->settings();
        $settings->setInternal('cron_last_run', (string) ($now - (CronHealth::STALE_AFTER_SECONDS + 600)));

        $status = (new CronHealth($this->storagePath, $settings))->status($now);

        $this->assertSame(CronStatus::STATE_STALE, $status->state);
        $this->assertTrue($status->isSilentBeyond(CronHealth::STALE_AFTER_SECONDS));
    }

    /**
     * An hourly crontab is late by this class's standard but not silent —
     * the two verdicts are separate on purpose, and the support package's
     * wording depends on the second one alone.
     */
    public function testAnHourOldPassIsStaleButNotSilent(): void
    {
        $now = time();
        $settings = $this->settings();
        $settings->setInternal('cron_last_run', (string) ($now - 3600));

        $status = (new CronHealth($this->storagePath, $settings))->status($now);

        $this->assertSame(CronStatus::STATE_STALE, $status->state);
        $this->assertFalse($status->isSilentBeyond(CronHealth::STALE_AFTER_SECONDS));
    }

    // --- median interval, from the ring buffer ----------------------------

    public function testTheMedianIntervalComesFromTheRingBuffer(): void
    {
        $now = time();
        $settings = $this->settings();
        // Five passes a minute apart, plus one gap of five minutes: the
        // median must ignore the outlier the average would absorb.
        $settings->setInternal(CronRunHistory::SETTING, (string) json_encode([
            $now - 660, $now - 600, $now - 540, $now - 480, $now - 180, $now - 120,
        ]));

        $status = (new CronHealth($this->storagePath, $settings))->status($now);

        $this->assertSame(60, $status->medianIntervalSeconds);
    }

    public function testASingleRecordedPassYieldsNoIntervalAtAll(): void
    {
        $now = time();
        $settings = $this->settings();
        $settings->setInternal(CronRunHistory::SETTING, (string) json_encode([$now - 60]));

        $this->assertNull((new CronHealth($this->storagePath, $settings))->status($now)->medianIntervalSeconds);
    }

    public function testNoSettingServiceMeansNoIntervalRatherThanZero(): void
    {
        $this->assertNull((new CronHealth($this->storagePath))->status()->medianIntervalSeconds);
    }

    // --- the shared crontab line ------------------------------------------

    /**
     * One spelling, in one place. The `php` prefix is the whole reason
     * this string is not left to each page to write: a hosting panel given
     * a bare script path executes nothing and reports nothing.
     */
    public function testTheCrontabLineIsSpelledOnceAndAlwaysCarriesThePhpPrefix(): void
    {
        $this->assertSame(
            '* * * * * php /htdocs/public/cron.php',
            CronHealth::crontabLine('/htdocs/public')
        );
        $this->assertSame(
            '* * * * * php /htdocs/public/cron.php',
            CronHealth::crontabLine('/htdocs/public/')
        );
    }

    public function testAnUnknownPublicDirectoryFallsBackToAPlaceholderPath(): void
    {
        $line = CronHealth::crontabLine('');

        $this->assertStringStartsWith('* * * * * php ', $line);
        $this->assertStringEndsWith('/cron.php', $line);
    }
}
