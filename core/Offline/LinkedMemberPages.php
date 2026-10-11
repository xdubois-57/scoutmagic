<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Offline;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Core\Member\TemporaryMemberProviderInterface;
use Core\ScoutYear\ScoutYearResolver;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\Role;

/**
 * The fiches of the members linked to this account — and no other (#883).
 * The fiche of any other member is never cached, even after a visit.
 */
final class LinkedMemberPages implements OfflinePageProvider
{
    public function __construct(
        private MemberService $memberService,
        private ScoutYearResolver $scoutYearService,
        private ?TemporaryMemberProviderInterface $temporaryMemberProvider = null
    ) {
    }

    public function pagesFor(Role $role, ?string $email): array
    {
        if ($email === null) {
            return [];
        }

        // The year this viewer is actually browsing, not the public
        // date-computed one: a chief in preview gets the fiches of the
        // year they are looking at.
        $scoutYearId = $this->scoutYearService->getEffectiveYear(ScoutYearSession::getPreviewId(), $role)->id;
        $members = $this->excludeTemporaryMember($this->memberService->getLinkedMembers($email, $scoutYearId), $email);

        return array_values(array_map(static fn (MemberProfile $m): string => '/members/' . $m->memberId, $members));
    }

    /**
     * Drop the session's temporary member (ARCHITECTURE.md §8.42),
     * deliberately.
     *
     * Everything else that feature touches is a live, server-side decision
     * that disappears the moment the override does. The offline cache is
     * the one consumer whose output is written to the visitor's own device
     * and outlives the session: caching another member's page into an
     * admin's browser, where it would stay readable after the override was
     * removed, is a data-retention problem the rest of the feature does not
     * have.
     *
     * @param MemberProfile[] $linkedMembers
     * @return MemberProfile[]
     */
    private function excludeTemporaryMember(array $linkedMembers, string $email): array
    {
        $temporaryMemberYearId = $this->temporaryMemberProvider?->resolveMemberYearId($email);
        if ($temporaryMemberYearId === null) {
            return $linkedMembers;
        }

        return array_values(array_filter(
            $linkedMembers,
            static fn (MemberProfile $member): bool => $member->memberYearId !== $temporaryMemberYearId
        ));
    }
}
