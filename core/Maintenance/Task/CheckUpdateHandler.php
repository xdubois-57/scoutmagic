<?php

declare(strict_types=1);

namespace Core\Maintenance\Task;

use Core\Maintenance\GitHubReleaseClient;
use Core\Maintenance\GitHubReleaseClientInterface;
use Core\Maintenance\VersionFile;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Daily background check for a newer published GitHub release (module spec:
 * never re-queried on every page load — the result is cached in
 * Core\Config\SettingService, read by Core\Http\Controller\
 * MaintenanceController::index()). Self-reschedules for the next day at the
 * end of every run (same pattern as Modules\LlmConnector\Task\
 * RefreshModelsHandler's weekly refresh) — there is no first-class
 * recurring-task concept in Core\Scheduler.
 *
 * No human requester, so no push notification here — only
 * Task\InstallUpdateHandler notifies (it always has a requester, the admin
 * who clicked "Installer").
 */
class CheckUpdateHandler implements TaskHandlerInterface
{
    private const REFERENCE = 'daily';

    public function __construct(private ?GitHubReleaseClientInterface $client = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $owner = (string) ($context->settings->get('update_github_owner') ?: '');
        $repo = (string) ($context->settings->get('update_github_repo') ?: '');
        $client = $this->client ?? new GitHubReleaseClient($owner, $repo);

        try {
            $this->checkAndStoreResult($client, $context);
            $context->journal->log('core', 'update_check_completed', 'info', 'Vérification de mise à jour effectuée');
        } catch (\Throwable $e) {
            $context->journal->log(
                'core',
                'update_check_failed',
                'info',
                'Échec de la vérification de mise à jour',
                ['error' => $e->getMessage()]
            );
        }

        $this->scheduleNextDailyCheck($context);
    }

    private function checkAndStoreResult(GitHubReleaseClientInterface $client, TaskContext $context): void
    {
        $installedVersion = VersionFile::read(dirname($context->storagePath));
        $release = $client->getLatestRelease();

        $checkedAt = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $context->settings->setInternal('update_checked_at', $checkedAt);

        if ($release === null) {
            $context->settings->setInternal('update_latest_version', '');
            $context->settings->setInternal('update_dependencies_changed', '0');
            return;
        }

        $latestVersion = $release->version();
        $dependenciesChanged = false;

        if (version_compare($latestVersion, $installedVersion, '>')) {
            try {
                $dependenciesChanged = $client->composerLockChanged('v' . $installedVersion, $release->tagName);
            } catch (\Throwable) {
                // Compare failed (e.g. the installed tag no longer exists on
                // the remote) — err on the side of caution and warn that
                // dependencies may have changed rather than silently saying
                // they haven't.
                $dependenciesChanged = true;
            }
        }

        $context->settings->setInternal('update_latest_version', $latestVersion);
        $context->settings->setInternal('update_release_notes', $release->body);
        $context->settings->setInternal('update_release_html_url', $release->htmlUrl);
        $context->settings->setInternal('update_download_url', (string) $release->downloadUrl);
        $context->settings->setInternal('update_dependencies_changed', $dependenciesChanged ? '1' : '0');
    }

    private function scheduleNextDailyCheck(TaskContext $context): void
    {
        $schedulerRepository = new SchedulerRepository($context->connection->getPdo());
        $schedulerService = new SchedulerService($schedulerRepository);

        $existing = $schedulerService->find('core', 'check_update', self::REFERENCE);
        if ($existing !== null && $existing['status'] === 'pending' && strtotime($existing['run_at']) > time()) {
            return;
        }

        $schedulerService->schedule('core', 'check_update', new \DateTimeImmutable('+1 day'), [], self::REFERENCE);
    }
}
