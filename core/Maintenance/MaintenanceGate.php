<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Scheduler\SchedulerContinuationRoute;
use Core\Security\AuthSession;
use Core\Security\Role;

/**
 * Decides whether the current visitor should see the "update in progress"
 * page instead of the normal site (Core\Http\FrontController::handle(),
 * mirroring Core\Security\RbacGuard's "enforce, return null when allowed"
 * shape). An update actually touches the live install (Task\
 * InstallUpdateHandler copies files over $basePath mid-'installing', and
 * migrates the schema mid-'migrating') — visitors hitting arbitrary routes
 * during that window could see a half-updated site, or worse, a half-
 * migrated one. An already-logged-in superadmin is let through
 * unconditionally (they're the one who'd need to check on/recover from a
 * stuck update); BYPASS_QUERY_PARAM exists for the case even that isn't
 * enough — e.g. the admin's session itself doesn't survive whatever broke
 * the update, or they need to log in fresh and the login page would
 * otherwise be gated too. This is purely an availability gate, not a
 * security boundary: RbacGuard below still applies in full to whatever the
 * bypass lets through, so a visitor who stumbles onto the parameter learns
 * nothing they couldn't otherwise reach once the update finishes.
 *
 * **One path is never gated: the scheduler's own continuation endpoint.**
 * That exemption is not a convenience, it is what lets an update finish at
 * all. Task\InstallUpdateHandler deliberately does not migrate in the
 * process that replaced the files: it sets the status to `migrating`,
 * queues the resume task, and calls Scheduler\SchedulerKick::now() so
 * another process picks it up immediately. That hop lands on
 * `POST /api/scheduler/continue` — and this gate, which runs before
 * routing and covered every path without exception, answered it with the
 * "update in progress" page, because the row it gates on IS the update the
 * hop exists to advance. The hop then fell through to the poor man's cron
 * at the end of public/index.php, which is throttled to once a minute and
 * had just been stamped by the pass that ran the install. So nothing ran,
 * and on an installation with no crontab the resume task waited for
 * whatever unrelated request happened to arrive next.
 *
 * Production, scoutmagic.be, 2026-08-31: `dev-afd995a → dev-a29f7ae`
 * queued its resume task at 15:03:00; it ran at 15:27:35 — and only
 * because UpdateHistoryRepository::findInProgress() had by then declared
 * the update abandoned all by itself, at exactly `started_at` plus fifteen
 * minutes, which is what ungated the site. The task then found a `failed`
 * row and gave up. Six updates died that way in forty-eight hours, each of
 * them serving this page to every visitor for the whole fifteen minutes,
 * and none of them was a slow migration: two of the last three changed no
 * schema at all. The wait was the gate, not the work.
 *
 * Exempting the route costs nothing. It is public, session-free and
 * authenticated by a shared secret checked inside
 * Http\Controller\SchedulerContinuationController, so a caller without the
 * secret gets a 403 rather than anything this gate was hiding — and the
 * gate is an availability gate, not a security boundary, as above.
 */
final class MaintenanceGate
{
    public const BYPASS_QUERY_PARAM = 'scoutmagic_maintenance_bypass';

    public function __construct(private UpdateHistoryRepository $updateHistoryRepository)
    {
    }

    /**
     * @param string $path the requested path, so the one route that must
     *        never be gated can be recognised — see the class docblock.
     *        Defaults to the empty string for call sites that have no path
     *        to offer (tests, any future non-web entry point); an empty
     *        path is gated like any other.
     *
     * @return UpdateHistory|null the in-progress update to show a
     *         maintenance page for, or null if the visitor should proceed
     *         to the normal site
     */
    public function checkBlocking(bool $bypassRequested, string $path = ''): ?UpdateHistory
    {
        // Before findInProgress(), deliberately: that call also marks a
        // stale row failed as a side effect, and a hop coming to finish an
        // update has no business tripping the watchdog on it.
        if ($path === SchedulerContinuationRoute::PATH) {
            return null;
        }

        $inProgress = $this->updateHistoryRepository->findInProgress();
        if ($inProgress === null) {
            return null;
        }

        if (AuthSession::isAuthenticated() && AuthSession::getRole() === Role::SUPERADMIN->value) {
            return null;
        }

        if ($bypassRequested) {
            return null;
        }

        return $inProgress;
    }
}
