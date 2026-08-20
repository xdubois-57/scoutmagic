<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member;

use Core\Security\AuthSession;
use Core\Security\Role;

/**
 * The one session-aware TemporaryMemberProviderInterface implementation,
 * wired in public/index.php's composition root.
 *
 * Two guards, both re-evaluated on every call rather than once at activation
 * time — the same posture as Core\View\ConfigurationMode::isActive():
 *
 * - **Role.** Only an admin (chef d'unité) or higher keeps the override. A
 *   session that has since lost that role (a Desk import demotion, say)
 *   stops resolving the temporary member immediately, without needing a
 *   logout or an explicit purge.
 * - **Ownership.** The override only ever applies to the email of the
 *   account that added it. Asking about anyone else's email returns null,
 *   so a temporary member can never leak into a third party's computed list
 *   through one of the callers that pass a non-session email.
 */
class SessionTemporaryMemberProvider implements TemporaryMemberProviderInterface
{
    public function resolveMemberYearId(string $email): ?int
    {
        $memberYearId = TemporaryMemberSession::get();
        if ($memberYearId === null) {
            return null;
        }

        if (!Role::fromString(AuthSession::getRole())->hasAccess(Role::ADMIN)) {
            return null;
        }

        $owner = AuthSession::getEmail();
        if ($owner === null || strtolower(trim($owner)) !== strtolower(trim($email))) {
            return null;
        }

        return $memberYearId;
    }
}
