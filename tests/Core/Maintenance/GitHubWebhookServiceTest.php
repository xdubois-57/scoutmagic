<?php

declare(strict_types=1);

namespace Tests\Core\Maintenance;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\GitHubReleaseClientInterface;
use Core\Maintenance\GitHubWebhookService;
use Core\Maintenance\ReleaseInfo;
use Core\Maintenance\UpdateHistoryRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GitHubWebhookServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SchedulerRepository $schedulerRepository;
    private UpdateHistoryRepository $updateHistoryRepository;
    private string $basePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        foreach (['update_latest_version', 'update_checked_at', 'update_release_notes', 'update_release_html_url', 'update_download_url', 'update_github_owner', 'update_github_repo'] as $key) {
            $this->settings->register($key, '', 'text', $key, $key);
        }
        $this->settings->register('update_dependencies_changed', '0', 'boolean', 'D', 'D');
        $this->settings->register('auto_update_enabled', '0', 'boolean', 'D', 'D');
        $this->settings->register('auto_update_level', 'patch', 'select', 'D', 'D', null, null, ['patch', 'minor', 'major', 'dev']);
        $this->settings->register('auto_update_day', 'monday', 'select', 'D', 'D', null, null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
        $this->settings->register('auto_update_time', '03:00', 'text', 'D', 'D');
        $this->settings->register('dev_update_branch', 'main', 'text', 'D', 'D');

        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->updateHistoryRepository = new UpdateHistoryRepository($this->pdo);

        $this->basePath = sys_get_temp_dir() . '/github_webhook_service_test_' . uniqid();
        mkdir($this->basePath, 0755, true);
        file_put_contents($this->basePath . '/VERSION', "2.4.1\n");
    }

    private function fakeClient(bool $composerLockChanged = false, ?\Throwable $throws = null, ?ReleaseInfo $latestRelease = null): GitHubReleaseClientInterface
    {
        return new class ($composerLockChanged, $throws, $latestRelease) implements GitHubReleaseClientInterface {
            public function __construct(private bool $changed, private ?\Throwable $throws, private ?ReleaseInfo $latestRelease)
            {
            }

            public function getLatestRelease(): ?ReleaseInfo
            {
                return $this->latestRelease;
            }

            public function composerLockChanged(string $base, string $head): bool
            {
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                return $this->changed;
            }

            public function getLatestCommit(string $branch): ?\Core\Maintenance\CommitInfo
            {
                return null;
            }
        };
    }

    private function service(?GitHubReleaseClientInterface $client = null): GitHubWebhookService
    {
        return new GitHubWebhookService(
            $this->settings,
            new SchedulerService($this->schedulerRepository),
            $this->updateHistoryRepository,
            new JournalService(new JournalRepository($this->pdo)),
            $this->basePath,
            $client ?? $this->fakeClient()
        );
    }

    // --- verifySignature() ---

    public function testVerifySignatureAcceptsAValidSignature(): void
    {
        $service = $this->service();
        $body = '{"action":"published"}';
        $secret = 'test-secret';
        $signature = 'sha256=' . hash_hmac('sha256', $body, $secret);

        $this->assertTrue($service->verifySignature($body, $signature, $secret));
    }

    public function testVerifySignatureRejectsAnIncorrectSignature(): void
    {
        $service = $this->service();
        $this->assertFalse($service->verifySignature('{"a":1}', 'sha256=deadbeef', 'test-secret'));
    }

    public function testVerifySignatureRejectsAnEmptySecret(): void
    {
        $service = $this->service();
        $body = '{"a":1}';
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'anything');

        $this->assertFalse($service->verifySignature($body, $signature, ''));
    }

    public function testVerifySignatureRejectsAMissingSignatureHeader(): void
    {
        $service = $this->service();
        $this->assertFalse($service->verifySignature('{"a":1}', '', 'test-secret'));
    }

    // --- handleReleaseEvent() ---

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function releasePayload(string $tag, array $overrides = []): array
    {
        return array_merge([
            'action' => 'published',
            'release' => array_merge([
                'tag_name' => $tag,
                'body' => 'Notes',
                'html_url' => 'https://github.com/x/y/releases/tag/' . $tag,
                'assets' => [['browser_download_url' => 'https://example.test/artifact.zip']],
            ], $overrides),
        ], []);
    }

    public function testHandleReleaseEventIgnoresNonPublishedActions(): void
    {
        $result = $this->service()->handleReleaseEvent(['action' => 'created', 'release' => ['tag_name' => 'v2.5.0']]);
        $this->assertSame(['status' => 'ignored', 'reason' => 'action_not_published'], $result);
    }

    public function testHandleReleaseEventIgnoresInvalidPayload(): void
    {
        $result = $this->service()->handleReleaseEvent(['action' => 'published']);
        $this->assertSame('ignored', $result['status']);
        $this->assertSame('invalid_payload', $result['reason']);
    }

    public function testHandleReleaseEventUpdatesTheSettingsCacheEvenWhenNotNewer(): void
    {
        $result = $this->service()->handleReleaseEvent($this->releasePayload('v2.4.1'));

        $this->assertSame('ignored', $result['status']);
        $this->assertSame('not_newer', $result['reason']);
        $this->settings->clearCache();
        $this->assertSame('2.4.1', $this->settings->get('update_latest_version'));
    }

    /**
     * A dev build installed from the branch is always ahead of any stable
     * release, so a release event must be treated as "not newer" (never a
     * downgrade) even though PHP's version_compare would call it newer.
     */
    public function testHandleReleaseEventDoesNotScheduleAStableReleaseOverAnInstalledDevBuild(): void
    {
        file_put_contents($this->basePath . '/VERSION', "dev-a1b2c3d\n");
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'major');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v3.0.0'));

        $this->assertSame(['status' => 'ignored', 'reason' => 'not_newer'], $result);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'install_update'")->fetchColumn();
        $this->assertSame(0, $count);
        $historyCount = (int) $this->pdo->query("SELECT COUNT(*) FROM update_history")->fetchColumn();
        $this->assertSame(0, $historyCount);
    }

    public function testHandleReleaseEventIgnoredWhenAutoUpdateDisabled(): void
    {
        $result = $this->service()->handleReleaseEvent($this->releasePayload('v2.4.2'));

        $this->assertSame(['status' => 'ignored', 'reason' => 'auto_update_disabled'], $result);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions")->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testHandleReleaseEventIgnoredWhenDevModeActive(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v2.4.2'));

        $this->assertSame(['status' => 'ignored', 'reason' => 'dev_mode_active'], $result);
    }

    public function testHandleReleaseEventIgnoredWhenVersionBumpExceedsAllowedLevel(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'patch');
        $this->settings->clearCache();

        // 2.4.1 -> 2.5.0 is a minor bump, level only allows patch.
        $result = $this->service()->handleReleaseEvent($this->releasePayload('v2.5.0'));

        $this->assertSame(['status' => 'ignored', 'reason' => 'version_type_not_allowed'], $result);
    }

    public function testHandleReleaseEventSchedulesInstallWhenPatchBumpAllowedAtPatchLevel(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'patch');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v2.4.2'));

        $this->assertSame('ok', $result['status']);
        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'install_update', 'scheduled_install');
        $this->assertNotNull($scheduled);
        $payload = json_decode((string) $scheduled['payload'], true);
        $this->assertSame('release', $payload['source_type']);
        $this->assertSame('https://example.test/artifact.zip', $payload['download_url']);
    }

    public function testHandleReleaseEventSchedulesInstallWhenMinorBumpAllowedAtMinorLevel(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'minor');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v2.5.0'));

        $this->assertSame('ok', $result['status']);
    }

    public function testHandleReleaseEventSchedulesInstallWhenMajorBumpAllowedAtMajorLevel(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'major');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v3.0.0'));

        $this->assertSame('ok', $result['status']);
    }

    public function testHandleReleaseEventCancelsAPreviouslyScheduledInstallBeforeSchedulingANewOne(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'patch');
        $this->settings->clearCache();

        $this->service()->handleReleaseEvent($this->releasePayload('v2.4.2'));
        $first = $this->schedulerRepository->findByModuleAndKey('core', 'install_update', 'scheduled_install');
        $this->assertNotNull($first);

        // A second, newer patch release replaces the first pending slot.
        $this->service()->handleReleaseEvent($this->releasePayload('v2.4.3'));

        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update', 10);
        $pending = array_filter($all, fn($a) => $a['status'] === 'pending');
        $this->assertCount(1, $pending);
    }

    public function testHandleReleaseEventPushesAWithinFiveMinuteSlotToNextWeek(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'patch');
        // Configure the slot for right now (within the next 5 minutes).
        $now = new \DateTimeImmutable();
        $this->settings->set('auto_update_day', strtolower($now->format('l')));
        $this->settings->set('auto_update_time', $now->modify('+2 minutes')->format('H:i'));
        $this->settings->clearCache();

        $this->service()->handleReleaseEvent($this->releasePayload('v2.4.2'));

        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'install_update', 'scheduled_install');
        $this->assertNotNull($scheduled);
        $runAt = new \DateTimeImmutable($scheduled['run_at']);
        $this->assertGreaterThan($now->modify('+6 days'), $runAt, 'a near-immediate slot must be pushed a full week out');
    }

    public function testHandleReleaseEventMarksDependenciesChangedWhenComposerLockDiffers(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->clearCache();

        $service = $this->service($this->fakeClient(true));
        $service->handleReleaseEvent($this->releasePayload('v2.4.2'));

        $this->settings->clearCache();
        $this->assertSame('1', $this->settings->get('update_dependencies_changed'));
    }

    public function testHandleReleaseEventAssumesDependenciesChangedWhenCompareFails(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->clearCache();

        $service = $this->service($this->fakeClient(false, new \RuntimeException('compare failed')));
        $service->handleReleaseEvent($this->releasePayload('v2.4.2'));

        $this->settings->clearCache();
        $this->assertSame('1', $this->settings->get('update_dependencies_changed'));
    }

    public function testHandleReleaseEventIgnoresWhenNoDownloadUrlIsAvailable(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v2.4.2', ['assets' => []]));

        $this->assertSame(['status' => 'ignored', 'reason' => 'no_download_url'], $result);
    }

    public function testHandleReleaseEventFallsBackToZipballUrl(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v2.4.2', [
            'assets' => [],
            'zipball_url' => 'https://api.github.com/repos/x/y/zipball/v2.4.2',
        ]));

        $this->assertSame('ok', $result['status']);
        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'install_update', 'scheduled_install');
        $payload = json_decode((string) $scheduled['payload'], true);
        $this->assertSame('https://api.github.com/repos/x/y/zipball/v2.4.2', $payload['download_url']);
    }

    // --- checkForNewRelease() ---

    public function testCheckForNewReleaseIgnoresWhenNoReleaseIsFound(): void
    {
        $result = $this->service($this->fakeClient())->checkForNewRelease();

        $this->assertSame(['status' => 'ignored', 'reason' => 'no_release_found'], $result);
    }

    public function testCheckForNewReleaseSchedulesInstallWhenAllowedBumpIsFound(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'minor');
        $this->settings->clearCache();

        $release = new ReleaseInfo('v2.5.0', 'Notes', 'https://github.com/x/y/releases/tag/v2.5.0', 'https://example.test/artifact.zip');
        $result = $this->service($this->fakeClient(false, null, $release))->checkForNewRelease();

        $this->assertSame('ok', $result['status']);
        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'install_update', 'scheduled_install');
        $this->assertNotNull($scheduled);
    }

    public function testCheckForNewReleaseIgnoredWhenDevModeActive(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->clearCache();

        $release = new ReleaseInfo('v2.5.0', 'Notes', 'https://github.com/x/y/releases/tag/v2.5.0', 'https://example.test/artifact.zip');
        $result = $this->service($this->fakeClient(false, null, $release))->checkForNewRelease();

        $this->assertSame(['status' => 'ignored', 'reason' => 'dev_mode_active'], $result);
    }

    // --- handlePushEvent() ---

    /**
     * @return array<string, mixed>
     */
    private function pushPayload(string $branch, string $sha = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'): array
    {
        return [
            'ref' => 'refs/heads/' . $branch,
            'after' => $sha,
            'repository' => ['full_name' => 'owner/repo'],
        ];
    }

    public function testHandlePushEventIgnoredWhenDevModeDisabled(): void
    {
        $result = $this->service()->handlePushEvent($this->pushPayload('main'));
        $this->assertSame(['status' => 'ignored', 'reason' => 'dev_mode_disabled'], $result);
    }

    public function testHandlePushEventIgnoredWhenBranchDoesNotMatch(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $result = $this->service()->handlePushEvent($this->pushPayload('feature/x'));

        $this->assertSame(['status' => 'ignored', 'reason' => 'branch_mismatch'], $result);
    }

    public function testHandlePushEventSchedulesImmediateInstallWhenBranchMatches(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $result = $this->service()->handlePushEvent($this->pushPayload('main', 'a1b2c3d4e5f6'));

        $this->assertSame('ok', $result['status']);
        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update', 10);
        $this->assertCount(1, $all);
        $payload = json_decode((string) $all[0]['payload'], true);
        $this->assertSame('branch', $payload['source_type']);
        $this->assertSame('https://api.github.com/repos/owner/repo/zipball/a1b2c3d4e5f6', $payload['download_url']);

        $history = $this->updateHistoryRepository->findById((int) $payload['history_id']);
        $this->assertSame('dev-a1b2c3d', $history->versionTo);

        // Immediate — runs at once (delay 0), unlike the release path's
        // weekly slot — but still carries its own reference so a rapid
        // follow-up push can dedup it (see the test below).
        $this->assertSame('push_install', $all[0]['reference']);
    }

    public function testHandlePushEventCancelsAStillPendingEarlierPushInstall(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $this->service()->handlePushEvent($this->pushPayload('main', 'aaaaaaaaaaaa'));
        $this->service()->handlePushEvent($this->pushPayload('main', 'bbbbbbbbbbbb'));

        // Two rapid pushes must never leave two tasks both due at once —
        // that's exactly what once let two InstallUpdateHandler runs copy
        // over the live install directory at the same time. Only the
        // second (newest) commit's install should still be pending.
        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update', 10);
        $this->assertCount(2, $all);
        $statuses = array_column($all, 'status');
        sort($statuses);
        $this->assertSame(['canceled', 'pending'], $statuses);

        $pending = array_values(array_filter($all, static fn(array $row) => $row['status'] === 'pending'))[0];
        $payload = json_decode((string) $pending['payload'], true);
        $this->assertSame('https://api.github.com/repos/owner/repo/zipball/bbbbbbbbbbbb', $payload['download_url']);
    }

    public function testHandlePushEventIgnoresAPushWhileAnotherUpdateIsActivelyInstalling(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $runningId = $this->updateHistoryRepository->create('1.0.0', 'dev-running', false, null);
        $this->updateHistoryRepository->setStatus($runningId, 'downloading');

        $result = $this->service()->handlePushEvent($this->pushPayload('main', 'cccccccccccc'));

        $this->assertSame(['status' => 'ignored', 'reason' => 'update_in_progress'], $result);
        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update', 10);
        $this->assertCount(0, $all);
    }

    public function testHandlePushEventIgnoresAPushWithNoCommitSha(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $result = $this->service()->handlePushEvent(['ref' => 'refs/heads/main', 'after' => '', 'repository' => ['full_name' => 'owner/repo']]);

        $this->assertSame(['status' => 'ignored', 'reason' => 'invalid_payload'], $result);
    }
}
