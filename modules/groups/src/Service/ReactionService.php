<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\ReactionRepository;
use Modules\Groups\Support\Reactions;

/**
 * The toggle/replace/remove rule for reactions, on posts and on replies
 * alike — the two differ only in which Repository\ReactionRepository they
 * are handed, so the rule itself exists once.
 *
 * A reaction counts as activity and bumps the post's (and the group's)
 * last_activity_at through the module's single writer,
 * Service\GroupActivityService. That deliberately means a reaction also
 * postpones the post's eventual purge — intended behaviour, not an
 * oversight: a post people are still reacting to is not dormant.
 *
 * Authorisation is entirely the Controller's, exactly like every other
 * service in this module: by the time toggle() is called, the caller has
 * been confirmed to be able to read the group AND to write in it
 * (GroupAccessService::canPost()), and the item has been confirmed to
 * belong to that group.
 */
class ReactionService
{
    public function __construct(
        private ReactionRepository $postReactions,
        private ReactionRepository $replyReactions,
        private GroupActivityService $activityService
    ) {
    }

    /**
     * Reacting on a post. $postId must already have been confirmed to
     * belong to $group by the caller.
     *
     * @return bool false when $reactionKey is not one of the fixed six —
     *         the Controller turns that into a 400, and nothing is written
     */
    public function toggleOnPost(DiscussionGroup $group, int $postId, int $memberId, string $reactionKey): bool
    {
        return $this->toggle($this->postReactions, $group, $postId, $postId, $memberId, $reactionKey);
    }

    /**
     * Reacting on a reply. $postId is the reply's own post — needed
     * because activity is always bumped on the POST, never on the reply
     * (a reply has no last_activity_at of its own: the conversation's
     * freshness is the post's).
     */
    public function toggleOnReply(
        DiscussionGroup $group,
        int $replyId,
        int $postId,
        int $memberId,
        string $reactionKey
    ): bool {
        return $this->toggle($this->replyReactions, $group, $replyId, $postId, $memberId, $reactionKey);
    }

    /**
     * @param int[] $postIds
     * @param int[] $memberIds
     * @return array{counts: array<int, array<string, int>>, own: array<int, string>}
     */
    public function forPosts(array $postIds, array $memberIds): array
    {
        return [
            'counts' => $this->postReactions->countsFor($postIds),
            'own' => $this->postReactions->ownKeysFor($postIds, $memberIds),
        ];
    }

    /**
     * @param int[] $replyIds
     * @param int[] $memberIds
     * @return array{counts: array<int, array<string, int>>, own: array<int, string>}
     */
    public function forReplies(array $replyIds, array $memberIds): array
    {
        return [
            'counts' => $this->replyReactions->countsFor($replyIds),
            'own' => $this->replyReactions->ownKeysFor($replyIds, $memberIds),
        ];
    }

    /**
     * Same reaction as the member already has → remove it. Different one
     * (or none yet) → set it, replacing whatever was there. The uniqueness
     * that makes "replacing" meaningful is the database's, not this
     * read-then-write: see Repository\ReactionRepository::set().
     */
    private function toggle(
        ReactionRepository $repository,
        DiscussionGroup $group,
        int $itemId,
        int $postId,
        int $memberId,
        string $reactionKey
    ): bool {
        // Validated against the constant map before anything is written,
        // and before the item is even touched: an unknown key, an empty
        // string or a raw emoji character submitted instead of a key are
        // all refused here (module spec).
        if (!Reactions::isValid($reactionKey)) {
            return false;
        }

        if ($repository->findKeyFor($itemId, [$memberId]) === $reactionKey) {
            $repository->remove($itemId, $memberId);
        } else {
            $repository->set($itemId, $memberId, $reactionKey);
        }

        $this->activityService->bump($group->id, $postId);

        return true;
    }
}
