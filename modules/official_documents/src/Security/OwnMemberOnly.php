<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\OfficialDocuments\Security;

use Core\Member\MemberNotFoundException;
use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Security\AuthSession;

/**
 * The real protection on every route of this module, in one place.
 *
 * `role_min: identified` says only that somebody is signed in. What decides
 * is whether the account asking is linked to THIS member, and the chantier
 * makes that strict: no chief and no administrator bypass, whatever their
 * role. The one way staff reach these screens is the temporary member
 * override (ARCHITECTURE.md §8.42), which `MemberService::canAccess()`
 * honours deliberately and which shows on screen while it lasts.
 *
 * **The literal `'identified'` is the whole mechanism.** Handing
 * `canAccess()` the caller's real role instead would let its
 * chief-or-admin branch answer true for somebody else's child. It is a
 * one-word difference with no visible symptom, on five routes carrying a
 * family's health data — which is exactly why it lives here once rather
 * than being retyped in each controller.
 *
 * Same shape as
 * `Core\Http\Controller\MemberEmailAddressController::requireOwnMemberId()`,
 * the pattern this module was asked to copy.
 */
final class OwnMemberOnly
{
    /**
     * The French sentence every refusal on these screens shows. Names
     * nothing: not the member, not who asked, not why.
     */
    public const REFUSAL = 'Ces documents ne peuvent être consultés que par une personne liée à ce membre.';

    public function __construct(private readonly MemberService $memberService)
    {
    }

    /**
     * This member's profile when the signed-in account is entitled to it,
     * null when access must be refused.
     *
     * A member that does not exist answers null too: a family asking for
     * somebody else's child and a family asking for a deleted one get the
     * same refusal, so the screen never confirms who exists.
     */
    public function profileFor(int $memberYearId): ?MemberProfile
    {
        if (!$this->allows($memberYearId)) {
            return null;
        }

        try {
            return $this->memberService->getMemberProfile($memberYearId);
        } catch (MemberNotFoundException) {
            return null;
        }
    }

    /**
     * Whether the signed-in account is linked to this member — the question
     * on its own, for an action that needs no profile (« Tout effacer »).
     */
    public function allows(int $memberYearId): bool
    {
        return $this->memberService->canAccess(
            AuthSession::getEmail() ?? '',
            $memberYearId,
            // NOT AuthSession::getRole(). See the class docblock: this
            // literal is what makes the rule self-only.
            'identified'
        );
    }
}
