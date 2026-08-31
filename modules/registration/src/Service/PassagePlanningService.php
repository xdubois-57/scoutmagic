<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Service;

use Core\Member\SectionService;
use Modules\Registration\Repository\FriendWish;
use Modules\Registration\Repository\PassageNoteRepository;
use Modules\Registration\Repository\ReenrollmentRepository;

/**
 * What the Passage page shows beside each line once the families have been
 * asked (roadmap IT-17): the section they said they would like, what they
 * wrote, the staff's own note, and the « avec qui » names with what the
 * server made of each.
 *
 * A view model, not a second source of truth: everything here is read from
 * the tables the reenrollment campaign already writes, plus the staff's own
 * `registration_passage_notes`. Nothing is computed twice.
 *
 * **Three columns, three owners, never mixed:**
 *
 * - the *family's* preferred section and comment come from their answer and
 *   are read-only on this page — a chief correcting a parent's words would
 *   be rewriting what a parent said;
 * - the *staff's* preferred section is a separate value in a separate
 *   table, shown when it exists and falling back to the family's when it
 *   does not, so a chief can record a wish for a family who never answered
 *   without fabricating an answer for them (the same rule IT-16 fixed in
 *   the other direction);
 * - the *staff's* internal note is never seen by the family and never
 *   leaves this page.
 *
 * One query per table for the whole page, keyed by member id / request id:
 * a section evening lists a whole branch at once.
 */
class PassagePlanningService
{
    public function __construct(
        private ReenrollmentRepository $reenrollmentRepository,
        private PassageNoteRepository $passageNoteRepository,
        private SectionService $sectionService,
        private ReenrollmentService $reenrollmentService
    ) {
    }

    /**
     * The extras for the « changements de branche » table, keyed by member
     * id.
     *
     * @param array<int, ?int> $arrivalBranchByMemberId member id => the
     *        branch they are heading into, which is the population a typed
     *        name is looked for among (IT-17) — null when unknown, and the
     *        whole roster is searched instead
     * @return array<int, array{
     *   family_section_label: ?string,
     *   family_comment: ?string,
     *   answered_at: ?\DateTimeImmutable,
     *   staff_section_id: ?int,
     *   staff_note: ?string,
     *   ai_suggestion: ?string,
     *   ai_confirmed: bool,
     *   wishes: array<int, array{id: int, raw_name: string, match_state: string, matched_label: ?string, candidates: array<int, array{member_id: int, label: string}>}>
     * }>
     */
    public function forMembers(array $arrivalBranchByMemberId, int $publicYearId, int $targetYearId): array
    {
        if ($arrivalBranchByMemberId === []) {
            return [];
        }

        $answers = $this->reenrollmentRepository->findAnswersForYear($targetYearId);
        $notes = $this->passageNoteRepository->findForYear($targetYearId);
        $sectionLabels = $this->sectionLabels();
        $limit = $this->reenrollmentService->friendWishLimit();

        $rows = [];
        foreach ($arrivalBranchByMemberId as $memberId => $arrivalBranchId) {
            $answer = $answers[$memberId] ?? null;
            $note = $notes[$memberId] ?? [
                'preferred_section_id' => null,
                'staff_note' => null,
                'ai_source_hash' => null,
                'ai_suggestion' => null,
                'ai_confirmed' => false,
            ];

            $rows[$memberId] = [
                'family_section_label' => $answer?->preferredSectionId !== null
                    ? ($sectionLabels[$answer->preferredSectionId] ?? null)
                    : null,
                'family_comment' => $answer?->familyComment,
                'answered_at' => $answer?->answeredAt,
                'staff_section_id' => $note['preferred_section_id'],
                'staff_note' => $note['staff_note'],
                // Shown « à vérifier » until a chief says otherwise, and
                // read by nothing downstream before that (IT-18).
                'ai_suggestion' => $note['ai_suggestion'],
                'ai_confirmed' => $note['ai_confirmed'],
                'wishes' => $answer === null
                    ? []
                    : $this->describeWishes(
                        $answer->friendWishesWithin($limit),
                        $publicYearId,
                        $targetYearId,
                        $arrivalBranchId,
                        $memberId
                    ),
            ];
        }

        return $rows;
    }

    /**
     * The extras for the « nouvelles inscriptions » table, keyed by request
     * id.
     *
     * A request already carries the family's own remarks and the staff's
     * internal notes in its own columns — this adds only the « avec qui »
     * names, which live in a table of their own because a request has no
     * member id (IT-14).
     *
     * @param array<int, ?int> $arrivalBranchByRequestId request id => the
     *        branch the child's birth date puts them in
     * @return array<int, array{wishes: array<int, array{id: int, raw_name: string, match_state: string, matched_label: ?string, candidates: array<int, array{member_id: int, label: string}>}>}>
     */
    public function forRequests(array $arrivalBranchByRequestId, int $publicYearId, int $targetYearId): array
    {
        $limit = $this->reenrollmentService->friendWishLimit();

        $rows = [];
        foreach ($arrivalBranchByRequestId as $requestId => $arrivalBranchId) {
            $wishes = $this->reenrollmentRepository->findRequestWishes($requestId);
            $rows[$requestId] = [
                'wishes' => $this->describeWishes(
                    $limit > 0 ? array_slice($wishes, 0, $limit) : [],
                    $publicYearId,
                    $targetYearId,
                    $arrivalBranchId,
                    0
                ),
            ];
        }

        return $rows;
    }

    /**
     * A chief deciding which of several candidates a typed name meant.
     *
     * Returns the chosen member's label, or null when they are not one of
     * the candidates the name actually produces. The check is a
     * RE-DERIVATION, never a comparison with anything the request
     * supplied: a member id in a POST body is not a boundary, and a forged
     * one would attach a child nobody named to a wish they never appear
     * in.
     */
    public function resolveWish(
        int $wishId,
        int $chosenMemberId,
        int $publicYearId,
        int $targetYearId,
        ?int $arrivalBranchId
    ): ?string {
        $wish = $this->reenrollmentRepository->findWish($wishId);
        $owner = $this->reenrollmentRepository->findWishOwner($wishId);
        if ($wish === null || $owner === null) {
            return null;
        }

        $candidates = $this->reenrollmentService->candidatesFor(
            $wish->rawName,
            $publicYearId,
            $arrivalBranchId,
            $targetYearId,
            $owner['member_id']
        );

        foreach ($candidates as $candidate) {
            if ($candidate['member_id'] === $chosenMemberId) {
                $this->reenrollmentRepository->resolveWish($wishId, $chosenMemberId);

                return $candidate['label'];
            }
        }

        return null;
    }

    /**
     * @param array<int, FriendWish> $wishes
     * @return array<int, array{id: int, raw_name: string, match_state: string, matched_label: ?string, candidates: array<int, array{member_id: int, label: string}>}>
     */
    private function describeWishes(
        array $wishes,
        int $publicYearId,
        int $targetYearId,
        ?int $arrivalBranchId,
        int $selfMemberId
    ): array {
        $described = [];
        foreach ($wishes as $wish) {
            // The candidates are recomputed rather than stored: a match is
            // a question about today's roster, and a list frozen at
            // submission time would name children who have since left.
            // Only for an ambiguous wish — the others have nothing to
            // choose between.
            $candidates = $wish->matchState === FriendWish::MATCH_AMBIGUOUS
                ? $this->reenrollmentService->candidatesFor(
                    $wish->rawName,
                    $publicYearId,
                    $arrivalBranchId,
                    $targetYearId,
                    $selfMemberId
                )
                : [];

            $described[] = [
                'id' => $wish->id,
                'raw_name' => $wish->rawName,
                'match_state' => $wish->matchState,
                'matched_label' => $wish->matchedMemberId !== null
                    ? $this->reenrollmentService->labelForMember($wish->matchedMemberId, $publicYearId)
                    : null,
                'candidates' => $candidates,
            ];
        }

        return $described;
    }

    /**
     * @return array<int, string>
     */
    private function sectionLabels(): array
    {
        $labels = [];
        foreach ($this->sectionService->getAllWithBranches(true) as $section) {
            $labels[(int) $section['id']] = (string) ($section['name'] ?? $section['desk_code']);
        }

        return $labels;
    }
}
