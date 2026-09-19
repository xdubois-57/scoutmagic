<?php

declare(strict_types=1);

namespace Tests\Core\Alert;

use Core\Alert\AlertSurfaces;
use Core\Alert\Check\HttpsCheck;
use Core\Alert\OperationalAlert;
use Core\Alert\OperationalAlertRepository;
use Core\Alert\OperationalAttentionProvider;
use Core\Alert\Task\RunOperationalChecksHandler;
use Core\Attention\AttentionPoint;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\CoreTaskHandlers;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\TaskContext;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The daily pass, and the attention surface that reads what it wrote.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RunOperationalChecksHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/alert_task_test_' . uniqid();
        mkdir($this->storagePath . '/temp', 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->storagePath);
    }

    /**
     * A handler nothing registers is a handler the real cron cannot run —
     * `public/cron.php` fails with "No handler registered" and nothing
     * says so. `CoreTaskHandlers::all()` is what stops that being possible
     * to forget in one of the two entry points but not the other.
     */
    public function testTheHandlerIsRegisteredForBothEntryPoints(): void
    {
        $this->assertSame(
            RunOperationalChecksHandler::class,
            CoreTaskHandlers::all()['operational_checks'] ?? null
        );
    }

    public function testARunEvaluatesTheChecksAndRearmsItself(): void
    {
        $handler = new RunOperationalChecksHandler();
        $handler->handle([], $this->context());

        // Nothing is wrong with this fixture except that it has never
        // backed up, which is a genuine trigger.
        $repository = new OperationalAlertRepository($this->pdo);
        $this->assertSame(
            OperationalAlert::STATE_TRIGGERED,
            $repository->findOrArmed(\Core\Alert\Check\BackupAgeCheck::KEY)->state
        );

        $pending = (new SchedulerRepository($this->pdo))->findByModuleAndKey(
            'core',
            'operational_checks',
            RunOperationalChecksHandler::REFERENCE
        );
        $this->assertNotNull($pending, 'the chain must arm its own successor');
    }

    /**
     * The guard every self-rescheduling handler here carries: a run that
     * overlaps an already-pending occurrence must stand down rather than
     * queue a second chain. Two chains never die on their own — each copy
     * re-arms, so the count stays at N for ever.
     */
    public function testASecondRunDoesNotQueueASecondChain(): void
    {
        $handler = new RunOperationalChecksHandler();
        $handler->handle([], $this->context());
        $handler->handle([], $this->context());

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'operational_checks' AND status = 'pending'"
        )->fetchColumn();

        $this->assertSame(1, $count);
    }

    // ————— Le point d'attention —————

    public function testTriggeredAlertsBecomeAttentionPointsCarryingTheirReading(): void
    {
        (new OperationalAlertRepository($this->pdo))->markTriggered('disk_usage', '92 %');

        $points = (new OperationalAttentionProvider(
            new OperationalAlertRepository($this->pdo),
            ['disk_usage' => 'Espace disque']
        ))->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('Espace disque : 92 %', $points[0]->title);
        $this->assertSame(AttentionPoint::SEVERITY_URGENT, $points[0]->severity);
    }

    /**
     * The default destination, and the eleven checks it is right for.
     *
     * The disk figure, the backup age and the cron stamp all live on the
     * maintenance page, so a reader who follows this link arrives at the
     * thing the alert is about.
     */
    public function testAnAlertWithNoDestinationOfItsOwnOpensTheMaintenancePage(): void
    {
        (new OperationalAlertRepository($this->pdo))->markTriggered('disk_usage', '92 %');

        $points = (new OperationalAttentionProvider(
            new OperationalAlertRepository($this->pdo),
            ['disk_usage' => 'Espace disque'],
            AlertSurfaces::destinations()
        ))->collect(1);

        $this->assertSame('/config/maintenance', $points[0]->actionUrl);
        $this->assertSame('Ouvrir la maintenance', $points[0]->actionLabel);
    }

    /**
     * Issue #352 — the fix has to reach the people already living with
     * the alert, and this page is the only surface that can.
     *
     * `AlertReading::actionUrl` is read by
     * `OperationalAlertService::notify()` alone, which fires once on the
     * armed→triggered transition. An installation whose HTTPS is
     * terminated in front of it is *already* triggered and cannot re-arm
     * without first enabling the setting the help topic explains — so it
     * can never earn a fresh notification carrying the new link. What it
     * has is this page, which used to send every alert to
     * `/config/maintenance`: a page that restates the reading, when the
     * reading was never what was wrong.
     */
    public function testTheHttpsAlertSendsTheAttentionPageToItsHelpTopicToo(): void
    {
        (new OperationalAlertRepository($this->pdo))->markTriggered(HttpsCheck::KEY, 'en clair à l\'instant');

        $points = (new OperationalAttentionProvider(
            new OperationalAlertRepository($this->pdo),
            AlertSurfaces::labels(),
            AlertSurfaces::destinations()
        ))->collect(1);

        $this->assertCount(1, $points);
        $this->assertSame('Connexion sécurisée : en clair à l\'instant', $points[0]->title);
        $this->assertSame(HttpsCheck::HELP_PATH, $points[0]->actionUrl);
        $this->assertSame(HttpsCheck::HELP_LABEL, $points[0]->actionLabel);

        // And it says why the maintenance page would not have helped:
        // two causes, opposite gestures.
        $this->assertStringContainsString('Deux causes', $points[0]->why);
        $this->assertStringNotContainsString('La page Maintenance', $points[0]->why);
    }

    /**
     * The two surfaces name the same destination, and cannot drift.
     *
     * They are built from opposite ends — the notification from the
     * check's own reading, this page from a map of stored rows — so
     * nothing but this holds them together. The constants make a silent
     * divergence impossible; this makes an intentional one visible.
     */
    public function testBothSurfacesSendTheHttpsAlertToTheSamePlace(): void
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $reading = (new HttpsCheck(['HTTPS' => 'off', 'SERVER_PORT' => '80'], $settings))->read();

        $destination = AlertSurfaces::destinations()[HttpsCheck::KEY];

        $this->assertSame($reading->actionUrl, $destination['path']);
        $this->assertSame($reading->actionLabel, $destination['label']);
    }

    /** An armed alert is not a current problem and must not be listed. */
    public function testArmedAlertsProduceNoAttentionPoint(): void
    {
        $repository = new OperationalAlertRepository($this->pdo);
        $repository->markTriggered('disk_usage', '92 %');
        $repository->markArmed('disk_usage', '40 %');

        $this->assertSame([], (new OperationalAttentionProvider($repository))->collect(1));
    }

    /**
     * An operational alert is a fact about the installation, not about a
     * scout year — the disk does not empty itself in September.
     */
    public function testTheScoutYearMakesNoDifference(): void
    {
        (new OperationalAlertRepository($this->pdo))->markTriggered('cron_silent', '72 h');
        $provider = new OperationalAttentionProvider(new OperationalAlertRepository($this->pdo));

        $this->assertCount(1, $provider->collect(1));
        $this->assertCount(1, $provider->collect(9999));
    }

    private function context(): TaskContext
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        foreach (['auto_update_enabled', 'auto_update_level', \Core\Storage\DiskBudget::QUOTA_SETTING] as $key) {
            $settings->register($key, '', 'text', $key, $key);
        }

        return new TaskContext(
            connection: $this->connection(),
            encryption: $this->createMock(\Core\Security\EncryptionService::class),
            mailService: $this->createMock(\Core\Mail\MailService::class),
            journal: new JournalService(new JournalRepository($this->pdo)),
            settings: $settings,
            userAccounts: $this->createMock(\Core\Security\UserAccountRepository::class),
            storagePath: $this->storagePath,
            notifications: null
        );
    }

    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        return $connection;
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
