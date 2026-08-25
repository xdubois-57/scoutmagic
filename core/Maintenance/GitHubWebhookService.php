<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Config\AppClock;
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

    // Public: MaintenanceController::saveAutoUpdatePreferences() must be
    // able to find/reconcile the exact pending install this service
    // scheduled when the admin changes the preferences that produced it.
    public const SCHEDULED_INSTALL_REFERENCE = 'scheduled_install';
    private const PUSH_INSTALL_REFERENCE = 'push_install';

    // Wall-clock timezone the admin's auto_update_day/auto_update_time are
    // expressed in: the application clock, named explicitly rather than
    // taken from date_default_timezone_get(), so a CLI pass launched with a
    // different ambient zone cannot silently move the slot by hours. An
    // admin typing "03:00" means 3 o'clock at night locally, not 04:00.
    //
    // nextOccurrence() below converts into this zone and back out into
    // $now's — now that the two are normally the same zone that round trip
    // is an identity rather than a shift, which is exactly what it must be:
    // it corrects a mismatch when there is one and does nothing when there
    // isn't. It must never become a second, unconditional correction.
    public const SCHEDULE_TIMEZONE = AppClock::TIMEZONE;

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

        // The event must be for the repository this site is configured to
        // update from. Without this a signed event for any attacker-owned
        // repo would install that repo's code.
        if (!$this->isConfiguredRepository($payload)) {
            return ['status' => 'ignored', 'reason' => 'repository_mismatch'];
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

        $installedVersion = VersionFile::read($this->basePath);
        $level = (string) ($this->settings->get('auto_update_level') ?: 'minor');
        if (!VersionFile::isNewerThan($latestVersion, $installedVersion, $level === 'dev')) {
            // A release we won't install needs no download URL — the version
            // cache above is still refreshed so the Maintenance page shows the
            // true latest version, but nothing about the artifact is trusted.
            $this->settings->setInternal('update_dependencies_changed', '0');
            return ['status' => 'ignored', 'reason' => 'not_newer'];
        }

        // Newer — the download URL is about to be trusted (cached for the
        // manual "Installer maintenant" button and, if allowed, scheduled for
        // an automatic install), so it must be validated first. It used to be
        // cached before any gate straight from the webhook JSON, so a signed
        // event could point the updater at an arbitrary host; it is now
        // refused outright unless it is a GitHub https URL.
        if ($downloadUrl === null) {
            return ['status' => 'ignored', 'reason' => 'no_download_url'];
        }
        if (!GitHubUrlValidator::isAllowed($downloadUrl)) {
            return ['status' => 'ignored', 'reason' => 'download_url_refused'];
        }
        $this->settings->setInternal('update_download_url', $downloadUrl);

        $dependenciesChanged = $this->composerLockChanged($installedVersion, $tagName);
        $this->settings->setInternal('update_dependencies_changed', $dependenciesChanged ? '1' : '0');

        if (!(bool) ((int) ($this->settings->get('auto_update_enabled') ?: '0'))) {
            return ['status' => 'ignored', 'reason' => 'auto_update_disabled'];
        }

        if ($level === 'dev') {
            // Development mode takes over the install path entirely — a
            // stable release arriving while it's active is deliberately
            // never auto-installed (module spec).
            return ['status' => 'ignored', 'reason' => 'dev_mode_active'];
        }

        if (!self::isBumpAllowed($installedVersion, $latestVersion, $level)) {
            return ['status' => 'ignored', 'reason' => 'version_type_not_allowed'];
        }

        $day = (string) ($this->settings->get('auto_update_day') ?: 'monday');
        $time = (string) ($this->settings->get('auto_update_time') ?: '03:00');
        $runAt = self::nextOccurrence($day, $time, new \DateTimeImmutable());

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
     * Records what became of the push, then answers exactly as before.
     *
     * The decision itself is untouched in processPushEvent() below; this
     * wrapper exists only so the site remembers the outcome. Without it
     * the reason is written into the HTTP response and immediately
     * forgotten — and since an ignored push still answers 200 (an ignore
     * is an ordinary result: a push to another branch is one), GitHub's
     * delivery log shows success either way. That left Configuration >
     * Maintenance able to say the channel had gone quiet but never why,
     * which is the half of the diagnosis an administrator actually needs.
     *
     * @param array<string, mixed> $payload
     * @return array{status: string, reason?: string}
     */
    public function handlePushEvent(array $payload): array
    {
        /** @var array{status: string, reason?: string} $result */
        $result = $this->processPushEvent($payload);
        $this->recordPushOutcome($result);

        return $result;
    }

    /**
     * @param array{status: string, reason?: string} $result
     */
    private function recordPushOutcome(array $result): void
    {
        $reason = (string) ($result['reason'] ?? '');

        // `branch_mismatch` is the one outcome that is not a symptom of
        // anything: GitHub sends a push event for every ref, so every
        // feature branch and every tag lands here and is rightly dropped.
        // Recording it would overwrite the last meaningful outcome within
        // minutes and leave the page reporting noise as the diagnosis.
        if ($reason === 'branch_mismatch') {
            return;
        }

        try {
            $this->settings->setInternal(
                'auto_update_last_push_at',
                (new \DateTimeImmutable())->format('Y-m-d H:i:s')
            );
            $this->settings->setInternal(
                'auto_update_last_push_result',
                $reason !== '' ? $reason : (string) $result['status']
            );
        } catch (\Throwable) {
            // Bookkeeping must never turn a working install into a failed
            // webhook: an unregistered setting (an install predating this
            // feature, before its migration has run) would otherwise throw
            // out of a request that had already done its real work.
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{status: string, reason?: string}
     */
    private function processPushEvent(array $payload): array
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

        // The zipball URL below is built from $repoFullName; it must be the
        // repository this site is configured to update from, or a signed
        // push event could install any attacker-owned repo's code.
        if (!$this->isConfiguredRepository($payload)) {
            return ['status' => 'ignored', 'reason' => 'repository_mismatch'];
        }

        // Never start a second automatic install while one is already
        // running — two pushes close together used to both schedule an
        // immediate install (cancelPending() below only dedupes a still-
        // *queued* one, never one already claimed and executing), and
        // running two installs at once has, in practice, corrupted an
        // in-progress one. Only a manual "Installer maintenant"
        // (MaintenanceController::installUpdate()/installDevBranchUpdate())
        // is allowed to force a new attempt regardless — that path never
        // calls this method. The superseded push's commit isn't lost: the
        // next push (or a manual install) picks up whatever is then
        // current, and Task\InstallUpdateHandler::markOtherInProgressAsFailed()
        // cleans up this row the moment that next attempt actually starts.
        if ($this->updateHistoryRepository->findInProgress() !== null) {
            $this->journalService->log(
                'core',
                'auto_update_skipped',
                'info',
                'Push ignoré : une mise à jour est déjà en cours d\'installation',
                ['branch' => $pushedBranch, 'sha' => $sha]
            );
            return ['status' => 'ignored', 'reason' => 'update_in_progress'];
        }

        $shortSha = substr($sha, 0, 7);
        $versionTo = 'dev-' . $shortSha;
        $downloadUrl = "https://api.github.com/repos/{$repoFullName}/zipball/{$sha}";
        $installedVersion = VersionFile::read($this->basePath);

        $historyId = $this->updateHistoryRepository->create($installedVersion, $versionTo, false, null);

        // A push arriving while a previous push's install is still merely
        // *queued* (not yet claimed/running — cancelPending() only ever
        // touches a still-'pending' row, never one already 'processing')
        // replaces it, exactly like processRelease()'s own dedup above:
        // only the newest commit should ever be waiting to install. Without
        // this, two pushes seconds apart queue two separate install_update
        // tasks that could both become due at once — Repository\
        // SchedulerRepository::claimOverdue() now claims each row
        // atomically so two concurrent requests can no longer BOTH run the
        // same row's InstallUpdateHandler, but two *different* rows can
        // still legitimately both be due and would then both copy an
        // extracted archive over the live install directory around the
        // same time. This dedup avoids ever creating that situation in the
        // first place.
        $this->schedulerService->cancelPending('core', 'install_update', self::PUSH_INSTALL_REFERENCE);
        $this->schedulerService->scheduleAfter(
            'core',
            'install_update',
            0,
            ['history_id' => $historyId, 'download_url' => $downloadUrl, 'source_type' => 'branch'],
            self::PUSH_INSTALL_REFERENCE
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
     * Whether the webhook payload's repository is the one this site is
     * configured to update from (case-insensitive "owner/repo"). Both the
     * release and push paths gate on this so a validly-signed event for a
     * different repository can never trigger an install.
     *
     * @param array<string, mixed> $payload
     */
    private function isConfiguredRepository(array $payload): bool
    {
        $owner = strtolower(trim((string) ($this->settings->get('update_github_owner') ?: '')));
        $repo = strtolower(trim((string) ($this->settings->get('update_github_repo') ?: '')));
        if ($owner === '' || $repo === '') {
            return false;
        }

        $repository = $payload['repository'] ?? null;
        $fullName = is_array($repository) ? strtolower(trim((string) ($repository['full_name'] ?? ''))) : '';

        return $fullName === $owner . '/' . $repo;
    }

    /**
     * Prefer a real .zip release asset over the first asset blindly (a
     * release can carry non-archive assets too), then fall back to the
     * source zipball — same selection GitHubReleaseClient::selectZipAssetUrl()
     * uses. The URL is validated against the GitHub allowlist by the caller
     * before it is ever cached or downloaded.
     *
     * @param array<string, mixed> $release
     */
    private function resolveReleaseDownloadUrl(array $release): ?string
    {
        $assets = $release['assets'] ?? [];
        if (is_array($assets)) {
            $zipAsset = GitHubReleaseClient::selectZipAssetUrl($assets);
            if ($zipAsset !== null) {
                return $zipAsset;
            }
        }
        if (!empty($release['zipball_url'])) {
            return (string) $release['zipball_url'];
        }

        return null;
    }

    private function composerLockChanged(string $installedVersion, string $latestTag): bool
    {
        // A dev/branch build's git ref is the commit sha inside its
        // "dev-{sha}" VERSION string — "vdev-{sha}" is never a real tag, so
        // comparing from it would always fail and wrongly report changed
        // dependencies on every dev-build → release transition.
        $base = VersionFile::isDevBuild($installedVersion)
            ? substr($installedVersion, strlen('dev-'))
            : 'v' . $installedVersion;

        try {
            return $this->releaseClient()->composerLockChanged($base, $latestTag);
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
     * Whether auto-installing $candidate over $installed is within the
     * admin's configured $level ('patch'/'minor'/'major'). Public static
     * (pure) so MaintenanceController::saveAutoUpdatePreferences() can
     * re-check an already-scheduled install against just-changed
     * preferences with exactly the rule this service applied when
     * scheduling it.
     *
     * An installed dev build ("dev-{sha}") bypasses the bump-type gate
     * entirely: its version components would parse as 0.0.0, classifying
     * EVERY release as a major bump and silently blocking sites configured
     * at patch/minor from ever leaving the dev build — yet the only way to
     * be on a dev build with a non-dev level is an admin who deliberately
     * switched the channel back to stable, i.e. explicitly asked for the
     * next release to be installed over it.
     */
    public static function isBumpAllowed(string $installed, string $candidate, string $level): bool
    {
        if (VersionFile::isDevBuild($installed)) {
            return true;
        }

        $allowed = self::LEVEL_ALLOWS[$level] ?? self::LEVEL_ALLOWS['patch'];

        return in_array(self::classifyVersionBump($installed, $candidate), $allowed, true);
    }

    /**
     * "major" if the major component differs, else "minor" if the minor
     * component differs, else "patch" (covers an equal-or-lower-precision
     * bump — this is only ever called after confirming $to > $from).
     */
    private static function classifyVersionBump(string $from, string $to): string
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
     * $day/$time are interpreted as wall-clock LOCAL time
     * (SCHEDULE_TIMEZONE) — never the server's own PHP timezone, which is
     * frequently UTC on shared hosting and used to silently shift the
     * admin's configured slot by one or two hours. The result is converted
     * back to $now's timezone before returning, since
     * scheduled_actions.run_at is stored naive and compared against PHP's
     * default timezone (Core\Scheduler\SchedulerRepository::claimOverdue()).
     * Pushed a full week out if the naturally-next occurrence is less than
     * 5 minutes away — module spec: never install on a coincidental
     * near-immediate slot right as the webhook arrives.
     *
     * Public static (pure) so MaintenanceController::
     * saveAutoUpdatePreferences() can move an already-pending install to a
     * just-saved slot with exactly this computation.
     */
    public static function nextOccurrence(string $day, string $time, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $targetIso = self::DAY_ISO[$day] ?? 1;
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        $localNow = $now->setTimezone(new \DateTimeZone(self::SCHEDULE_TIMEZONE));
        $candidate = $localNow->setTime($hour, $minute, 0);
        $currentIso = (int) $candidate->format('N');
        $daysUntil = ($targetIso - $currentIso + 7) % 7;
        $candidate = $candidate->modify("+{$daysUntil} days");

        // DateTimeImmutable comparisons are instant-based, so comparing the
        // Brussels-zoned candidate against a differently-zoned $now is safe.
        if ($candidate <= $now->modify('+5 minutes')) {
            $candidate = $candidate->modify('+7 days');
        }

        return $candidate->setTimezone($now->getTimezone());
    }
}
