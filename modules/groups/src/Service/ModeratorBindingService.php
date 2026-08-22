<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\GroupMemberRepository;

/**
 * Binds moderator rows granted before the flag named a login.
 *
 * The flag used to sit on the membership, so it belonged to a member and
 * therefore to every address that reaches them. It now names one
 * user_accounts row (schema.sql, Service\GroupAccessService::
 * canModerate()), and a row that names none moderates nothing — which
 * would silently strip the moderators of every group that already
 * existed.
 *
 * So a row with no account is bound to one here, and only when there is
 * exactly ONE candidate: a member reachable by a single address has no
 * ambiguity to resolve, while a member reachable by two is precisely the
 * case a human has to decide (which is the whole reason the flag stopped
 * being a property of the member). Nothing is ever guessed between two
 * candidates, and a member with no account at all is left alone — they
 * could not have been moderating anything anyway.
 *
 * Idempotent and bounded, so it runs where this module already self-heals
 * (Task\EnsureSectionGroupsHandler, and the group list's own page load —
 * the same pattern as Service\SectionGroupSyncService): a pass that finds
 * nothing to bind is two queries and no writes.
 */
class ModeratorBindingService
{
    /**
     * One bounded batch per pass — a big install never turns a page load
     * into an unbounded write loop, and the next pass picks up the rest.
     */
    private const BATCH = 200;

    public function __construct(
        private GroupMemberRepository $memberRepository,
        private GroupRecipientResolver $recipientResolver
    ) {
    }

    /**
     * @return int how many rows were bound
     */
    public function run(): int
    {
        $bound = 0;

        foreach ($this->memberRepository->findUnboundModerators(self::BATCH) as $row) {
            $accountIds = $this->recipientResolver->accountIdsForMember($row->memberId);
            if (count($accountIds) !== 1) {
                continue;
            }

            $this->memberRepository->bindModeratorAccount($row->groupId, $row->memberId, $accountIds[0]);
            $bound++;
        }

        return $bound;
    }
}
