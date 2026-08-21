<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Service\StatisticsIntakeService;
use Modules\SupportDashboard\Task\PurgeInstallationsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The retention task: what it deletes, what it must never touch, and the
 * fact that it always reschedules itself.
 */
class PurgeInstallationsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SupportInstallationRepository $installations;
    private SettingService $settings;
    private TaskContext $context;
    private string $storagePath;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);
        $this->installations = new SupportInstallationRepository($this->pdo);

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register('support_retention_months', '6', 'number', 'L', 'D', 'support_dashboard');
        $this->settings->clearCache();

        $this->storagePath = sys_get_temp_dir() . '/purge_installations_' . uniqid();
        mkdir($this->storagePath . '/keys', 0o777, true);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            new MailService('local', 'unite@exemple.be', 'Unité', 'EX', new DkimManager($this->storagePath . '/keys'), 's1'),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    private function seed(string $installationId, string $lastReceivedRelative): int
    {
        $payload = [
            'statistics_schema_version' => 1,
            'installation_id' => $installationId,
            'instance_url' => 'https://' . $installationId . '.example.be',
            'scoutmagic' => ['version' => '1.0.33', 'is_dev_build' => false],
        ];

        $id = $this->installations->register(
            $installationId,
            password_hash('secret', PASSWORD_DEFAULT),
            (string) json_encode($payload),
            StatisticsIntakeService::denormalize($payload)
        );

        $this->pdo->prepare('UPDATE support_installations SET last_received_at = ? WHERE id = ?')
            ->execute([(new \DateTimeImmutable($lastReceivedRelative))->format('Y-m-d H:i:s'), $id]);

        return $id;
    }

    public function testItDeletesOnlyWhatIsPastTheRetentionWindow(): void
    {
        $keep = $this->seed('aaaa', '-5 months');
        $drop = $this->seed('bbbb', '-7 months');

        (new PurgeInstallationsHandler())->handle([], $this->context);

        $this->assertNotNull($this->installations->findById($keep));
        $this->assertNull($this->installations->findById($drop));
    }

    public function testTheRetentionSettingChangesWhatIsKept(): void
    {
        $id = $this->seed('aaaa', '-7 months');

        $this->settings->set('support_retention_months', '12', 'support_dashboard');
        $this->settings->clearCache();

        (new PurgeInstallationsHandler())->handle([], $this->context);

        $this->assertNotNull($this->installations->findById($id), 'a 12-month window keeps a 7-month-old record');
    }

    public function testItJournalsACountAndNeverAnIdentity(): void
    {
        $this->seed('aaaa', '-9 months');

        (new PurgeInstallationsHandler())->handle([], $this->context);

        $entries = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringContainsString('support_installations_purged', $entries);
        $this->assertStringNotContainsString('aaaa', $entries);
        $this->assertStringNotContainsString('example.be', $entries);
    }

    public function testAPurgeThatDeletesNothingSaysNothing(): void
    {
        $this->seed('aaaa', '-1 day');

        (new PurgeInstallationsHandler())->handle([], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'support_installations_purged'")->fetchColumn();
        $this->assertSame(0, $count, 'a quiet purge must not write a daily journal entry');
    }

    public function testItAlwaysReschedulesItself(): void
    {
        (new PurgeInstallationsHandler())->handle([], $this->context);

        $next = (new SchedulerService(new SchedulerRepository($this->pdo)))
            ->find('support_dashboard', PurgeInstallationsHandler::TASK_KEY, PurgeInstallationsHandler::REFERENCE);

        $this->assertNotNull($next, 'a purge that stops rescheduling stops running forever');
    }

    /**
     * The hard requirement: retention removes an installation, never a
     * finalised aggregate. A history rewritten because a contributor later
     * disappeared would mean nothing.
     */
    public function testThePurgeLeavesFinalizedAggregatesUntouched(): void
    {
        $this->seed('aaaa', '-9 months');

        $this->pdo->prepare(
            'INSERT INTO support_monthly_aggregates (month, installation_count, finalized_at) VALUES (?, ?, ?)'
        )->execute(['2026-01', 42, '2026-02-01 00:00:00']);

        (new PurgeInstallationsHandler())->handle([], $this->context);

        $this->assertSame(
            42,
            (int) $this->pdo->query("SELECT installation_count FROM support_monthly_aggregates WHERE month = '2026-01'")->fetchColumn()
        );
    }
}
