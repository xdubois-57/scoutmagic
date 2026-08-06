<?php

declare(strict_types=1);

namespace Core\Maintenance;

interface GitHubReleaseClientInterface
{
    /**
     * The latest published release (GitHub's "latest" endpoint already
     * excludes drafts and prereleases on its own). Null when the repo has
     * no published release yet.
     *
     * @throws UpdateException on a network/API error
     */
    public function getLatestRelease(): ?ReleaseInfo;

    /**
     * Whether composer.lock is among the files changed between two tags
     * (GET /repos/{owner}/{repo}/compare/{base}...{head}).
     *
     * @throws UpdateException on a network/API error
     */
    public function composerLockChanged(string $base, string $head): bool;

    /**
     * The latest commit on a branch (GET /repos/{owner}/{repo}/commits/
     * {branch}) — the development-channel equivalent of getLatestRelease().
     * Null when the branch doesn't exist.
     *
     * @throws UpdateException on a network/API error
     */
    public function getLatestCommit(string $branch): ?CommitInfo;
}
