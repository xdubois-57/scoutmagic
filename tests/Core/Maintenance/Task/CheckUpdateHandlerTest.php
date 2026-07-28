<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\GitHubReleaseClientInterface;
use Core\Maintenance\ReleaseInfo;
use Core\Maintenance\Task\CheckUpdateHandler;
use Core\Mail\MailService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
class CheckUpdateHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SchedulerRepository $schedulerRepository;
    private TaskContext $context;
    private string $basePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($settingRepository);
        // CheckUpdateHandler writes via setInternal(), which requires the
        // setting to already be registered — mirrors public/index.php's
        // real boot sequence.
        foreach (['update_github_owner', 'update_github_repo', 'update_latest_version', 'update_checked_at', 'update_release_notes', 'update_release_html_url', 'update_download_url'] as $key) {
            $this->settings->register($key, '', 'text', $key, $key);
        }
        $this->settings->register('update_dependencies_changed', '0', 'boolean', 'update_dependencies_changed', 'update_dependencies_changed');

        $this->schedulerRepository = new SchedulerRepository($this->pdo);

        $this->basePath = sys_get_temp_dir() . '/check_update_handler_test_' . uniqid();
        mkdir($this->basePath, 0755, true);
        file_put_contents($this->basePath . '/VERSION', "1.0.0\n");
        $storagePath = $this->basePath . '/storage';
        mkdir($storagePath, 0755, true);

        $connection = Connection::withPdo($this->pdo);
        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $storagePath
        );
    }

    private function fakeClient(?ReleaseInfo $release, bool $composerLockChanged = false, ?\Throwable $compareThrows = null): GitHubReleaseClientInterface
    {
        return new class ($release, $composerLockChanged, $compareThrows) implements GitHubReleaseClientInterface {
            public int $compareCalls = 0;

            public function __construct(
                private ?ReleaseInfo $release,
                private bool $composerLockChanged,
                private ?\Throwable $compareThrows
            ) {
            }

            public function getLatestRelease(): ?ReleaseInfo
            {
                return $this->release;
            }

            public function composerLockChanged(string $base, string $head): bool
            {
                $this->compareCalls++;
                if ($this->compareThrows !== null) {
                    throw $this->compareThrows;
                }
                return $this->composerLockChanged;
            }
        };
    }

    public function testHandleStoresNewerVersionAndReleaseMetadata(): void
    {
        $release = new ReleaseInfo('v1.1.0', 'Notes de version', 'https://github.com/x/y/releases/tag/v1.1.0', 'https://example.test/artifact.zip');
        $handler = new CheckUpdateHandler($this->fakeClient($release));

        $handler->handle([], $this->context);

        $this->settings->clearCache();
        $this->assertSame('1.1.0', $this->settings->get('update_latest_version'));
        $this->assertSame('Notes de version', $this->settings->get('update_release_notes'));
        $this->assertSame('https://github.com/x/y/releases/tag/v1.1.0', $this->settings->get('update_release_html_url'));
        $this->assertSame('https://example.test/artifact.zip', $this->settings->get('update_download_url'));
        $this->assertNotSame('', $this->settings->get('update_checked_at'));
    }

    public function testHandleDetectsComposerLockChange(): void
    {
        $release = new ReleaseInfo('v2.0.0', '', 'https://github.com/x/y/releases/tag/v2.0.0', null);
        $handler = new CheckUpdateHandler($this->fakeClient($release, true));

        $handler->handle([], $this->context);

        $this->settings->clearCache();
        $this->assertSame('1', $this->settings->get('update_dependencies_changed'));
    }

    public function testHandleDoesNotCompareWhenNoNewerVersionAvailable(): void
    {
        $release = new ReleaseInfo('v1.0.0', '', 'https://github.com/x/y/releases/tag/v1.0.0', null);
        $client = $this->fakeClient($release, true);
        $handler = new CheckUpdateHandler($client);

        $handler->handle([], $this->context);

        $this->assertSame(0, $client->compareCalls);
        $this->settings->clearCache();
        $this->assertSame('0', $this->settings->get('update_dependencies_changed'));
    }

    public function testHandleClearsLatestVersionWhenNoReleasePublished(): void
    {
        $handler = new CheckUpdateHandler($this->fakeClient(null));

        $handler->handle([], $this->context);

        $this->settings->clearCache();
        $this->assertSame('', $this->settings->get('update_latest_version'));
        $this->assertNotSame('', $this->settings->get('update_checked_at'));
    }

    public function testHandleAssumesDependenciesChangedWhenCompareFails(): void
    {
        $release = new ReleaseInfo('v1.1.0', '', 'https://github.com/x/y/releases/tag/v1.1.0', null);
        $handler = new CheckUpdateHandler($this->fakeClient($release, false, new \RuntimeException('compare failed')));

        $handler->handle([], $this->context);

        $this->settings->clearCache();
        $this->assertSame('1', $this->settings->get('update_dependencies_changed'));
    }

    public function testHandleSelfReschedulesForTheNextDay(): void
    {
        $handler = new CheckUpdateHandler($this->fakeClient(null));

        $handler->handle([], $this->context);

        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'check_update', 'daily');
        $this->assertNotNull($scheduled);
        $this->assertSame('pending', $scheduled['status']);
        $this->assertGreaterThan(time(), strtotime($scheduled['run_at']));
    }

    public function testHandleDoesNotDuplicateAnAlreadyScheduledFutureCheck(): void
    {
        $handler = new CheckUpdateHandler($this->fakeClient(null));

        $handler->handle([], $this->context);
        $handler->handle([], $this->context);

        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'check_update', 10);
        $this->assertCount(1, $all);
    }

    public function testHandleNeverThrowsWhenClientFails(): void
    {
        $client = new class implements GitHubReleaseClientInterface {
            public function getLatestRelease(): ?ReleaseInfo
            {
                throw new \RuntimeException('network down');
            }

            public function composerLockChanged(string $base, string $head): bool
            {
                return false;
            }
        };
        $handler = new CheckUpdateHandler($client);

        $handler->handle([], $this->context);
        $this->addToAssertionCount(1);

        // Still reschedules for the next day despite the failure.
        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'check_update', 'daily');
        $this->assertNotNull($scheduled);
    }
}
