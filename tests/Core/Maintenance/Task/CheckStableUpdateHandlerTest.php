<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\CommitInfo;
use Core\Maintenance\GitHubReleaseClientInterface;
use Core\Maintenance\ReleaseInfo;
use Core\Maintenance\Task\CheckStableUpdateHandler;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
class CheckStableUpdateHandlerTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SchedulerRepository $schedulerRepository;
    private TaskContext $context;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register('auto_update_enabled', '0', 'boolean', 'L', 'D');
        $this->settings->register('auto_update_level', 'minor', 'select', 'L', 'D', null, null, ['patch', 'minor', 'major', 'dev']);
        $this->settings->register('auto_update_day', 'monday', 'select', 'L', 'D', null, null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
        $this->settings->register('auto_update_time', '03:00', 'text', 'L', 'D');
        $this->settings->register('dev_update_branch', 'main', 'text', 'L', 'D');
        foreach (['update_latest_version', 'update_checked_at', 'update_release_notes', 'update_release_html_url', 'update_download_url', 'update_github_owner', 'update_github_repo'] as $key) {
            $this->settings->register($key, '', 'text', 'L', 'D');
        }
        $this->settings->register('update_dependencies_changed', '0', 'boolean', 'L', 'D');

        $this->schedulerRepository = new SchedulerRepository($this->pdo);

        // Nested one level below the unique base dir so dirname($storagePath)
        // — where GitHubWebhookService/VersionFile::read() actually looks —
        // is itself unique per test, rather than colliding on the shared
        // sys_get_temp_dir() every test process reads/writes VERSION under.
        $base = sys_get_temp_dir() . '/check_stable_update_handler_test_' . uniqid();
        $this->storagePath = $base . '/storage';
        mkdir($this->storagePath, 0755, true);
        file_put_contents($base . '/VERSION', "2.4.1\n");

        $connection = Connection::withPdo($this->pdo);
        $this->context = new TaskContext(
            $connection,
            $encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            $this->settings,
            new UserAccountRepository($this->pdo, $encryption),
            $this->storagePath
        );
    }

    private function fakeClient(?ReleaseInfo $release = null): GitHubReleaseClientInterface
    {
        return new class ($release) implements GitHubReleaseClientInterface {
            public function __construct(private ?ReleaseInfo $release)
            {
            }

            public function getLatestRelease(): ?ReleaseInfo
            {
                return $this->release;
            }

            public function getReleaseByTag(string $tag): ?ReleaseInfo
            {
                return null;
            }

            public function composerLockChanged(string $base, string $head): bool
            {
                return false;
            }

            public function getLatestCommit(string $branch): ?CommitInfo
            {
                return null;
            }

            public function getCommit(string $sha): ?CommitInfo
            {
                return null;
            }
        };
    }

    public function testHandleSkipsTheCheckWhenAutoUpdateIsDisabled(): void
    {
        $this->settings->set('auto_update_enabled', '0');
        $release = new ReleaseInfo('v2.5.0', 'Notes', 'https://github.com/x/y/releases/tag/v2.5.0', 'https://github.com/owner/repo/releases/download/v2.5.0/scoutmagic.zip');
        $handler = new CheckStableUpdateHandler($this->fakeClient($release));

        $handler->handle([], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'install_update'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testHandleSkipsTheCheckWhenLevelIsDev(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $release = new ReleaseInfo('v2.5.0', 'Notes', 'https://github.com/x/y/releases/tag/v2.5.0', 'https://github.com/owner/repo/releases/download/v2.5.0/scoutmagic.zip');
        $handler = new CheckStableUpdateHandler($this->fakeClient($release));

        $handler->handle([], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'install_update'")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testHandleSchedulesAnInstallWhenEnabledAndANewerReleaseIsFound(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'minor');
        $release = new ReleaseInfo('v2.5.0', 'Notes', 'https://github.com/x/y/releases/tag/v2.5.0', 'https://github.com/owner/repo/releases/download/v2.5.0/scoutmagic.zip');
        $handler = new CheckStableUpdateHandler($this->fakeClient($release));

        $handler->handle([], $this->context);

        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'install_update'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testHandleJournalsFailureInsteadOfCrashingWhenTheCheckThrows(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'minor');
        $throwing = new class implements GitHubReleaseClientInterface {
            public function getLatestRelease(): ?ReleaseInfo
            {
                throw new \RuntimeException('GitHub unavailable');
            }

            public function getReleaseByTag(string $tag): ?ReleaseInfo
            {
                return null;
            }

            public function composerLockChanged(string $base, string $head): bool
            {
                return false;
            }

            public function getLatestCommit(string $branch): ?CommitInfo
            {
                return null;
            }

            public function getCommit(string $sha): ?CommitInfo
            {
                return null;
            }
        };
        $handler = new CheckStableUpdateHandler($throwing);

        $handler->handle([], $this->context);

        $rows = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'auto_update_check_failed'")->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
    }

    public function testHandleAlwaysSelfReschedulesForTheNextDay(): void
    {
        $this->settings->set('auto_update_enabled', '0');
        $handler = new CheckStableUpdateHandler($this->fakeClient());

        $handler->handle([], $this->context);

        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'check_stable_update', 'daily');
        $this->assertNotNull($scheduled);
        $runAt = strtotime($scheduled['run_at']);
        $this->assertGreaterThan(time(), $runAt);
        $this->assertLessThanOrEqual(strtotime('+2 days'), $runAt);
    }

    public function testHandleDoesNotDuplicateAnAlreadyScheduledFutureRun(): void
    {
        $this->settings->set('auto_update_enabled', '0');
        $handler = new CheckStableUpdateHandler($this->fakeClient());

        $handler->handle([], $this->context);
        $handler->handle([], $this->context);

        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'check_stable_update', 10);
        $this->assertCount(1, $all);
    }

    public function testNextRunAtIsAtOneAmPlusJitterOnTheSameDayWhenBeforeOneAm(): void
    {
        $now = new \DateTimeImmutable('2026-07-18 00:10:00');

        $next = CheckStableUpdateHandler::nextRunAt($now);

        $this->assertSame('2026-07-18', $next->format('Y-m-d'));
        $this->assertGreaterThanOrEqual(strtotime('2026-07-18 01:00:00'), $next->getTimestamp());
        $this->assertLessThanOrEqual(strtotime('2026-07-18 02:00:00'), $next->getTimestamp());
    }

    public function testNextRunAtRollsOverToTheNextDayWhenAlreadyPastOneAm(): void
    {
        $now = new \DateTimeImmutable('2026-07-18 10:00:00');

        $next = CheckStableUpdateHandler::nextRunAt($now);

        $this->assertSame('2026-07-19', $next->format('Y-m-d'));
        $this->assertGreaterThan($now->getTimestamp(), $next->getTimestamp());
    }
}
