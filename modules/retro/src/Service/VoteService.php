<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Retro\Service;

use Core\Security\EncryptionService;
use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\Comment;
use Modules\Retro\Repository\CommentRepository;
use Modules\Retro\Repository\VoteRepository;

/**
 * Voting, including the module's anonymity-critical vote-deduplication
 * mechanism. Two one-way HMACs (Core\Security\EncryptionService::
 * blindIndex() — the same blind-index technique used elsewhere in this
 * codebase for encrypted-but-searchable lookups), both derived from a
 * voter identifier that is never itself stored:
 *
 * - voterHash(): scoped to (voter, board, comment) — "has this voter
 *   already voted on this exact comment?", the dedup key.
 * - voterBoardHash(): scoped to (voter, board) only — budget mode needs to
 *   sum how many points a voter has spent across an ENTIRE board, which
 *   voterHash() alone cannot answer (it differs per comment by design).
 *   This is the minimum extra correlation a shared-budget feature
 *   requires: it links a voter's own rows together within one board, but
 *   still never reveals who they are, and a different board yields an
 *   unrelated hash.
 */
class VoteService
{
    public function __construct(
        private VoteRepository $voteRepository,
        private CommentRepository $commentRepository,
        private EncryptionService $encryption
    ) {
    }

    public function voterHash(int $boardId, int $commentId, string $voterIdentifier): string
    {
        return $this->encryption->blindIndex("{$voterIdentifier}:{$boardId}:{$commentId}", 'retro_vote');
    }

    public function voterBoardHash(int $boardId, string $voterIdentifier): string
    {
        return $this->encryption->blindIndex("{$voterIdentifier}:{$boardId}", 'retro_vote_board');
    }

    /**
     * Unlimited mode: toggles a like. Returns the new liked state.
     *
     * @throws RetroException
     */
    public function toggleLike(Board $board, Comment $comment, string $voterIdentifier): bool
    {
        $this->assertCanVote($board, 'unlimited');

        $hash = $this->voterHash($board->id, $comment->id, $voterIdentifier);
        $existing = $this->voteRepository->findByCommentAndVoter($comment->id, $hash);

        if ($existing !== null) {
            $this->voteRepository->delete($existing->id);
            $this->syncCommentVotes($comment->id);
            return false;
        }

        $boardHash = $this->voterBoardHash($board->id, $voterIdentifier);
        $this->voteRepository->create($comment->id, $board->id, $hash, $boardHash, 1);
        $this->syncCommentVotes($comment->id);
        return true;
    }

    /**
     * Budget mode: allocates one more point to $comment. Returns the
     * remaining budget after the allocation.
     *
     * @throws RetroException
     */
    public function addPoint(Board $board, Comment $comment, string $voterIdentifier): int
    {
        $this->assertCanVote($board, 'budget');

        $remaining = $this->remainingBudget($board, $voterIdentifier);
        if ($remaining <= 0) {
            throw new RetroException('Vous avez déjà utilisé tous vos points sur ce board.');
        }

        $hash = $this->voterHash($board->id, $comment->id, $voterIdentifier);
        $existing = $this->voteRepository->findByCommentAndVoter($comment->id, $hash);

        if ($existing !== null) {
            $this->voteRepository->updateWeight($existing->id, $existing->weight + 1);
        } else {
            $boardHash = $this->voterBoardHash($board->id, $voterIdentifier);
            $this->voteRepository->create($comment->id, $board->id, $hash, $boardHash, 1);
        }

        $this->syncCommentVotes($comment->id);
        return $remaining - 1;
    }

    /**
     * Budget mode: removes one point from $comment (only ever a point this
     * voter allocated themselves — the unique voter_hash constraint makes
     * that automatic). Returns the remaining budget after the removal.
     *
     * @throws RetroException
     */
    public function removePoint(Board $board, Comment $comment, string $voterIdentifier): int
    {
        $this->assertCanVote($board, 'budget');

        $hash = $this->voterHash($board->id, $comment->id, $voterIdentifier);
        $existing = $this->voteRepository->findByCommentAndVoter($comment->id, $hash);
        if ($existing === null) {
            return $this->remainingBudget($board, $voterIdentifier);
        }

        if ($existing->weight <= 1) {
            $this->voteRepository->delete($existing->id);
        } else {
            $this->voteRepository->updateWeight($existing->id, $existing->weight - 1);
        }

        $this->syncCommentVotes($comment->id);
        return $this->remainingBudget($board, $voterIdentifier);
    }

    /**
     * Which of $commentIds this voter currently has an active vote on — the
     * UI needs this to keep the "you voted" state visible across polling
     * re-renders (without it, unlimited mode's like button always redraws
     * as un-liked on the next poll even though the vote is still recorded,
     * which reads as the vote having silently failed or been reverted).
     *
     * @param int[] $commentIds
     * @return int[] the subset of $commentIds this voter has an active vote on
     */
    public function votedCommentIds(int $boardId, array $commentIds, string $voterIdentifier): array
    {
        if ($commentIds === []) {
            return [];
        }

        $hashByCommentId = [];
        foreach ($commentIds as $commentId) {
            $hashByCommentId[$commentId] = $this->voterHash($boardId, $commentId, $voterIdentifier);
        }

        $existing = array_flip($this->voteRepository->findExistingHashes($boardId, array_values($hashByCommentId)));

        return array_values(array_filter($commentIds, fn(int $id) => isset($existing[$hashByCommentId[$id]])));
    }

    public function remainingBudget(Board $board, string $voterIdentifier): int
    {
        $boardHash = $this->voterBoardHash($board->id, $voterIdentifier);
        return max(0, $board->voteBudget - $this->voteRepository->sumWeightForVoterOnBoard($board->id, $boardHash));
    }

    private function assertCanVote(Board $board, string $expectedMode): void
    {
        if (!$board->isOpen()) {
            throw new RetroException('Ce board est clôturé.', RetroException::TYPE_CLOSED);
        }
        if ($board->voteMode !== $expectedMode) {
            throw new RetroException('Mode de vote invalide pour ce board.');
        }
    }

    private function syncCommentVotes(int $commentId): void
    {
        $this->commentRepository->setVotes($commentId, $this->voteRepository->sumWeightForComment($commentId));
    }
}
