<?php

declare(strict_types=1);

namespace Tests\Core\Alert;

use Core\Alert\AlertSurfaces;
use Core\Alert\AlertThresholds;
use Core\Alert\Check\BackupAgeCheck;
use Core\Alert\Check\CronSilenceCheck;
use Core\Alert\Check\DevelopmentModeCheck;
use Core\Alert\Check\DiskUsageCheck;
use Core\Alert\Check\HttpsCheck;
use Core\Alert\Check\MailDeliveryCheck;
use Core\Alert\OperationalCheck;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Maintenance\BackupRepository;
use Core\Scheduler\CronHealth;
use Core\Storage\DiskBudget;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Each check reads one fact and compares it against its own two
 * thresholds. What is pinned here is the comparison and, above all, what
 * each one does when it cannot tell.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ChecksTest extends TestCase
{
    private const PLAIN_REQUEST = ['HTTPS' => 'off', 'SERVER_PORT' => '80'];
    private const SECURE_REQUEST = ['HTTPS' => 'on', 'SERVER_PORT' => '443'];

    private \PDO $pdo;
    private string $storagePath;
    private string $installPath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        // `storage/` nested inside an installation root of its own, never
        // directly under the system temp directory: a declared quota is
        // charged for the whole installation (§8.98), so a `storage/`
        // whose parent is `/tmp` is measured against everything else on
        // the machine — and these tests express occupation in hundreds of
        // bytes.
        $this->installPath = sys_get_temp_dir() . '/alert_checks_test_' . uniqid();
        $this->storagePath = $this->installPath . '/storage';
        mkdir($this->storagePath, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->installPath);
    }

    // ————— Espace disque —————

    public function testDiskUsageTriggersAtTheThresholdAndRearmsBelowTheLowerOne(): void
    {
        $settings = $this->settings([DiskBudget::QUOTA_SETTING => '1000']);

        // 900 bytes of a 1000-byte quota — 90 %, over the 85 % trigger.
        $this->writeBytes('gallery/photo.jpg', 900);
        $over = (new DiskUsageCheck(new DiskBudget($this->storagePath, $settings)))->read();
        $this->assertTrue($over->overTrigger);
        $this->assertFalse($over->underRearm);
        $this->assertSame('90 %', $over->value);

        // Down to 700 bytes — 70 %, under the 75 % re-arm. The cached
        // measurement has to go with it: DiskUsageCheck reads measure(),
        // which reuses a walk for a quarter of an hour, and two readings
        // a millisecond apart would otherwise be the same one. That cache
        // is invisible in production, where this check runs once a day.
        unlink($this->storagePath . '/gallery/photo.jpg');
        @unlink($this->storagePath . '/core/disk-usage.json');
        $this->writeBytes('gallery/photo.jpg', 700);
        $under = (new DiskUsageCheck(new DiskBudget($this->storagePath, $settings)))->read();
        $this->assertFalse($under->overTrigger);
        $this->assertTrue($under->underRearm);
    }

    /**
     * Between 75 % and 85 % neither boolean is true, which is the gap that
     * keeps a wobbling value from notifying in a loop.
     */
    public function testDiskUsageBetweenTheTwoThresholdsMovesNothing(): void
    {
        $settings = $this->settings([DiskBudget::QUOTA_SETTING => '1000']);
        $this->writeBytes('gallery/photo.jpg', 800);

        $reading = (new DiskUsageCheck(new DiskBudget($this->storagePath, $settings)))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    /**
     * An unknown occupation is inconclusive, never healthy: a host that
     * says nothing has not said the disk is empty.
     */
    public function testDiskUsageWithNothingToMeasureIsInconclusive(): void
    {
        $settings = $this->settings([DiskBudget::QUOTA_SETTING => '']);
        $budget = new DiskBudget($this->storagePath . '/gone', $settings);

        $reading = (new DiskUsageCheck($budget))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    // ————— Âge de la dernière sauvegarde —————

    public function testBackupAgeTriggersPastTheThreshold(): void
    {
        $this->insertBackup('completed', '-12 days');

        $reading = (new BackupAgeCheck(new BackupRepository($this->pdo)))->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertSame('12 jours', $reading->value);
    }

    public function testBackupAgeRearmsOnceARecentOneSucceeds(): void
    {
        $this->insertBackup('completed', '-1 day');

        $reading = (new BackupAgeCheck(new BackupRepository($this->pdo)))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
    }

    /**
     * A failed run is exactly the situation this check exists to reveal,
     * so it must not count as a backup — otherwise the reading says the
     * opposite of the truth.
     */
    public function testBackupAgeIgnoresFailedRuns(): void
    {
        $this->insertBackup('completed', '-30 days');
        $this->insertBackup('failed', '-1 hour');

        $reading = (new BackupAgeCheck(new BackupRepository($this->pdo)))->read();

        $this->assertTrue($reading->overTrigger, 'a failed run is not a backup');
        $this->assertSame('30 jours', $reading->value);
    }

    /** Never backed up at all is the worst reading, not a missing one. */
    public function testNeverHavingBackedUpTriggers(): void
    {

        $reading = (new BackupAgeCheck(new BackupRepository($this->pdo)))->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertSame('aucune', $reading->value);
    }

    // ————— Envoi d'e-mails —————

    public function testMailDeliveryTriggersOnRepeatedFailuresAndRearmsAtZero(): void
    {
        for ($i = 0; $i < AlertThresholds::MAIL_FAILURE_TRIGGER_COUNT; $i++) {
            $this->insertEvent('mail_send_failed', '-1 hour');
        }

        $reading = (new MailDeliveryCheck(new JournalRepository($this->pdo)))->read();
        $this->assertTrue($reading->overTrigger);

        // The same journal, read a week later: the failures have aged out
        // of the 24-hour window.
        $later = new \DateTimeImmutable('+7 days');
        $recovered = (new MailDeliveryCheck(new JournalRepository($this->pdo), $later))->read();
        $this->assertFalse($recovered->overTrigger);
        $this->assertTrue($recovered->underRearm);
    }

    /** One bad address is not a broken relay. */
    public function testASingleMailFailureDoesNotTrigger(): void
    {
        $this->insertEvent('mail_send_failed', '-1 hour');

        $this->assertFalse((new MailDeliveryCheck(new JournalRepository($this->pdo)))->read()->overTrigger);
    }

    /** Counted on `event_type`, never on a French description that can be reworded. */
    public function testMailDeliveryCountsOnlyItsOwnEventType(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->insertEvent('some_other_event', '-1 hour');
        }

        $this->assertFalse((new MailDeliveryCheck(new JournalRepository($this->pdo)))->read()->overTrigger);
    }

    // ————— Mode développement —————

    public function testDevelopmentModeNeedsBothTheSwitchAndTheLevel(): void
    {
        $on = $this->settings(['auto_update_enabled' => '1', 'auto_update_level' => 'dev']);
        $this->assertTrue((new DevelopmentModeCheck($on))->read()->overTrigger);

        // The level alone, with automatic updates switched off, installs
        // nothing — so it is not development mode.
        $levelOnly = $this->settings(['auto_update_enabled' => '0', 'auto_update_level' => 'dev']);
        $this->assertFalse((new DevelopmentModeCheck($levelOnly))->read()->overTrigger);

        $ordinary = $this->settings(['auto_update_enabled' => '1', 'auto_update_level' => 'patch']);
        $reading = (new DevelopmentModeCheck($ordinary))->read();
        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
    }

    // ————— Cron silencieux —————

    /**
     * The check that cannot live in the scheduled task, evaluated with no
     * scheduler in sight — which is the whole point: if the cron has
     * stopped, the task never runs.
     */
    public function testCronSilenceIsEvaluatedWithoutTheScheduler(): void
    {
        $settings = $this->settings(['cron_last_run' => (string) (time() - 3 * 86400)]);

        $reading = (new CronSilenceCheck(new CronHealth($this->storagePath, $settings)))->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertStringContainsString('h', $reading->value);
    }

    public function testARunningCronRearms(): void
    {
        $settings = $this->settings(['cron_last_run' => (string) (time() - 120)]);

        $reading = (new CronSilenceCheck(new CronHealth($this->storagePath, $settings)))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
    }

    /**
     * A site that has never seen a cron pass is left inconclusive: the
     * setup wizard refuses to finish without one and says so far more
     * usefully, and there is no super-admin to notify yet.
     */
    public function testACronThatHasNeverRunIsInconclusive(): void
    {
        $settings = $this->settings(['cron_last_run' => '0']);

        $reading = (new CronSilenceCheck(new CronHealth($this->storagePath, $settings)))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    // ————— HTTPS —————

    public function testHttpsAbsentTriggersAndPresentRearms(): void
    {
        $plain = new HttpsCheck(self::PLAIN_REQUEST, $this->settings(['base_url' => 'http://exemple.be']));
        $reading = $plain->read();
        $this->assertTrue($reading->overTrigger);
        $this->assertSame('HTTP', $reading->value);

        $secure = new HttpsCheck(self::SECURE_REQUEST, $this->settings(['base_url' => 'https://exemple.be']));
        $reading = $secure->read();
        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('HTTPS', $reading->value);
    }

    /**
     * A site answering on BOTH schemes must not flap.
     *
     * The first version read one request's scheme for both directions —
     * `overTrigger: !$secure`, `underRearm: $secure` — complementary
     * values of the same boolean, so a gap of zero. Nothing here forces
     * HTTP to HTTPS, so such a site alternates as visitors arrive, and
     * this check runs every quarter of an hour: triggered, armed,
     * triggered, e-mailing every super-admin each way. Triggering and
     * re-arming therefore each need the observation AND the declared
     * `base_url` to agree, and the disagreement between them is the gap.
     */
    public function testARequestThatDisagreesWithTheDeclaredAddressMovesNothing(): void
    {
        // Declared HTTPS, this request arrived over HTTP: a stray plain
        // request against a site that says it is secure. Not proof enough.
        $stray = (new HttpsCheck(self::PLAIN_REQUEST, $this->settings(['base_url' => 'https://exemple.be'])))->read();
        $this->assertFalse($stray->overTrigger);
        $this->assertFalse($stray->underRearm);

        // Declared HTTP, this request arrived over HTTPS: secure once, but
        // the site still says it is not. Not a re-arm either.
        $partial = (new HttpsCheck(self::SECURE_REQUEST, $this->settings(['base_url' => 'http://exemple.be'])))->read();
        $this->assertFalse($partial->overTrigger);
        $this->assertFalse($partial->underRearm);
    }

    /** No declared address at all is not a declaration of HTTPS. */
    public function testAnUndeclaredAddressStillTriggersOnAPlainRequest(): void
    {
        $reading = (new HttpsCheck(self::PLAIN_REQUEST, $this->settings(['base_url' => ''])))->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    /** With nothing to read the declaration from, the check declines to guess. */
    public function testWithNoSettingsToReadTheHttpsCheckIsInconclusive(): void
    {
        $reading = (new HttpsCheck(self::PLAIN_REQUEST))->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
        $this->assertSame('', $reading->value);
    }

    // ————— Les libellés —————

    /**
     * `AlertSurfaces` names every key for the attention page, which reads
     * rows rather than checks. A shipped check missing from that map would
     * render as its raw key — legible to nobody.
     */
    public function testEveryShippedCheckHasASurfaceLabel(): void
    {
        $labels = AlertSurfaces::labels();

        foreach ($this->everyCheckClass() as $class) {
            $this->assertArrayHasKey(
                $class::KEY,
                $labels,
                $class . ' is missing from AlertSurfaces::labels()'
            );
        }

        $this->assertCount(count($this->everyCheckClass()), $labels, 'no stale keys either');
    }

    /** Two checks writing the same row would overwrite each other's state. */
    public function testEveryCheckKeyIsDistinct(): void
    {
        $keys = array_map(static fn (string $class): string => $class::KEY, $this->everyCheckClass());

        $this->assertSame($keys, array_unique($keys));
    }

    /** @return list<class-string<OperationalCheck>> */
    private function everyCheckClass(): array
    {
        return [
            DiskUsageCheck::class,
            BackupAgeCheck::class,
            MailDeliveryCheck::class,
            DevelopmentModeCheck::class,
            CronSilenceCheck::class,
            HttpsCheck::class,
        ];
    }

    // ————— Harnais —————

    /** @param array<string, string> $values */
    private function settings(array $values): SettingService
    {
        $service = new SettingService(new SettingRepository($this->pdo));
        foreach ($values as $key => $value) {
            $service->register($key, '', 'text', $key, $key);
            if ($value !== '') {
                $service->set($key, $value);
            }
        }

        return $service;
    }

    private function insertBackup(string $status, string $ago): void
    {
        $at = (new \DateTimeImmutable($ago))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO backups (type, status, created_at, completed_at) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute(['auto_backup', $status, $at, $status === 'completed' ? $at : null]);
    }

    private function insertEvent(string $eventType, string $ago): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO event_log (logged_at, category, event_type, level, description) VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            (new \DateTimeImmutable($ago))->format('Y-m-d H:i:s'),
            'core',
            $eventType,
            'error',
            'Échec d\'envoi d\'un e-mail',
        ]);
    }

    private function writeBytes(string $relativePath, int $bytes): void
    {
        $path = $this->storagePath . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, str_repeat('x', $bytes));
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir((string) $item) : @unlink((string) $item);
        }
        @rmdir($dir);
    }
}
