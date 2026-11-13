<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\Role;
use Core\Security\UserAccountRepository;

/**
 * Turns the raw session facts a Controller reads (email, role, account id)
 * into the GroupSessionContext every service in this module decides on —
 * in the authorization year, a chief's year preview never entering it
 * (issue #1124).
 *
 * Two things it deliberately does on every call: re-resolves the effective
 * scout year (never a cached or session-stored membership picture), and
 * reduces the account's names to a single boolean, so the account's actual
 * first and last name — encrypted personal data, read here through
 * Core\Security\UserAccountRepository like every other encrypted field —
 * never travel into the access layer.
 */
class GroupSessionContextFactory
{
    public function __construct(
        private MemberService $memberService,
        private UserAccountRepository $userAccountRepository,
        private ScoutYearResolver $scoutYearResolver
    ) {
    }

    public function build(
        ?string $email,
        string $role,
        ?int $userAccountId
    ): GroupSessionContext
    {
        $roleEnum = Role::fromString($role);
        // The authorization year, never a chief's year preview (issue
        // #1124). Everything this context carries decides an access — the
        // linked members a group's membership is checked against, the year
        // a past group is read-only relative to — and a preview is chosen
        // by the person it would authorise, for any year that ever existed:
        // a chief previewing a past year would post in that year's groups
        // again, as that year's parent of a child.
        $effectiveYear = $this->scoutYearResolver->getAuthorizationYear();

        $linkedMemberIds = [];
        if ($email !== null && $email !== '') {
            $linkedMemberIds = array_values(array_unique(array_map(
                fn(MemberProfile $m) => $m->memberId,
                $this->memberService->getLinkedMembers($email, $effectiveYear->id)
            )));
        }

        $hasCompleteProfile = false;
        if ($userAccountId !== null) {
            $account = $this->userAccountRepository->findById($userAccountId);
            $hasCompleteProfile = $account !== null
                && $account->firstName !== null && trim($account->firstName) !== ''
                && $account->lastName !== null && trim($account->lastName) !== '';
        }

        return new GroupSessionContext(
            $userAccountId,
            $roleEnum,
            $linkedMemberIds,
            $effectiveYear->id,
            $hasCompleteProfile,
            $effectiveYear->label
        );
    }
}
