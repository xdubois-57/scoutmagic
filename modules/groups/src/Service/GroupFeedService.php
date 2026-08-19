<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;

/**
 * Assembles a feed page: pinned posts first, then the stream ordered by
 * last_activity_at descending, paginated by keyset.
 *
 * Pinned posts are fetched separately and never appear in the stream —
 * PostRepository::findPage() excludes them. Two reasons, both of which
 * bite in practice: mixing them in duplicates a pinned post (once at the
 * top, once in its chronological place), and pinning or unpinning
 * anything mid-scroll would shift the stream under a cursor that assumes
 * a stable set.
 */
class GroupFeedService
{
    public const PAGE_SIZE = 20;

    public function __construct(
        private PostRepository $postRepository,
        private PostAuthorResolver $authorResolver,
        private PostService $postService,
        private PostMediaService $postMediaService,
        private PostLinkRepository $postLinkRepository,
        private ReplyRepository $replyRepository,
        private ReplyPresenter $replyPresenter,
        private ReactionService $reactionService
    ) {
    }

    public function page(DiscussionGroup $group, GroupSessionContext $context, bool $canModerate, ?string $cursor = null): FeedPage
    {
        $isFirstPage = $cursor === null;

        // One extra row, to know whether another page exists without a
        // second COUNT query.
        $rows = $this->postRepository->findPage($group->id, self::PAGE_SIZE + 1, $this->decodeCursor($cursor));
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);

        $pinned = $isFirstPage ? $this->postRepository->findPinned($group->id) : [];
        $all = array_merge($pinned, $rows);

        // Author names for the pinned posts and the page together: still
        // two queries, whatever the page holds.
        $labels = $this->authorResolver->resolve($all, $group->scoutYearId ?? $context->effectiveScoutYearId);

        // Same "resolve once, decorate many" shape: the album's whole
        // media list is fetched once (Service\PostMediaService::
        // albumMediaById()), then mediaForPosts() maps it onto every post
        // on this page in one more query, never once per post.
        $postIds = array_map(fn(Post $p) => $p->id, $all);
        $mediaById = $this->postMediaService->albumMediaById($group);
        $mediaByPost = $this->postMediaService->mediaForPosts($postIds, $mediaById);
        $linkByPost = $this->postLinkRepository->findForPosts($postIds);

        // Replies and reactions for the whole page, never per post: the
        // first few replies of every post plus their true totals come back
        // in two queries (Repository\ReplyRepository::findFirstForPosts()),
        // and the post reactions in two more.
        $replyData = $this->replyRepository->findFirstForPosts($postIds, ReplyService::PAGE_SIZE);
        $postReactions = $this->reactionService->forPosts($postIds, $context->linkedMemberIds);

        $repliesByPost = [];
        foreach ($replyData['replies'] as $postId => $replies) {
            $repliesByPost[$postId] = $this->replyPresenter->decorate(
                $replies,
                $context,
                $canModerate,
                $group->scoutYearId ?? $context->effectiveScoutYearId,
                $mediaById
            );
        }

        $decorated = [
            'labels' => $labels,
            'media' => $mediaByPost,
            'links' => $linkByPost,
            'replies' => $repliesByPost,
            'reply_counts' => $replyData['counts'],
            'reactions' => $postReactions,
        ];

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new FeedPage(
            array_map(fn(Post $p) => $this->decorate($p, $decorated, $context, $canModerate), $pinned),
            array_map(fn(Post $p) => $this->decorate($p, $decorated, $context, $canModerate), $rows),
            $hasMore && $last !== null ? $this->encodeCursor($last) : null
        );
    }

    /**
     * $page carries everything already resolved once for the whole page —
     * passed as one array rather than six positional parameters, which is
     * what kept this signature from growing a parameter per feature.
     *
     * @param array{
     *     labels: array<int, array{display_name: string, account_name: string}>,
     *     media: array<int, \Modules\Gallery\Api\DelegatedMedia[]>,
     *     links: array<int, \Modules\Groups\Repository\PostLink>,
     *     replies: array<int, array<int, array<string, mixed>>>,
     *     reply_counts: array<int, int>,
     *     reactions: array{counts: array<int, array<string, int>>, own: array<int, string>}
     * } $page
     * @return array<string, mixed>
     */
    private function decorate(Post $post, array $page, GroupSessionContext $context, bool $canModerate): array
    {
        $label = $page['labels'][$post->id] ?? ['display_name' => '', 'account_name' => ''];
        $shownReplies = $page['replies'][$post->id] ?? [];
        $totalReplies = $page['reply_counts'][$post->id] ?? 0;

        return [
            'post' => $post,
            'display_name' => $label['display_name'],
            'account_name' => $label['account_name'],
            'media' => $page['media'][$post->id] ?? [],
            'link' => $page['links'][$post->id] ?? null,
            'replies' => $shownReplies,
            'reply_count' => $totalReplies,
            // The cursor "Charger plus" continues from: the last reply
            // actually rendered. Null when everything already fits, which
            // is also what hides the button.
            'replies_next_after_id' => $totalReplies > count($shownReplies) && $shownReplies !== []
                ? $shownReplies[count($shownReplies) - 1]['reply']->id
                : null,
            'reactions' => ReactionSummary::build(
                $page['reactions']['counts'][$post->id] ?? [],
                $page['reactions']['own'][$post->id] ?? null
            ),
            // Every one of these is re-checked server-side by the action
            // itself — they only decide whether the kebab entry is shown.
            'can_edit' => $this->postService->canEdit($post, $context),
            'can_delete' => $this->postService->canDelete($post, $context, $canModerate),
            'can_pin' => $canModerate,
        ];
    }

    private function encodeCursor(Post $post): string
    {
        return base64_encode($post->lastActivityAt . '|' . $post->id);
    }

    /**
     * A cursor comes back from the client, so it is treated as untrusted
     * input: anything that does not decode to (timestamp, positive id) is
     * discarded and the caller simply gets the first page. It carries no
     * authority of its own either — the group is authorised separately.
     *
     * @return array{last_activity_at: string, id: int}|null
     */
    private function decodeCursor(?string $cursor): ?array
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $decoded = base64_decode($cursor, true);
        if ($decoded === false || !str_contains($decoded, '|')) {
            return null;
        }

        [$lastActivityAt, $id] = explode('|', $decoded, 2);
        if (!ctype_digit($id) || (int) $id <= 0) {
            return null;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $lastActivityAt) !== 1) {
            return null;
        }

        return ['last_activity_at' => $lastActivityAt, 'id' => (int) $id];
    }
}
