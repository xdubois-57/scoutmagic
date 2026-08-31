<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Member\MemberProfile;
use Core\Member\MemberService;
use Modules\Registration\Repository\ReenrollmentAnswer;

/**
 * What the « Réinscription » page shows a parent: one card per child of
 * theirs, and for each the question that child's own situation raises.
 *
 * **The account decides which children, and nothing else does.** There is
 * no member id in the URL: the cards come from
 * `MemberService::getLinkedMembers()` for the signed-in address, and the
 * same list is what a save is checked against. A forged request naming
 * somebody else's child fails on the list, not on a hidden field.
 *
 * **Animés only.** A parent who is also an animateur sees cards for their
 * children and none for themselves. "Animé" is not redefined here: it is
 * `PassageService::getAnimeMemberYears()`'s own set, so this page and the
 * Passage page can never disagree about who is one — with one difference
 * the two pages must have: `includeLeaving: true`. A family's own
 * « il ne revient pas » ticks the departure box (roadmap IT-16), and
 * without that flag the card would disappear the instant they answered,
 * leaving them unable to see or change what they had just said.
 *
 * **Three situations, three forms**, and the difference is only ever
 * whether there is a real choice to offer:
 *
 * - the child stays in their section → a sentence, then a free comment;
 * - the child changes branch but the arrival branch has ONE visible,
 *   active section → the same, worded for the move: naming the section
 *   would be asking a question with one answer;
 * - the child changes branch and the arrival branch has several → the
 *   preferred section (« Peu importe » included), the friend wishes, then
 *   the comment.
 *
 * The section count comes from `SectionService::getAllWithBranches()`
 * WITHOUT hidden sections: a section a family cannot see is not a choice
 * they can make.
 */
class ReenrollmentFormService
{
    public function __construct(
        private MemberService $memberService,
        private PassageService $passageService,
        private ReenrollmentService $reenrollmentService
    ) {
    }

    /**
     * One card per animé linked to `$email`, in the order the account's
     * own member list gives them.
     *
     * @return array<int, array{
     *   member_id: int,
     *   display_name: string,
     *   current_section_label: ?string,
     *   changes_branch: bool,
     *   arrival_sections: array<int, array{id: int, label: string}>,
     *   asks_section: bool,
     *   asks_friends: bool,
     *   friend_wish_limit: int,
     *   answer: ?ReenrollmentAnswer
     * }>
     */
    public function cardsFor(
        string $email,
        int $publicYearId,
        string $publicYearLabel,
        int $targetYearId
    ): array {
        $animeMemberIds = [];
        foreach ($this->passageService->getAnimeMemberYears($publicYearId, includeLeaving: true) as $row) {
            $animeMemberIds[(int) $row['member_id']] = true;
        }

        $limit = $this->reenrollmentService->friendWishLimit();

        $cards = [];
        foreach ($this->memberService->getLinkedMembers($email, $publicYearId) as $profile) {
            if (!isset($animeMemberIds[$profile->memberId])) {
                continue;
            }

            $cards[] = $this->card($profile, $publicYearId, $publicYearLabel, $targetYearId, $limit);
        }

        return $cards;
    }

    /**
     * The branch a child is heading into next year, or null when they are
     * not changing branch (or have nowhere further to go).
     *
     * What IT-17's name matching is scoped to: a typed « Léo » is looked
     * for among the children who will actually be placed alongside this
     * one, not among every Léo in the unit. Read off the same
     * `arrivalSectionsForMember()` call the card already makes, and with
     * the same `includeHidden: false` — a family cannot mean a section
     * they cannot see.
     */
    public function arrivalBranchIdFor(int $memberId, int $publicYearId, string $publicYearLabel): ?int
    {
        foreach ($this->passageService->arrivalSectionsForMember(
            $memberId,
            $publicYearId,
            $publicYearLabel,
            includeHidden: false
        ) as $section) {
            return (int) $section['age_branch_id'];
        }

        return null;
    }

    /**
     * Whether `$email`'s account may answer for `$memberId` — the one
     * check every save goes through, whatever the request looked like.
     */
    public function mayAnswerFor(string $email, int $memberId, int $publicYearId): bool
    {
        foreach ($this->passageService->getAnimeMemberYears($publicYearId, includeLeaving: true) as $row) {
            if ((int) $row['member_id'] !== $memberId) {
                continue;
            }

            foreach ($this->memberService->getLinkedMembers($email, $publicYearId) as $profile) {
                if ($profile->memberId === $memberId) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }

    /**
     * @return array{
     *   member_id: int,
     *   display_name: string,
     *   current_section_label: ?string,
     *   changes_branch: bool,
     *   arrival_sections: array<int, array{id: int, label: string}>,
     *   asks_section: bool,
     *   asks_friends: bool,
     *   friend_wish_limit: int,
     *   answer: ?ReenrollmentAnswer
     * }
     */
    private function card(
        MemberProfile $profile,
        int $publicYearId,
        string $publicYearLabel,
        int $targetYearId,
        int $friendWishLimit
    ): array {
        // Visible, active sections only — a section a family cannot see is
        // not a choice they can make.
        $arrival = $this->passageService->arrivalSectionsForMember(
            $profile->memberId,
            $publicYearId,
            $publicYearLabel,
            includeHidden: false
        );

        $sections = [];
        foreach ($arrival as $section) {
            $sections[] = ['id' => (int) $section['id'], 'label' => (string) ($section['name'] ?? $section['desk_code'])];
        }

        // An empty arrival list means one of two things and they are not
        // the same: the child is not changing branch, or they are at the
        // last rank of the oldest branch and go nowhere. Neither raises a
        // section question, so the card treats them alike; the wording
        // above the form says which it is.
        $changesBranch = $sections !== [];
        $asksSection = count($sections) > 1;

        return [
            'member_id' => $profile->memberId,
            'display_name' => $profile->totem ?? $profile->firstName,
            'current_section_label' => $this->currentSectionLabel($profile),
            'changes_branch' => $changesBranch,
            'arrival_sections' => $sections,
            'asks_section' => $asksSection,
            // The friend question rides with the section question: it is
            // only worth asking somebody who has a section to be placed in.
            'asks_friends' => $asksSection && $friendWishLimit > 0,
            'friend_wish_limit' => $friendWishLimit,
            'answer' => $this->reenrollmentService->findAnswer($profile->memberId, $targetYearId),
        ];
    }

    private function currentSectionLabel(MemberProfile $profile): ?string
    {
        foreach ($profile->functions as $function) {
            if ($function->isMainFunction && $function->sectionName !== null) {
                return $function->sectionName;
            }
        }

        foreach ($profile->functions as $function) {
            if ($function->sectionName !== null) {
                return $function->sectionName;
            }
        }

        return null;
    }
}
