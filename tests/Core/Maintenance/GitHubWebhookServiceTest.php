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
        // The site is configured to update from github.com/owner/repo — every
        // release/push fixture below carries a matching repository.full_name so
        // it passes the isConfiguredRepository() gate (a dedicated test drives
        // the mismatch case).
        $this->settings->set('update_github_owner', 'owner');
        $this->settings->set('update_github_repo', 'repo');
        $this->settings->register('update_dependencies_changed', '0', 'boolean', 'D', 'D');
        $this->settings->register('auto_update_enabled', '0', 'boolean', 'D', 'D');
        $this->settings->register('auto_update_level', 'patch', 'select', 'D', 'D', null, null, ['patch', 'minor', 'major', 'dev']);
        $this->settings->register('auto_update_day', 'monday', 'select', 'D', 'D', null, null, ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday']);
        $this->settings->register('auto_update_time', '03:00', 'text', 'D', 'D');
        $this->settings->register('dev_update_branch', 'main', 'text', 'D', 'D');
        $this->settings->register('auto_update_last_push_at', '', 'text', 'D', 'D');
        $this->settings->register('auto_update_last_push_result', '', 'text', 'D', 'D');

        $this->schedulerRepository = new SchedulerRepository($this->pdo);
        $this->updateHistoryRepository = new UpdateHistoryRepository($this->pdo);

        $this->basePath = sys_get_temp_dir() . '/github_webhook_service_test_' . uniqid();
        mkdir($this->basePath, 0755, true);
        file_put_contents($this->basePath . '/VERSION', "2.4.1\n");
    }

    private function fakeClient(bool $composerLockChanged = false, ?\Throwable $throws = null, ?ReleaseInfo $latestRelease = null): GitHubReleaseClientInterface
    {
        return new class ($composerLockChanged, $throws, $latestRelease) implements GitHubReleaseClientInterface {
            public ?string $lastCompareBase = null;

            public function __construct(private bool $changed, private ?\Throwable $throws, private ?ReleaseInfo $latestRelease)
            {
            }

            public function getLatestRelease(): ?ReleaseInfo
            {
                return $this->latestRelease;
            }

            public function getReleaseByTag(string $tag): ?ReleaseInfo
            {
                return null;
            }

            /** @return array<int, ReleaseInfo> */
            public function listReleases(): array
            {
                return $this->latestRelease !== null ? [$this->latestRelease] : [];
            }

            public function composerLockChanged(string $base, string $head): bool
            {
                $this->lastCompareBase = $base;
                if ($this->throws !== null) {
                    throw $this->throws;
                }
                return $this->changed;
            }

            public function getLatestCommit(string $branch): ?\Core\Maintenance\CommitInfo
            {
                return null;
            }

            public function getCommit(string $sha): ?\Core\Maintenance\CommitInfo
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
    /** A genuine GitHub release-asset download URL (host on the allowlist). */
    private const RELEASE_ASSET_URL = 'https://github.com/owner/repo/releases/download/asset/scoutmagic.zip';

    private function releasePayload(string $tag, array $overrides = []): array
    {
        return [
            'action' => 'published',
            'repository' => ['full_name' => 'owner/repo'],
            'release' => array_merge([
                'tag_name' => $tag,
                'body' => 'Notes',
                'html_url' => 'https://github.com/owner/repo/releases/tag/' . $tag,
                'assets' => [['name' => 'scoutmagic.zip', 'browser_download_url' => self::RELEASE_ASSET_URL]],
            ], $overrides),
        ];
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

    public function testHandleReleaseEventIgnoresAnEventForADifferentRepository(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'major');
        $this->settings->clearCache();

        // A validly-signed release for a repository this site is NOT
        // configured to update from must never schedule an install — the
        // download URL it carries would otherwise install that repo's code.
        $payload = $this->releasePayload('v3.0.0', []);
        $payload['repository'] = ['full_name' => 'attacker/evil'];

        $result = $this->service()->handleReleaseEvent($payload);

        $this->assertSame(['status' => 'ignored', 'reason' => 'repository_mismatch'], $result);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions")->fetchColumn();
        $this->assertSame(0, $count);
        // Nothing about the untrusted event may reach the settings cache.
        $this->settings->clearCache();
        $this->assertSame('', (string) $this->settings->get('update_latest_version'));
    }

    public function testHandleReleaseEventRefusesADownloadUrlThatIsNotAGitHubHost(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'major');
        $this->settings->clearCache();

        // The release is newer and from the configured repo, but its asset
        // URL points off GitHub — the updater must refuse it rather than
        // fetch and unpack an arbitrary host's archive over the live tree.
        $result = $this->service()->handleReleaseEvent($this->releasePayload('v3.0.0', [
            'assets' => [['name' => 'scoutmagic.zip', 'browser_download_url' => 'https://evil.example/artifact.zip']],
        ]));

        $this->assertSame(['status' => 'ignored', 'reason' => 'download_url_refused'], $result);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions")->fetchColumn();
        $this->assertSame(0, $count);
        // The poisoned URL must not have been cached for the manual button.
        $this->settings->clearCache();
        $this->assertSame('', (string) $this->settings->get('update_download_url'));
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
     * While the configured channel is still 'dev', a dev build installed
     * from the branch is always ahead of any stable release, so a release
     * event must be treated as "not newer" (never a downgrade) even though
     * PHP's version_compare would call it newer.
     */
    public function testHandleReleaseEventDoesNotScheduleAStableReleaseOverAnInstalledDevBuildWhileStayingOnDevChannel(): void
    {
        file_put_contents($this->basePath . '/VERSION', "dev-a1b2c3d\n");
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v3.0.0'));

        $this->assertSame(['status' => 'ignored', 'reason' => 'not_newer'], $result);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'install_update'")->fetchColumn();
        $this->assertSame(0, $count);
        $historyCount = (int) $this->pdo->query("SELECT COUNT(*) FROM update_history")->fetchColumn();
        $this->assertSame(0, $historyCount);
    }

    /**
     * Once the admin has switched the configured channel away from 'dev'
     * to a numbered level (e.g. deliberately moving off a leftover dev
     * build back to stable), an installed dev build must no longer mask a
     * genuinely newer release — it must be detected and scheduled exactly
     * like any other stable update.
     */
    public function testHandleReleaseEventSchedulesAStableReleaseOverAnInstalledDevBuildWhenChannelIsNoLongerDev(): void
    {
        file_put_contents($this->basePath . '/VERSION', "dev-a1b2c3d\n");
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'major');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v3.0.0'));

        $this->assertSame(['status' => 'ok'], $result);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'install_update'")->fetchColumn();
        $this->assertSame(1, $count);
    }

    /**
     * The reported production bug: a dev build's version components parse
     * as 0.0.0, so classifying the bump type called EVERY release a major
     * bump — an admin who switched the channel from 'dev' back to the
     * default 'minor' (or 'patch') never got the release installed at all
     * ('version_type_not_allowed' on every event and daily check). Moving
     * off a dev build must be allowed at any stable level.
     */
    public function testHandleReleaseEventSchedulesAReleaseOverAnInstalledDevBuildEvenAtPatchLevel(): void
    {
        file_put_contents($this->basePath . '/VERSION', "dev-a1b2c3d\n");
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'patch');
        $this->settings->clearCache();

        $result = $this->service()->handleReleaseEvent($this->releasePayload('v3.0.0'));

        $this->assertSame(['status' => 'ok'], $result);
        $scheduled = $this->schedulerRepository->findByModuleAndKey('core', 'install_update', 'scheduled_install');
        $this->assertNotNull($scheduled);
    }

    /**
     * "vdev-{sha}" is never a real tag — the composer.lock compare for an
     * installed dev build must run from the commit sha itself, not from a
     * "v" + VERSION ref that always 404s (and therefore always reported
     * "dependencies changed" on a dev-build → release transition).
     */
    public function testHandleReleaseEventComparesComposerLockFromTheDevBuildCommitSha(): void
    {
        file_put_contents($this->basePath . '/VERSION', "dev-a1b2c3d\n");
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'minor');
        $this->settings->clearCache();

        $client = $this->fakeClient();
        $this->service($client)->handleReleaseEvent($this->releasePayload('v3.0.0'));

        $this->assertSame('a1b2c3d', $client->lastCompareBase);
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
        $this->assertSame(self::RELEASE_ASSET_URL, $payload['download_url']);
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

        $release = new ReleaseInfo('v2.5.0', 'Notes', 'https://github.com/owner/repo/releases/tag/v2.5.0', self::RELEASE_ASSET_URL);
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

        $release = new ReleaseInfo('v2.5.0', 'Notes', 'https://github.com/owner/repo/releases/tag/v2.5.0', self::RELEASE_ASSET_URL);
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

    public function testHandlePushEventIgnoresAnEventForADifferentRepository(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        // A push on the right branch but for a repo this site is not
        // configured to update from must never schedule an install — the
        // artifact URL is built from the payload's full_name.
        $payload = $this->pushPayload('main', 'a1b2c3d4e5f6');
        $payload['repository'] = ['full_name' => 'attacker/evil'];

        $result = $this->service()->handlePushEvent($payload);

        $this->assertSame(['status' => 'ignored', 'reason' => 'repository_mismatch'], $result);
        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update', 10);
        $this->assertCount(0, $all);
    }

    /**
     * The development channel installs the CI-built artifact attached to
     * the rolling `dev-build` prerelease, never GitHub's zipball of the
     * commit. The zipball is the git tree: no vendor/ (gitignored) and
     * tests/, .github/, bootstrap/ and scripts/ all copied to a production
     * webroot — and since installFiles() copies additively, the live
     * vendor/ was never replaced while composer.lock on disk was. Measured
     * on scoutmagic.be: vendor/'s mtime stayed at the original install
     * date across roughly 40 dev updates.
     */
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
        $this->assertSame(
            'https://github.com/owner/repo/releases/download/dev-build/scoutmagic-dev-a1b2c3d.zip',
            $payload['download_url']
        );
        // 'release', not 'branch': the artifact is FLAT (scripts/build-artifact.sh
        // zips `.` from the repository root), exactly like a release
        // artifact. resolveBranchArchiveRoot() exists for the zipball's
        // single wrapping "{owner}-{repo}-{sha}/" directory and would strip
        // a real top-level entry off this one.
        $this->assertSame('release', $payload['source_type']);

        $history = $this->updateHistoryRepository->findById((int) $payload['history_id']);
        $this->assertSame('dev-a1b2c3d', $history->versionTo);

        // Immediate — runs at once (delay 0), unlike the release path's
        // weekly slot — but still carries its own reference so a rapid
        // follow-up push can dedup it (see the test below).
        $this->assertSame('push_install', $all[0]['reference']);
    }

    /**
     * The URL the push path builds must survive the allowlist the
     * downloader applies before the first byte
     * (Core\Maintenance\GitHubUrlValidator): a release-asset download
     * starts on github.com and redirects to objects.githubusercontent.com,
     * both of which the stable channel's own asset installs already
     * needed. Asserted here rather than assumed, because the failure mode
     * is an update refused on every push with nothing else to explain it.
     */
    public function testTheDevArtifactUrlIsAcceptedByTheDownloadAllowlist(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $this->service()->handlePushEvent($this->pushPayload('main', 'a1b2c3d4e5f6'));

        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update', 10);
        $payload = json_decode((string) $all[0]['payload'], true);

        $this->assertTrue(\Core\Maintenance\GitHubUrlValidator::isAllowed((string) $payload['download_url']));
        $this->assertTrue(\Core\Maintenance\GitHubUrlValidator::isAllowed(
            'https://objects.githubusercontent.com/github-production-release-asset/1/2?token=x'
        ));
    }

    /**
     * The webhook fires the instant the push lands; the artifact only
     * exists once CI has resolved dependencies, zipped the tree and
     * uploaded the asset. The deadline in the payload is how long
     * Task\InstallUpdateHandler may keep waiting for it before failing —
     * without it, the very first install attempt would 404 and roll back.
     */
    public function testHandlePushEventGivesTheInstallADeadlineToWaitForTheArtifact(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $before = time();
        $this->service()->handlePushEvent($this->pushPayload('main', 'a1b2c3d4e5f6'));
        $after = time();

        $all = $this->schedulerRepository->findByModuleAndTaskKey('core', 'install_update', 10);
        $payload = json_decode((string) $all[0]['payload'], true);

        $this->assertArrayHasKey('wait_for_artifact_until', $payload);
        $this->assertGreaterThanOrEqual($before + 600, (int) $payload['wait_for_artifact_until']);
        $this->assertLessThanOrEqual($after + 600, (int) $payload['wait_for_artifact_until']);

        // The handler is never told its own reference, so it travels in
        // the payload: a retry scheduled while waiting must stay findable
        // by supersedeQueuedInstall(), or a newer push would leave two
        // installs due at once.
        $this->assertSame('push_install', $payload['reference']);
    }

    /**
     * This used to be hardcoded false, so the update history's
     * "dépendances" column read "non" on the development channel however
     * much composer.lock had moved.
     */
    public function testHandlePushEventRecordsThatDependenciesChanged(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $client = $this->fakeClient(true);
        $this->service($client)->handlePushEvent($this->pushPayload('main', 'a1b2c3d4e5f6'));

        $rows = $this->updateHistoryRepository->findRecent(10);
        $this->assertTrue($rows[0]->dependenciesChanged);
        // Compared from the installed version's tag to the pushed commit
        // itself — there is no release tag to compare against here.
        $this->assertSame('v2.4.1', $client->lastCompareBase);
    }

    public function testHandlePushEventComparesFromTheCommitShaWhenADevBuildIsInstalled(): void
    {
        file_put_contents($this->basePath . '/VERSION', "dev-a1b2c3d\n");
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $client = $this->fakeClient(false);
        $this->service($client)->handlePushEvent($this->pushPayload('main', 'ffffffffffff'));

        // "vdev-a1b2c3d" is never a real tag; the bare sha is.
        $this->assertSame('a1b2c3d', $client->lastCompareBase);
    }

    /**
     * The release path assumes "changed" when the compare fails, because
     * there the flag warns an admin about dependencies they cannot install
     * by hand. The development path must not: its artifact carries vendor/
     * itself, so the flag is a note about what happened — and a GitHub API
     * hiccup must never block the install or dress the history row up as
     * something it is not.
     */
    public function testHandlePushEventDegradesToUnchangedWhenTheCompareFails(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $service = $this->service($this->fakeClient(true, new \RuntimeException('compare failed')));
        $result = $service->handlePushEvent($this->pushPayload('main', 'a1b2c3d4e5f6'));

        $this->assertSame('ok', $result['status'], 'a failed compare must never stop the install');
        $rows = $this->updateHistoryRepository->findRecent(10);
        $this->assertFalse($rows[0]->dependenciesChanged);
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
        $this->assertSame(
            'https://github.com/owner/repo/releases/download/dev-build/scoutmagic-dev-bbbbbbb.zip',
            $payload['download_url']
        );
    }

    /**
     * Observed on scoutmagic.be: two pushes two minutes apart left the
     * first push's update_history row at 'pending' forever, because
     * cancelling the scheduled action was all that happened. Nothing else
     * could ever move that row — findInProgress() and
     * markOtherInProgressAsFailed() both deliberately exclude 'pending',
     * and the staleness net only looks at rows already running — so the
     * maintenance page showed two updates "En cours" from the same
     * version, which reads exactly like two migrations in parallel.
     */
    public function testASupersededPushInstallDoesNotLeaveItsHistoryRowPendingForever(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->set('dev_update_branch', 'main');
        $this->settings->clearCache();

        $this->service()->handlePushEvent($this->pushPayload('main', 'aaaaaaaaaaaa'));
        $this->service()->handlePushEvent($this->pushPayload('main', 'bbbbbbbbbbbb'));

        $rows = $this->updateHistoryRepository->findRecent(10);
        $byVersion = [];
        foreach ($rows as $row) {
            $byVersion[$row->versionTo] = $row;
        }

        $this->assertSame('failed', $byVersion['dev-aaaaaaa']->status);
        $this->assertStringContainsString('push plus récent', (string) $byVersion['dev-aaaaaaa']->errorMessage);
        $this->assertSame('pending', $byVersion['dev-bbbbbbb']->status, 'the newest push is the one still to install');
    }

    public function testASupersededReleaseInstallDoesNotLeaveItsHistoryRowPendingForever(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'major');
        $this->settings->clearCache();

        $this->service()->handleReleaseEvent($this->releasePayload('v2.5.0'));
        $this->service()->handleReleaseEvent($this->releasePayload('v2.6.0'));

        $rows = $this->updateHistoryRepository->findRecent(10);
        $byVersion = [];
        foreach ($rows as $row) {
            $byVersion[$row->versionTo] = $row;
        }

        // The tag's leading "v" is stripped on the way into update_history.
        $this->assertSame('failed', $byVersion['2.5.0']->status);
        $this->assertStringContainsString('release plus récente', (string) $byVersion['2.5.0']->errorMessage);
        $this->assertSame('pending', $byVersion['2.6.0']->status);
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

    // --- isBumpAllowed() ---

    public function testIsBumpAllowedGatesSemverBumpsByLevel(): void
    {
        $this->assertTrue(GitHubWebhookService::isBumpAllowed('2.4.1', '2.4.2', 'patch'));
        $this->assertFalse(GitHubWebhookService::isBumpAllowed('2.4.1', '2.5.0', 'patch'));
        $this->assertTrue(GitHubWebhookService::isBumpAllowed('2.4.1', '2.5.0', 'minor'));
        $this->assertFalse(GitHubWebhookService::isBumpAllowed('2.4.1', '3.0.0', 'minor'));
        $this->assertTrue(GitHubWebhookService::isBumpAllowed('2.4.1', '3.0.0', 'major'));
    }

    public function testIsBumpAllowedAlwaysAllowsLeavingAnInstalledDevBuild(): void
    {
        $this->assertTrue(GitHubWebhookService::isBumpAllowed('dev-a1b2c3d', '2.4.2', 'patch'));
        $this->assertTrue(GitHubWebhookService::isBumpAllowed('dev-a1b2c3d', '3.0.0', 'minor'));
    }

    // --- nextOccurrence() ---

    /**
     * The configured slot is wall-clock Brussels time, never the server's
     * PHP timezone (frequently UTC on shared hosting): "monday 03:00" is
     * 02:00 UTC in winter (CET, +01:00)…
     */
    public function testNextOccurrenceInterpretsTheSlotInBrusselsWinterTime(): void
    {
        // Wednesday 2026-01-07 noon UTC → next Monday is 2026-01-12.
        $now = new \DateTimeImmutable('2026-01-07 12:00:00', new \DateTimeZone('UTC'));

        $runAt = GitHubWebhookService::nextOccurrence('monday', '03:00', $now);

        $this->assertSame('2026-01-12 02:00:00', $runAt->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $runAt->getTimezone()->getName(), 'must be converted back to the caller\'s timezone for naive run_at storage');
    }

    /**
     * …and 01:00 UTC in summer (CEST, +02:00) — the DST shift must follow
     * the local wall clock, not a fixed offset.
     */
    public function testNextOccurrenceInterpretsTheSlotInBrusselsSummerTime(): void
    {
        // Wednesday 2026-07-08 noon UTC → next Monday is 2026-07-13.
        $now = new \DateTimeImmutable('2026-07-08 12:00:00', new \DateTimeZone('UTC'));

        $runAt = GitHubWebhookService::nextOccurrence('monday', '03:00', $now);

        $this->assertSame('2026-07-13 01:00:00', $runAt->format('Y-m-d H:i:s'));
    }

    public function testNextOccurrencePushesANearImmediateSlotAFullWeekOut(): void
    {
        // Monday 2026-01-12 01:58 UTC = 02:58 Brussels — the "monday 03:00"
        // slot is only 2 minutes away, inside the 5-minute guard.
        $now = new \DateTimeImmutable('2026-01-12 01:58:00', new \DateTimeZone('UTC'));

        $runAt = GitHubWebhookService::nextOccurrence('monday', '03:00', $now);

        $this->assertSame('2026-01-19 02:00:00', $runAt->format('Y-m-d H:i:s'));
    }

    /**
     * The reason a push was dropped used to live only in the HTTP response
     * and was then forgotten, while GitHub logged a 200 either way — so
     * nothing on the site could say why the channel had gone quiet.
     */
    public function testAnIgnoredPushRecordsItsReasonForTheMaintenancePage(): void
    {
        $this->service()->handlePushEvent($this->pushPayload('main'));

        $this->settings->clearCache();
        $this->assertSame('dev_mode_disabled', $this->settings->get('auto_update_last_push_result'));
        $this->assertNotSame('', (string) $this->settings->get('auto_update_last_push_at'));
    }

    /**
     * GitHub sends a push event for every ref, so feature branches and tags
     * land here constantly and are rightly dropped. Recording them would
     * overwrite the last meaningful outcome within minutes and leave the
     * page reporting noise as the diagnosis.
     */
    public function testAPushToAnotherBranchIsNotRecorded(): void
    {
        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->clearCache();

        $result = $this->service()->handlePushEvent($this->pushPayload('claude/some-feature'));

        $this->assertSame('branch_mismatch', $result['reason']);
        $this->settings->clearCache();
        $this->assertSame('', (string) $this->settings->get('auto_update_last_push_result'));
        $this->assertSame('', (string) $this->settings->get('auto_update_last_push_at'));
    }

    /**
     * A branch_mismatch arriving after a real outcome must not erase it —
     * that is the case that makes the record useless in practice, since
     * other branches are pushed far more often than the tracked one.
     */
    public function testABranchMismatchDoesNotOverwriteAnEarlierRecordedOutcome(): void
    {
        $this->service()->handlePushEvent($this->pushPayload('main'));
        $this->settings->clearCache();
        $this->assertSame('dev_mode_disabled', $this->settings->get('auto_update_last_push_result'));

        $this->settings->set('auto_update_enabled', '1');
        $this->settings->set('auto_update_level', 'dev');
        $this->settings->clearCache();
        $this->service()->handlePushEvent($this->pushPayload('claude/other'));

        $this->settings->clearCache();
        $this->assertSame('dev_mode_disabled', $this->settings->get('auto_update_last_push_result'));
    }

}
