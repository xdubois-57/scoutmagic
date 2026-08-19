<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\Reply;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Support\Timestamps;

/**
 * Creating, editing and deleting replies, and the rules that govern each —
 * the exact counterpart of Service\PostService one level down.
 *
 * Authorisation for "may this session write in this group at all" stays
 * Service\GroupAccessService's (the Controller re-checks canPost() on
 * every write, replies included); this service owns the per-reply rules on
 * top, and they are deliberately identical to a post's: the author may
 * edit inside a 15-minute window computed from the stored created_at, and
 * the author or any moderator may delete.
 */
class ReplyService
{
    /**
     * Plain text, bounded server-side — the client's maxlength is a
     * convenience, never the limit. Shorter than a post's own ceiling
     * because a reply is a reply.
     */
    public const MAX_BODY_LENGTH = 2000;

    /**
     * Same window as a post's (Service\PostService::EDIT_WINDOW_MINUTES),
     * measured the same way: from the stored created_at, in UTC, on the
     * server — never from anything the client sends.
     */
    public const EDIT_WINDOW_MINUTES = 15;

    /**
     * How many replies a post shows inline in the feed before offering
     * "Charger plus", and the page size of that endpoint.
     */
    public const PAGE_SIZE = 5;

    public function __construct(
        private ReplyRepository $replyRepository,
        private GroupActivityService $activityService,
        private PostMediaService $postMediaService
    ) {
    }

    /**
     * A reply counts as activity: it bumps both the post's and the group's
     * last_activity_at through the module's single writer
     * (Service\GroupActivityService), which is what floats an active
     * conversation back to the top of the feed.
     *
     * @return int the new reply id
     */
    public function create(
        DiscussionGroup $group,
        int $postId,
        int $userAccountId,
        int $authorMemberId,
        string $body,
        ?int $galleryMediaId = null
    ): int {
        $now = Timestamps::now();
        $replyId = $this->replyRepository->create(
            $postId,
            $userAccountId,
            $authorMemberId,
            $this->normalize($body),
            $galleryMediaId,
            $now
        );
        $this->activityService->bump($group->id, $postId, $now);

        return $replyId;
    }

    /**
     * The author — the account that actually typed the reply, not the
     * member it was signed as — and only inside the window.
     */
    public function canEdit(Reply $reply, GroupSessionContext $context, ?\DateTimeImmutable $now = null): bool
    {
        if ($context->userAccountId === null || $reply->authorUserAccountId !== $context->userAccountId) {
            return false;
        }

        return $this->isWithinEditWindow($reply, $now);
    }

    /**
     * Computed from the stored created_at every single time, both sides in
     * UTC (Support\Timestamps). Nothing the client sends takes part in
     * this: a forged "created 10 seconds ago" field changes nothing,
     * because no such field is ever read.
     */
    public function isWithinEditWindow(Reply $reply, ?\DateTimeImmutable $now = null): bool
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $deadline = Timestamps::toUtc($reply->createdAt)->modify('+' . self::EDIT_WINDOW_MINUTES . ' minutes');

        return $now <= $deadline;
    }

    public function canDelete(Reply $reply, GroupSessionContext $context, bool $canModerate): bool
    {
        return $canModerate
            || ($context->userAccountId !== null && $reply->authorUserAccountId === $context->userAccountId);
    }

    /**
     * Editing marks the reply "modifié" and nothing else — last_activity_at
     * is untouched, for the same reason editing a post leaves it alone:
     * correcting a typo must not resurrect an old conversation to the top
     * of the feed (nor postpone its later purge).
     */
    public function edit(Reply $reply, string $body): void
    {
        $this->replyRepository->updateBody($reply->id, $this->normalize($body), Timestamps::now());
    }

    /**
     * Deletes the reply's image (gallery row and stored object) before the
     * reply row itself — the image lives in another module's table, which
     * no CASCADE from here can reach. The reply's own reactions ARE
     * reached by the CASCADE (schema.sql).
     */
    public function delete(Reply $reply, DiscussionGroup $group): void
    {
        if ($reply->galleryMediaId !== null) {
            $this->postMediaService->deleteOne($group, $reply->galleryMediaId);
        }

        $this->replyRepository->delete($reply->id);
    }

    /**
     * Every reply image of a post about to be deleted. Called by
     * Controller\PostController::delete() BEFORE the post row goes: the
     * CASCADE removes the reply rows (and their reactions) on its own, but
     * would leave their gallery media orphaned in storage.
     *
     * Never throws: a media row already gone must not block the post's own
     * removal — same contract as
     * Service\PostMediaService::deleteAllForPost().
     */
    public function deleteAllMediaForPost(DiscussionGroup $group, int $postId): void
    {
        foreach ($this->replyRepository->findMediaIdsForPost($postId) as $mediaId) {
            $this->postMediaService->deleteOne($group, $mediaId);
        }
    }

    /**
     * A reply with neither text nor an image is invalid; text alone and
     * image alone are both valid (module spec).
     */
    public function isReplyable(string $body, bool $hasImage = false): bool
    {
        return $this->normalize($body) !== '' || $hasImage;
    }

    /**
     * Plain text in, plain text out — identical treatment to a post's
     * body (Service\PostService::normalize()): line endings normalised,
     * control characters other than newline and tab dropped, length
     * bounded. No markup is produced, interpreted or stripped.
     */
    public function normalize(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $body = (string) preg_replace('/[^\P{C}\n\t]/u', '', $body);

        return mb_substr(trim($body), 0, self::MAX_BODY_LENGTH);
    }
}
