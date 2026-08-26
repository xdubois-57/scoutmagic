<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to say which registration request
 * a member came from, so the admin member page can link back to it
 * without core depending on the module. Same precedent as
 * Core\Module\FormationPathProvider (ARCHITECTURE.md §7.4).
 *
 * **Why a new interface and not one of the module's existing ones.** The
 * registration module already publishes five `Api\` interfaces
 * (§7.5) and none of them answers this question: they count households,
 * feed the scout-year workflow, veto a transition, expose a mailing
 * list, trigger a reconciliation. Adding "and also, where did this member
 * come from" to any of them would be the generic
 * tell-me-everything-about-this-member coupling §7.4 exists to prevent.
 * One question, one method, `int $memberId` in.
 *
 * **This hook decides nothing about who may look.** The admin member
 * page is `role_min: admin` and so is the request page it links to, so
 * a reader who can see the link can always open it. A provider that
 * re-derived its own audience would be a second answer waiting to
 * disagree with the router's.
 */
interface MemberRegistrationOriginProvider
{
    /**
     * The request this member was created from, or null when there is
     * none — which is the ORDINARY case, not an anomaly: every member
     * imported from Desk before the module existed, and every member
     * who joined any other way, has no request. The page shows nothing
     * at all in that case rather than an empty block.
     *
     * The id is a `members.id`, the persistent identity, like everywhere
     * else: a request produced a person, not a scout year's row.
     */
    public function getRegistrationOrigin(int $memberId): ?MemberRegistrationOriginView;
}
