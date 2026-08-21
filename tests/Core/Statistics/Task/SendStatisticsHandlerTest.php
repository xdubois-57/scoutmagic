<?php

declare(strict_types=1);

namespace Tests\Core\Statistics\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Statistics\StatisticsSender;
use Core\Statistics\StatisticsSenderFactoryInterface;
use Core\Statistics\StatisticsStateSettings;
use Core\Statistics\StatisticsTransportInterface;
use Core\Statistics\StatisticsTransportResponse;
use Core\Statistics\Task\SendStatisticsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

final class StubTransport implements StatisticsTransportInterface
{
    public int $calls = 0;

    public function __construct(private ?StatisticsTransportResponse $response = null)
    {
    }

    public function post(string $url, string $jsonBody, string $bearerToken, string $userAgent): StatisticsTransportResponse
    {
        $this->calls++;

        return $this->response ?? StatisticsTransportResponse::response(200, '');
    }
}

class SendStatisticsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private string $projectRoot;
    private SecretManager $secretManager;
    private TaskContext $context;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-handler-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);
        file_put_contents($this->projectRoot . '/VERSION', "1.0.33\n");

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $this->settings->register('base_url', 'https://unite-exemple.be', 'url', 'L', 'D');
        $this->settings->register('statistics_enabled', '1', 'boolean', 'L', 'D');
        $this->settings->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'L', 'D');
        $this->settings->register('auto_update_enabled', '0', 'boolean', 'L', 'D');
        $this->settings->register('auto_update_level', 'minor', 'text', 'L', 'D');
        $this->settings->register(InstallationIdentityService::INSTALLATION_ID_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(StatisticsStateSettings::LAST_SUCCESS_AT, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(StatisticsStateSettings::LAST_FAILURE_AT, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(StatisticsStateSettings::LAST_FAILURE_REASON, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->clearCache();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = $this->createMock(Connection::class);
        $connection->method('getPdo')->willReturn($this->pdo);

        $this->context = new TaskContext(
            $connection,
            $encryption,
            new MailService('local', 'unite@exemple.be', 'Unité', 'EX', new DkimManager($this->projectRoot . '/storage/keys'), 's1'),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->projectRoot . '/storage'
        );
    }

    protected function tearDown(): void
    {
        self::removeTree($this->projectRoot);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function handlerWith(StatisticsTransportInterface $transport): SendStatisticsHandler
    {
        $pdo = $this->pdo;
        $settings = $this->settings;
        $secretManager = $this->secretManager;
        $projectRoot = $this->projectRoot;

        $factory = new class ($pdo, $settings, $secretManager, $projectRoot, $transport) implements StatisticsSenderFactoryInterface {
            public function __construct(
                private \PDO $pdo,
                private SettingService $settings,
                private SecretManager $secretManager,
                private string $projectRoot,
                private StatisticsTransportInterface $transport
            ) {
            }

            public function create(TaskContext $context): StatisticsSender
            {
                $identity = new InstallationIdentityService($this->settings, $this->secretManager);

                return new StatisticsSender(
                    $this->settings,
                    new StatisticsPayloadBuilder($this->settings, $this->pdo, $identity, $this->projectRoot),
                    $identity,
                    $this->transport,
                    $context->journal,
                    $this->pdo,
                    '1.0.33'
                );
            }
        };

        return new SendStatisticsHandler($factory);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function scheduledSends(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scheduled_actions WHERE module_id = ? AND task_key = ? ORDER BY id'
        );
        $stmt->execute(['core', SendStatisticsHandler::TASK_KEY]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function testASuccessfulRunReschedulesItselfADayLater(): void
    {
        $transport = new StubTransport();

        $this->handlerWith($transport)->handle([], $this->context);

        $this->assertSame(1, $transport->calls);
        $scheduled = $this->scheduledSends();
        $this->assertCount(1, $scheduled);
        $this->assertSame(SendStatisticsHandler::REFERENCE, $scheduled[0]['reference']);

        $delta = (new \DateTimeImmutable((string) $scheduled[0]['run_at']))->getTimestamp() - time();
        $this->assertGreaterThan(86000, $delta);
        $this->assertLessThan(86700, $delta);
    }

    public function testAFailedRunStillReschedulesAndDoesNotRetryImmediately(): void
    {
        $transport = new StubTransport(StatisticsTransportResponse::response(503, 'down'));

        $this->handlerWith($transport)->handle([], $this->context);

        $this->assertSame(1, $transport->calls);
        $this->assertCount(1, $this->scheduledSends());
    }

    public function testASkippedRunStillReschedules(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_enabled', '0');
        $this->settings->clearCache();
        $transport = new StubTransport();

        $this->handlerWith($transport)->handle([], $this->context);

        $this->assertSame(0, $transport->calls);
        $this->assertCount(1, $this->scheduledSends());
    }

    public function testAThrowingSenderStillReschedulesBeforePropagating(): void
    {
        $factory = new class implements StatisticsSenderFactoryInterface {
            public function create(TaskContext $context): StatisticsSender
            {
                throw new \RuntimeException('composition failed');
            }
        };

        try {
            (new SendStatisticsHandler($factory))->handle([], $this->context);
            $this->fail('The handler should have propagated the failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('composition failed', $e->getMessage());
        }

        $this->assertCount(1, $this->scheduledSends());
    }
}
