<?php

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Config\SettingService;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;

/**
 * Handles the two GitHub webhook events (Core\Http\Controller\
 * WebhookController::github()) that replace the old daily-polling
 * Task\CheckUpdateHandler: a published release (stable update path,
 * schedules Task\InstallUpdateHandler at the admin's configured weekly
 * slot, gated by the allowed version-bump level) and a push (development
 * mode only — installs immediately from the branch's zipball, ignoring
 * both the level gate and the weekly slot).
 *
 * Signature verification (verifySignature()) is a separate, pure method
 * with no dependencies, deliberately kept out of both event handlers —
 * Http\Controller\WebhookController checks it once, before either handler
 * ever runs.
 */
class GitHubWebhookService
{
    /** @var array<string, array<int, string>> allowed version-bump types per configured level */
    private const LEVEL_ALLOWS = [
        'patch' => ['patch'],
        'minor' => ['patch', 'minor'],
        'major' => ['patch', 'minor', 'major'],
    ];

    /** @var array<string, int> ISO-8601 day-of-week number (1=Monday) */
    private const DAY_ISO = [
        'monday' => 1, 'tuesday' => 2, 'wednesday' => 3, 'thursday' => 4,
        'friday' => 5, 'saturday' => 6, 'sunday' => 7,
    ];

    private const SCHEDULED_INSTALL_REFERENCE = 'scheduled_install';

    public function __construct(
        private SettingService $settings,
        private SchedulerService $schedulerService,
        private UpdateHistoryRepository $updateHistoryRepository,
        private JournalService $journalService,
        private string $basePath,
        private ?GitHubReleaseClientInterface $releaseClient = null
    ) {
    }

    /**
     * GitHub signs the raw request body with the shared secret
     * (HMAC-SHA256, hex-encoded, prefixed "sha256=") — constant-time
     * comparison so response timing can't leak how much of the signature
     * matched.
     */
    public function verifySignature(string $rawBody, string $signatureHeader, string $secret): bool
    {
        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, reason?: string}
     */
    public function handleReleaseEvent(array $payload): array
    {
        if (($payload['action'] ?? '') !== 'published') {
            return ['status' => 'ignored', 'reason' => 'action_not_published'];
        }

        $release = $payload['release'] ?? null;
        if (!is_array($release) || empty($release['tag_name'])) {
            return ['status' => 'ignored', 'reason' => 'invalid_payload'];
        }

        return $this->processRelease(
            (string) $release['tag_name'],
            (string) ($release['body'] ?? ''),
            (string) ($release['html_url'] ?? ''),
            $this->resolveReleaseDownloadUrl($release)
        );
    }

    /**
     * Daily fallback for the stable channel (Task\CheckStableUpdateHandler)
     * — the webhook is the primary detection mechanism, but a missed or
     * misconfigured delivery would otherwise leave patch/minor/major sites
     * unaware of a new release until an admin manually checks. Shares
     * processRelease() with handleReleaseEvent() so both paths schedule
     * installs identically; the only difference is where the release comes
     * from (a webhook payload vs. a live API call here).
     *
     * @return array{status: string, reason?: string}
     */
    public function checkForNewRelease(): array
    {
        $release = $this->releaseClient()->getLatestRelease();
        if ($release === null) {
            return ['status' => 'ignored', 'reason' => 'no_release_found'];
        }

        return $this->processRelease($release->tagName, $release->body, $release->htmlUrl, $release->downloadUrl);
    }

    /**
     * @return array{status: string, reason?: string}
     */
    private function processRelease(string $tagName, string $body, string $htmlUrl, ?string $downloadUrl): array
    {
        $latestVersion = ltrim($tagName, 'vV');

        // Same cache Task\CheckUpdateHandler used to populate — kept in
        // sync regardless of whether this specific release ends up
        // auto-installed, so the admin always sees the true latest version
        // on the Maintenance page.
        $this->settings->setInternal('update_checked_at', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->settings->setInternal('update_latest_version', $latestVersion);
        $this->settings->setInternal('update_release_notes', $body);
        $this->settings->setInternal('update_release_html_url', $htmlUrl);
        $this->settings->setInternal('update_download_url', (string) $downloadUrl);

        $installedVersion = VersionFile::read($this->basePath);
        if (!version_compare($latestVersion, $installedVersion, '>')) {
            $this->settings->setInternal('update_dependencies_changed', '0');
            return ['status' => 'ignored', 'reason' => 'not_newer'];
        }

        $dependenciesChanged = $this->composerLockChanged($installedVersion, $tagName);
        $this->settings->setInternal('update_dependencies_changed', $dependenciesChanged ? '1' : '0');

        if (!(bool) ((int) ($this->settings->get('auto_update_enabled') ?: '0'))) {
            return ['status' => 'ignored', 'reason' => 'auto_update_disabled'];
        }

        $level = (string) ($this->settings->get('auto_update_level') ?: 'minor');
        if ($level === 'dev') {
            // Development mode takes over the install path entirely — a
            // stable release arriving while it's active is deliberately
            // never auto-installed (module spec).
            return ['status' => 'ignored', 'reason' => 'dev_mode_active'];
        }

        if ($downloadUrl === null) {
            return ['status' => 'ignored', 'reason' => 'no_download_url'];
        }

        $bumpType = $this->classifyVersionBump($installedVersion, $latestVersion);
        $allowed = self::LEVEL_ALLOWS[$level] ?? self::LEVEL_ALLOWS['patch'];
        if (!in_array($bumpType, $allowed, true)) {
            return ['status' => 'ignored', 'reason' => 'version_type_not_allowed'];
        }

        $day = (string) ($this->settings->get('auto_update_day') ?: 'monday');
        $time = (string) ($this->settings->get('auto_update_time') ?: '03:00');
        $runAt = $this->nextOccurrence($day, $time, new \DateTimeImmutable());

        $historyId = $this->updateHistoryRepository->create($installedVersion, $latestVersion, $dependenciesChanged, null);

        // A later release arriving before the previous one's slot fires
        // replaces it — only the newest known release should ever be
        // pending for the weekly slot.
        $this->schedulerService->cancelPending('core', 'install_update', self::SCHEDULED_INSTALL_REFERENCE);
        $this->schedulerService->schedule(
            'core',
            'install_update',
            $runAt,
            ['history_id' => $historyId, 'download_url' => $downloadUrl, 'source_type' => 'release'],
            self::SCHEDULED_INSTALL_REFERENCE
        );

        $this->journalService->log(
            'core',
            'auto_update_scheduled',
            'info',
            'Installation automatique planifiée suite à une nouvelle release GitHub',
            ['version_from' => $installedVersion, 'version_to' => $latestVersion, 'run_at' => $runAt->format('Y-m-d H:i:s')]
        );

        return ['status' => 'ok'];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, reason?: string}
     */
    public function handlePushEvent(array $payload): array
    {
        $enabled = (bool) ((int) ($this->settings->get('auto_update_enabled') ?: '0'));
        $level = (string) ($this->settings->get('auto_update_level') ?: 'minor');
        if (!$enabled || $level !== 'dev') {
            return ['status' => 'ignored', 'reason' => 'dev_mode_disabled'];
        }

        $ref = (string) ($payload['ref'] ?? '');
        $pushedBranch = str_starts_with($ref, 'refs/heads/') ? substr($ref, strlen('refs/heads/')) : $ref;
        $configuredBranch = (string) ($this->settings->get('dev_update_branch') ?: 'main');

        if ($pushedBranch === '' || $pushedBranch !== $configuredBranch) {
            return ['status' => 'ignored', 'reason' => 'branch_mismatch'];
        }

        $sha = (string) ($payload['after'] ?? '');
        $repoFullName = (string) ($payload['repository']['full_name'] ?? '');
        if ($sha === '' || $repoFullName === '') {
            return ['status' => 'ignored', 'reason' => 'invalid_payload'];
        }

        $shortSha = substr($sha, 0, 7);
        $versionTo = 'dev-' . $shortSha;
        $downloadUrl = "https://api.github.com/repos/{$repoFullName}/zipball/{$sha}";
        $installedVersion = VersionFile::read($this->basePath);

        $historyId = $this->updateHistoryRepository->create($installedVersion, $versionTo, false, null);

        $this->schedulerService->scheduleAfter(
            'core',
            'install_update',
            0,
            ['history_id' => $historyId, 'download_url' => $downloadUrl, 'source_type' => 'branch']
        );

        $this->journalService->log(
            'core',
            'auto_update_scheduled',
            'info',
            'Installation immédiate planifiée suite à un push sur la branche de développement',
            ['branch' => $pushedBranch, 'version_from' => $installedVersion, 'version_to' => $versionTo]
        );

        return ['status' => 'ok'];
    }

    /**
     * @param array<string, mixed> $release
     */
    private function resolveReleaseDownloadUrl(array $release): ?string
    {
        $assets = $release['assets'] ?? [];
        if (is_array($assets) && !empty($assets[0]['browser_download_url'])) {
            return (string) $assets[0]['browser_download_url'];
        }
        if (!empty($release['zipball_url'])) {
            return (string) $release['zipball_url'];
        }

        return null;
    }

    private function composerLockChanged(string $installedVersion, string $latestTag): bool
    {
        try {
            return $this->releaseClient()->composerLockChanged('v' . $installedVersion, $latestTag);
        } catch (\Throwable) {
            // Same "err on the side of caution" fallback Task\CheckUpdateHandler used.
            return true;
        }
    }

    private function releaseClient(): GitHubReleaseClientInterface
    {
        if ($this->releaseClient !== null) {
            return $this->releaseClient;
        }

        $owner = (string) ($this->settings->get('update_github_owner') ?: '');
        $repo = (string) ($this->settings->get('update_github_repo') ?: '');

        return new GitHubReleaseClient($owner, $repo);
    }

    /**
     * "major" if the major component differs, else "minor" if the minor
     * component differs, else "patch" (covers an equal-or-lower-precision
     * bump — this is only ever called after confirming $to > $from).
     */
    private function classifyVersionBump(string $from, string $to): string
    {
        $fromParts = array_pad(array_map('intval', explode('.', $from)), 3, 0);
        $toParts = array_pad(array_map('intval', explode('.', $to)), 3, 0);

        if ($toParts[0] !== $fromParts[0]) {
            return 'major';
        }
        if ($toParts[1] !== $fromParts[1]) {
            return 'minor';
        }

        return 'patch';
    }

    /**
     * Next occurrence of $day (e.g. "monday") at $time ("HH:MM") from $now.
     * Pushed a full week out if the naturally-next occurrence is less than
     * 5 minutes away — module spec: never install on a coincidental
     * near-immediate slot right as the webhook arrives.
     */
    private function nextOccurrence(string $day, string $time, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $targetIso = self::DAY_ISO[$day] ?? 1;
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        $candidate = $now->setTime($hour, $minute, 0);
        $currentIso = (int) $candidate->format('N');
        $daysUntil = ($targetIso - $currentIso + 7) % 7;
        $candidate = $candidate->modify("+{$daysUntil} days");

        if ($candidate <= $now->modify('+5 minutes')) {
            $candidate = $candidate->modify('+7 days');
        }

        return $candidate;
    }
}
