<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
     * A specific published release by its tag name (GET /repos/{owner}/
     * {repo}/releases/tags/{tag}) — used to fetch the release notes of the
     * currently INSTALLED version (Core\Http\Controller\
     * MaintenanceController::index()), as opposed to getLatestRelease()'s
     * "what's newest". Null when no release has that exact tag.
     *
     * @throws UpdateException on a network/API error
     */
    public function getReleaseByTag(string $tag): ?ReleaseInfo;

    /**
     * Every published release, newest first (GET /repos/{owner}/{repo}/
     * releases, drafts and prereleases filtered out). Needed by
     * Core\Http\Controller\MaintenanceController::checkForUpdatesNow(),
     * which can't answer "is there a major release between the installed
     * version and the latest one?" from getLatestRelease() alone — see
     * UpdateTargetSelector. Empty when the repo has no published release.
     *
     * @return array<int, ReleaseInfo>
     * @throws UpdateException on a network/API error
     */
    public function listReleases(): array;

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

    /**
     * A specific commit by its sha (GET /repos/{owner}/{repo}/commits/
     * {sha}) — the development-channel equivalent of getReleaseByTag():
     * used to fetch the commit message of the currently INSTALLED dev
     * build. Null when no commit has that sha.
     *
     * @throws UpdateException on a network/API error
     */
    public function getCommit(string $sha): ?CommitInfo;
}
