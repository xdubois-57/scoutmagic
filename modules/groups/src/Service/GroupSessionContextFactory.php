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
 * Turns the raw session facts a Controller reads (email, role, account id,
 * the chief's optional year preview) into the GroupSessionContext every
 * service in this module decides on.
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
        ?int $userAccountId,
        ?int $yearPreviewId = null
    ): GroupSessionContext
    {
        $roleEnum = Role::fromString($role);
        $effectiveYear = $this->scoutYearResolver->getEffectiveYear($yearPreviewId, $roleEnum);

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
