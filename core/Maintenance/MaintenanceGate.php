<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

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
 */
final class MaintenanceGate
{
    public const BYPASS_QUERY_PARAM = 'scoutmagic_maintenance_bypass';

    public function __construct(private UpdateHistoryRepository $updateHistoryRepository)
    {
    }

    /**
     * @return UpdateHistory|null the in-progress update to show a
     *         maintenance page for, or null if the visitor should proceed
     *         to the normal site
     */
    public function checkBlocking(bool $bypassRequested): ?UpdateHistory
    {
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
