<?php

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * The latest commit on a branch, as returned by
 * GET /repos/{owner}/{repo}/commits/{branch}. Used by the "Vérifier
 * maintenant" manual check when development mode (auto_update_level ===
 * 'dev') is configured — the stable channel uses ReleaseInfo instead.
 */
final class CommitInfo
{
    public function __construct(
        public readonly string $sha,
        public readonly string $message,
        public readonly string $htmlUrl
    ) {
    }

    /**
     * Same short-sha + "dev-" prefix convention GitHubWebhookService::
     * handlePushEvent() uses for update_history.version_to.
     */
    public function shortVersion(): string
    {
        return 'dev-' . substr($this->sha, 0, 7);
    }
}
