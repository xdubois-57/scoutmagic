<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

/**
 * Which release the Maintenance page's "Vérifier maintenant" button
 * proposes, given everything published on the stable channel.
 *
 * The manual check deliberately ignores `auto_update_level` entirely (an
 * admin clicking the button asks "what can I install right now?", not
 * "what would the weekly automatic slot have picked?" — the level gates
 * Core\Maintenance\GitHubWebhookService's *unattended* installs, nothing
 * else), so the answer is normally simply the newest release available.
 *
 * The one exception is a major bump: when at least one release with a
 * higher MAJOR component than the installed version exists, the proposal
 * stops at the FIRST of them rather than jumping straight to the newest.
 * Two majors' worth of migrations applied in a single install is exactly
 * the situation Task\InstallUpdateHandler's rollback is least able to
 * recover from, so a site three majors behind walks there one major at a
 * time, each step separately confirmed by the admin and separately backed
 * up.
 *
 * Development mode never reaches this class — MaintenanceController::
 * checkForUpdatesNow() proposes the branch's latest commit instead, and
 * an installed "dev-{sha}" build has no meaningful major component to
 * step from anyway (see selectTarget()).
 */
final class UpdateTargetSelector
{
    /**
     * The release to propose over $installedVersion, or null when none of
     * $releases is newer.
     *
     * @param array<int, ReleaseInfo> $releases every published release, in any order
     */
    public static function selectTarget(string $installedVersion, array $releases): ?ReleaseInfo
    {
        $newer = array_values(array_filter(
            $releases,
            static fn(ReleaseInfo $release): bool => VersionFile::isNewerThan($release->version(), $installedVersion)
        ));
        if ($newer === []) {
            return null;
        }

        usort($newer, static fn(ReleaseInfo $a, ReleaseInfo $b): int => version_compare($a->version(), $b->version()));
        $latest = $newer[count($newer) - 1];

        // An installed dev build's components parse as 0.0.0, and so does
        // an install whose VERSION file is missing entirely — either would
        // classify every release as "a major ahead" and pin the proposal
        // to the oldest release the repository ever published. Same
        // reasoning as GitHubWebhookService::isBumpAllowed()'s dev-build
        // bypass: there is no major to step from, so propose the newest.
        if (VersionFile::isDevBuild($installedVersion) || $installedVersion === VersionFile::UNKNOWN) {
            return $latest;
        }

        $installedMajor = self::major($installedVersion);
        foreach ($newer as $release) {
            if (self::major($release->version()) > $installedMajor) {
                return $release;
            }
        }

        return $latest;
    }

    /**
     * The newest published version among $releases (bare, no leading "v"),
     * or an empty string when there is none — what selectTarget() would
     * have proposed had the major-by-major stepping not held it back, so
     * the Maintenance page can say so.
     *
     * @param array<int, ReleaseInfo> $releases
     */
    public static function latestVersion(array $releases): string
    {
        $latest = '';
        foreach ($releases as $release) {
            if ($latest === '' || version_compare($release->version(), $latest, '>')) {
                $latest = $release->version();
            }
        }

        return $latest;
    }

    private static function major(string $version): int
    {
        return (int) explode('.', $version)[0];
    }
}
