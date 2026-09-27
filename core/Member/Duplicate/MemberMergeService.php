<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Duplicate;

use Core\Journal\JournalService;

/**
 * Folds one `members` row into another.
 *
 * **Never automatic.** Two people can carry the same surname, first name
 * and date of birth. The site proposes pairs
 * ({@see DuplicateMemberDetector}); a human decides.
 *
 * **A merge deletes nothing.** It repoints foreign keys onto the kept
 * identity and leaves the abandoned row in place, marked
 * `merged_into_member_id`. Nothing in this codebase deletes a member, and
 * a merge is not the place to start — the abandoned row is also what
 * makes the operation auditable afterwards.
 *
 * **And it registers a Desk alias**, which is the part without which the
 * whole thing is pointless: the abandoned `desk_id` stays in the
 * federation's exports, and the next CSV carrying it would create a
 * brand-new `members` row and re-open the split this just repaired.
 * `MemberRepository::findByDeskId()` consults the alias table for exactly
 * that reason.
 *
 * The statements themselves live in {@see MemberMergeRepository}
 * (issue #629): this Service decides whether a pair may be merged and
 * journals what happened; the repository counts and moves.
 */
class MemberMergeService
{
    public function __construct(
        private MemberMergeRepository $repository,
        private DuplicateMemberRepository $candidates,
        private JournalService $journal
    ) {
    }

    /**
     * Count what a merge would move, without moving anything.
     */
    public function preview(int $keptMemberId, int $duplicateMemberId): MergePreview
    {
        return $this->repository->preview($duplicateMemberId);
    }

    /**
     * Repoint everything the duplicate carries onto the kept identity,
     * mark it merged, and register its Desk id as an alias.
     *
     * One transaction: a half-merged member is a member whose history is
     * split across two rows in a way nobody can see, which is worse than
     * the duplicate it was meant to repair.
     *
     * @throws MergeException when the pair cannot be merged, always before anything moves
     */
    public function merge(
        int $keptMemberId,
        int $duplicateMemberId,
        ?int $userAccountId,
        ?int $candidateId = null
    ): MergePreview
    {
        if ($keptMemberId === $duplicateMemberId) {
            throw new MergeException('Une fiche ne peut pas être fusionnée avec elle-même.');
        }

        $duplicateDeskId = $this->repository->deskIdOf($duplicateMemberId);
        if ($duplicateDeskId === null || $this->repository->deskIdOf($keptMemberId) === null) {
            throw new MergeException("L'une des deux fiches n'existe plus. Rechargez la page.");
        }

        // Two identities present in the SAME scout year are two people as
        // far as Desk is concerned: it guarantees one desk_id per person
        // per export, so both were in the same CSV. Merging them would
        // also collide head-on with member_years' (member, year) unique
        // index. Refused rather than half-applied — the real duplicate
        // this feature is about is strictly inter-year.
        if ($this->repository->sharedScoutYears($keptMemberId, $duplicateMemberId) > 0) {
            throw new MergeException(
                'Ces deux fiches sont présentes la même année scoute : Desk les considère comme deux '
                . "personnes distinctes. La fusion ne concerne qu'une fiche recréée d'une année à l'autre."
            );
        }

        $preview = $this->preview($keptMemberId, $duplicateMemberId);

        $this->repository->mergeInto(
            $keptMemberId,
            $duplicateMemberId,
            $duplicateDeskId,
            $userAccountId,
            $candidateId
        );

        // Numeric identifiers and counts only — a merge is a `security`
        // event, and who was merged with whom must not become readable as
        // personal data in the journal (SECURITY.md §11).
        $this->journal->log(
            'core',
            'member_merged',
            'security',
            'Deux fiches membres ont été fusionnées',
            [
                'kept_member_id' => $keptMemberId,
                'merged_member_id' => $duplicateMemberId,
                'scout_years' => $preview->scoutYears,
                'photos' => $preview->photos,
                'badges' => $preview->badges,
                'documents' => $preview->documents,
                'files' => $preview->files,
            ],
            $userAccountId
        );

        return $preview;
    }

    /**
     * Record that a proposed pair is two different people.
     *
     * A decision, and one the site has to remember: without it every
     * import would re-propose the same pair, and a list that keeps
     * re-asking a question already answered stops being read.
     */
    public function markDistinct(int $candidateId, ?int $userAccountId): void
    {
        $this->candidates->decide($candidateId, 'distinct', $userAccountId);

        $this->journal->log(
            'core',
            'member_duplicate_dismissed',
            'security',
            'Deux fiches membres semblables ont été déclarées distinctes',
            ['candidate_id' => $candidateId],
            $userAccountId
        );
    }
}
