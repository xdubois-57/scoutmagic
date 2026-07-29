<?php

declare(strict_types=1);

namespace Modules\Retro\Service;

use Modules\Retro\Repository\Board;
use Modules\Retro\Repository\Comment;
use Modules\Retro\Repository\CommentRepository;

/**
 * Comment posting and moderation. Deliberately never calls JournalService
 * — see the module's schema.sql header comment and Service\
 * RateLimitService's class doc for why: even a timestamped stream of board
 * events (with no personal data at all) could still be correlated with
 * other logs to deanonymise a participant, so posting/hiding/unhiding are
 * never logged anywhere, by design, not by oversight.
 */
class CommentService
{
    public function __construct(
        private CommentRepository $commentRepository,
        private ?ModerationService $moderationService,
        private RateLimitService $rateLimitService
    ) {
    }

    /**
     * @param string $moderationMode 'disabled'|'warning'|'enforced' — resolved
     *        server-side by the caller from the retro_moderation_mode setting
     *        (never trust a client-supplied mode). Any other value behaves as
     *        'disabled'.
     * @param bool $acceptedWarning Only meaningful in 'warning' mode: the
     *        participant has already seen a flag+suggestion once and chose to
     *        publish their own wording anyway. Ignored in 'enforced' mode —
     *        there is no bypass there, by design.
     * @throws RetroException TYPE_OFFENSIVE carries the AI's suggestion (if any)
     */
    public function postComment(
        Board $board,
        string $columnKey,
        string $body,
        string $rateLimitIdentifierHash,
        string $moderationMode = 'disabled',
        bool $acceptedWarning = false
    ): Comment {
        if (!$board->isOpen()) {
            throw new RetroException('Ce board est clôturé.', RetroException::TYPE_CLOSED);
        }
        if (!in_array($columnKey, Comment::COLUMNS, true)) {
            throw new RetroException('Colonne invalide.');
        }

        // Checked before the (potentially costly) moderation call, so an
        // abusive burst never reaches the LLM.
        $this->rateLimitService->checkAndRecord($rateLimitIdentifierHash, 'comment');

        $body = trim($body);
        if ($body === '') {
            throw new RetroException('Le commentaire ne peut pas être vide.');
        }
        if (mb_strlen($body) > $board->maxCommentLength) {
            $over = mb_strlen($body) - $board->maxCommentLength;
            throw new RetroException(
                "Message trop long de {$over} caractère" . ($over > 1 ? 's' : '') . '.',
                RetroException::TYPE_TOO_LONG
            );
        }

        $skipModeration = $moderationMode === 'disabled'
            || ($moderationMode === 'warning' && $acceptedWarning);

        if ($this->moderationService !== null && !$skipModeration) {
            $result = $this->moderationService->moderate($body, $board->maxCommentLength);
            if ($result !== null && $result['flagged']) {
                throw new RetroException(
                    $result['reason'] ?? 'Ce message peut être perçu comme une attaque personnelle ou un propos irrespectueux. Merci de reformuler ta pensée.',
                    RetroException::TYPE_OFFENSIVE,
                    $result['suggestion']
                );
            }
        }

        $id = $this->commentRepository->create($board->id, $columnKey, $body);
        $comment = $this->commentRepository->findById($id);
        \assert($comment !== null);

        return $comment;
    }

    public function hide(int $commentId): void
    {
        $this->commentRepository->setHidden($commentId, true);
    }

    public function unhide(int $commentId): void
    {
        $this->commentRepository->setHidden($commentId, false);
    }

    /**
     * @return Comment[]
     */
    public function listForDisplay(int $boardId, bool $sortByVotes): array
    {
        $comments = $this->commentRepository->findByBoardId($boardId);
        if ($sortByVotes) {
            usort($comments, fn(Comment $a, Comment $b) => $b->votes <=> $a->votes);
        }

        return $comments;
    }
}
