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

    /**
     * A search shows one screenful of the best-matching messages and no
     * "load more". Anything past this means the term was too broad to be
     * worth paging through — the honest answer there is a narrower term,
     * not page 4 of 30.
     */
    public const RESULT_LIMIT = 30;

    public function __construct(
        private PostRepository $postRepository,
        private PostAuthorResolver $authorResolver,
        private PostService $postService,
        private PostMediaService $postMediaService,
        private PostLinkRepository $postLinkRepository,
        private ReplyRepository $replyRepository,
        private ReplyPresenter $replyPresenter,
        private ReactionService $reactionService,
        private ReportService $reportService,
        // Nullable and last, like every other optional collaborator in
        // this module: a feed renders perfectly well with no read state
        // at all, it just shows no "vu par" line.
        private ?GroupReadStateService $readStateService = null,
        // Also nullable and also last, and for a stronger reason than
        // the one above: this one reaches another MODULE. With calendar
        // disabled it is simply absent and no post shows an event.
        private ?PostEventService $eventService = null,
        private ?PollService $pollService = null
    ) {
    }

    public function page(DiscussionGroup $group, GroupSessionContext $context, bool $canModerate, ?string $cursor = null): FeedPage
    {
        $isFirstPage = $cursor === null;

        // A pin whose deadline has passed stops being one HERE, before
        // the page is read — a lapsed pin has to go away on its own
        // whether or not this install's scheduler ever runs, and the
        // group's own page load is the one moment somebody is certainly
        // looking (Service\PostService::expireStalePins()). One bounded
        // UPDATE that writes nothing at all in the ordinary case, so the
        // feed pays for it only when there is something to fix. Only on
        // the first page: the deeper pages of one scroll must not
        // re-answer a question already settled at the top.
        if ($isFirstPage) {
            $this->postService->expireStalePins($group->id);
        }

        // One extra row, to know whether another page exists without a
        // second COUNT query. A moderator, and only a moderator, sees
        // auto-hidden posts — and the exclusion happens in the SQL, so a
        // hidden post is absent from the rows entirely rather than
        // rendered and then hidden in the markup (module spec).
        $rows = $this->postRepository->findPage(
            $group->id,
            self::PAGE_SIZE + 1,
            $this->decodeCursor($cursor),
            $canModerate
        );
        $hasMore = count($rows) > self::PAGE_SIZE;
        $rows = array_slice($rows, 0, self::PAGE_SIZE);

        $pinned = $isFirstPage ? $this->postRepository->findPinned($group->id, $canModerate) : [];
        $all = array_merge($pinned, $rows);

        $decorated = $this->resolveFor($group, $all, $context, $canModerate);

        $last = $rows === [] ? null : $rows[count($rows) - 1];

        return new FeedPage(
            array_map(fn(Post $p) => $this->decorate($p, $decorated, $context, $canModerate), $pinned),
            array_map(fn(Post $p) => $this->decorate($p, $decorated, $context, $canModerate), $rows),
            $hasMore && $last !== null ? $this->encodeCursor($last) : null
        );
    }

    /**
     * Everything a set of posts needs, resolved once for all of them —
     * author labels, media, links, first replies, reply totals, reactions
     * and reports — in a fixed number of queries whatever the set holds.
     * This is the "resolve once, decorate many" shape the feed has always
     * had; it is a method of its own so that search() below reuses it
     * verbatim rather than growing a second, drifting copy.
     *
     * @param Post[] $posts
     * @return array<string, mixed> the $page array decorate() expects
     */
    private function resolveFor(DiscussionGroup $group, array $posts, GroupSessionContext $context, bool $canModerate): array
    {
        $scoutYearId = $group->scoutYearId ?? $context->effectiveScoutYearId;
        $labels = $this->authorResolver->resolve($posts, $scoutYearId);

        // The album's whole media list is fetched once
        // (Service\PostMediaService::albumMediaById()), then
        // mediaForPosts() maps it onto every post in one more query,
        // never once per post.
        $postIds = array_map(fn(Post $p) => $p->id, $posts);
        $mediaById = $this->postMediaService->albumMediaById($group);
        $mediaByPost = $this->postMediaService->mediaForPosts($postIds, $mediaById);
        $linkByPost = $this->postLinkRepository->findForPosts($postIds);

        // Replies and reactions for the whole set, never per post: the
        // first few replies of every post plus their true totals come back
        // in two queries (Repository\ReplyRepository::findLastForPosts()),
        // and the post reactions in two more.
        $replyData = $this->replyRepository->findLastForPosts($postIds, ReplyService::PAGE_SIZE, $canModerate);
        $postReactions = $this->reactionService->forPosts($postIds, $context->linkedMemberIds);
        $postReports = $this->reportService->forPosts($postIds, $context->linkedMemberIds);
        $seenCounts = $this->readStateService?->seenCountsForPosts($group, $posts) ?? [];

        // "2 nouveaux" on a collapsed thread — replies that arrived since
        // this reader last opened the group. Computed here rather than in
        // the template because it needs the reader's own reading position,
        // and skipped entirely for a first visit (lastReadAt() null), when
        // every reply would qualify and none of them would mean anything.
        //
        // Controller\GroupController::show() marks the group read AFTER
        // building this page, never before, which is what makes the
        // numbers describe the visit the reader is arriving from rather
        // than the one they are making.
        $lastReadAt = $this->readStateService?->lastReadAt($group, $context);
        $newReplyCounts = $lastReadAt === null || $postIds === []
            ? []
            : $this->replyRepository->countNewerForPosts($postIds, $lastReadAt, $context->linkedMemberIds, $canModerate);
        $polls = $this->pollService?->forPosts($postIds, $context->userAccountId, $context->linkedMemberIds) ?? [];
        $events = $this->eventService?->summariesFor(
            array_map(fn(Post $p) => $p->calendarEventId, $posts),
            $context->role
        ) ?? [];

        $repliesByPost = [];
        foreach ($replyData['replies'] as $postId => $replies) {
            $repliesByPost[$postId] = $this->replyPresenter->decorate(
                $replies,
                $context,
                $canModerate,
                $scoutYearId,
                $mediaById
            );
        }

        return [
            'labels' => $labels,
            'media' => $mediaByPost,
            'links' => $linkByPost,
            'replies' => $repliesByPost,
            'reply_counts' => $replyData['counts'],
            'reactions' => $postReactions,
            'reports' => $postReports,
            'seen_counts' => $seenCounts,
            'new_reply_counts' => $newReplyCounts,
            'events' => $events,
            'polls' => $polls,
        ];
    }

    /**
     * Messages of this group matching what the member typed, newest
     * first, rendered as exactly the same cards as the feed.
     *
     * A post is a hit when its own body matches OR one of its replies
     * does — the two searches are separate queries whose results are
     * merged here, because a reply lives in its own table and a hit on it
     * is still a hit on the conversation it belongs to.
     *
     * $canModerate is threaded all the way down into both queries, so
     * auto-hidden messages are excluded in SQL for everyone else. Nothing
     * about visibility is decided in this method; it only passes the flag
     * the controller computed from the authorised group.
     *
     * @return array<int, array<string, mixed>> decorated rows, at most RESULT_LIMIT
     */
    public function search(DiscussionGroup $group, GroupSessionContext $context, bool $canModerate, string $pattern): array
    {
        $matched = $this->postRepository->search($group->id, $pattern, self::RESULT_LIMIT, $canModerate);

        // Only the posts a reply matched that the body search did not
        // already return — asking for the rest by id keeps the two halves
        // from ever producing the same card twice.
        $seen = [];
        foreach ($matched as $post) {
            $seen[$post->id] = true;
        }
        $viaReplies = array_values(array_filter(
            $this->replyRepository->searchPostIds($group->id, $pattern, self::RESULT_LIMIT, $canModerate),
            fn (int $id) => !isset($seen[$id])
        ));

        $posts = array_merge($matched, $this->postRepository->findByIdsInGroup($group->id, $viaReplies, $canModerate));

        // Re-sorted across the merge, then cut: each half came back
        // newest-first on its own, and concatenating two sorted lists does
        // not give a sorted one.
        usort($posts, fn (Post $a, Post $b) => [$b->createdAt, $b->id] <=> [$a->createdAt, $a->id]);
        $posts = array_slice($posts, 0, self::RESULT_LIMIT);

        $decorated = $this->resolveFor($group, $posts, $context, $canModerate);

        return array_map(fn(Post $p) => $this->decorate($p, $decorated, $context, $canModerate), $posts);
    }

    /**
     * The same cards as the feed, for an explicit list of post ids — what
     * a moderator's reports page renders (Controller\ReportController::
     * index()).
     *
     * Goes through the very same resolveFor()/decorate() pair as the feed
     * and the search, so a card on that page can never say something
     * different from the same card in the conversation. Always built with
     * $canModerate true: the only caller is a moderator's own page, and a
     * reported item that has already been hidden is precisely what they
     * came to look at.
     *
     * @param int[] $postIds
     * @return array<int, array<string, mixed>>
     */
    public function rowsForPostIds(DiscussionGroup $group, GroupSessionContext $context, array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }

        $posts = $this->postRepository->findByIdsInGroup($group->id, $postIds, true);
        $decorated = $this->resolveFor($group, $posts, $context, true);

        return array_map(fn(Post $p) => $this->decorate($p, $decorated, $context, true), $posts);
    }

    /**
     * One row, in exactly the shape decorate() builds for a page — the
     * post PostController::create() just made has no replies, no reports
     * and no reactions yet, so those three batched lookups are trivially
     * empty rather than genuinely queried; its own author label, media
     * and link still go through the real lookups, scoped to this one id
     * instead of a whole page. groups.js inserts the returned fragment
     * into the feed without a reload, which is the only reason this
     * exists — every other caller still goes through page() above.
     *
     * @return array<string, mixed>
     */
    public function rowForNewPost(DiscussionGroup $group, Post $post, GroupSessionContext $context, bool $canModerate): array
    {
        $mediaById = $this->postMediaService->albumMediaById($group);

        $page = [
            'labels' => $this->authorResolver->resolve([$post], $group->scoutYearId ?? $context->effectiveScoutYearId),
            'media' => $this->postMediaService->mediaForPosts([$post->id], $mediaById),
            'links' => $this->postLinkRepository->findForPosts([$post->id]),
            'replies' => [],
            'reply_counts' => [],
            'reactions' => ['counts' => [], 'own' => []],
            'reports' => ['reported' => [], 'counts' => []],
            // A post that did not exist a moment ago has been seen by
            // nobody, by definition — no query needed to say so.
            'seen_counts' => [$post->id => 0],
            'events' => $this->eventService?->summariesFor([$post->calendarEventId], $context->role) ?? [],
            // A post that did not exist a moment ago has no replies, so
            // none of them can be new either.
            'new_reply_counts' => [],
            // A poll created alongside this very post: fetched, not
            // assumed empty, because Controller\PostController::create()
            // attaches it before rendering the fragment groups.js inserts.
            'polls' => $this->pollService?->forPosts([$post->id], $context->userAccountId, $context->linkedMemberIds) ?? [],
        ];

        return $this->decorate($post, $page, $context, $canModerate);
    }

    /**
     * $page carries everything already resolved once for the whole page —
     * passed as one array rather than six positional parameters, which is
     * what kept this signature from growing a parameter per feature.
     *
     * @param array{
     *     labels: array<int, array{account_name: string, member_names: string[]}>,
     *     media: array<int, \Modules\Gallery\Api\DelegatedMedia[]>,
     *     links: array<int, \Modules\Groups\Repository\PostLink>,
     *     replies: array<int, array<int, array<string, mixed>>>,
     *     reply_counts: array<int, int>,
     *     reactions: array{counts: array<int, array<string, int>>, own: array<int, string>},
     *     reports: array{reported: array<int, true>, counts: array<int, int>},
     *     seen_counts?: array<int, int>,
     *     new_reply_counts?: array<int, int>,
     *     events?: array<int, \Modules\Calendar\Api\EventSummary>,
     *     polls?: array<int, array<string, mixed>>
     * } $page
     * @return array<string, mixed>
     */
    private function decorate(Post $post, array $page, GroupSessionContext $context, bool $canModerate): array
    {
        $identity = $page['labels'][$post->id] ?? ['account_name' => '', 'member_names' => []];
        $shownReplies = $page['replies'][$post->id] ?? [];
        $totalReplies = $page['reply_counts'][$post->id] ?? 0;

        return [
            'post' => $post,
            // Service\MemberIdentityService's shape, rendered by
            // partials/identity.html.twig — the account, then its
            // memberships.
            'identity' => $identity,
            'media' => $page['media'][$post->id] ?? [],
            'link' => $page['links'][$post->id] ?? null,
            'replies' => $shownReplies,
            'reply_count' => $totalReplies,
            // The cursor "Voir les commentaires précédents" continues
            // from, and it points BACKWARDS: the OLDEST reply on screen is
            // where the previous page resumes. Null when everything
            // already fits, which is also what hides the button.
            'replies_prev_before_id' => $totalReplies > count($shownReplies) && $shownReplies !== []
                ? $shownReplies[0]['reply']->id
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
            // Offered to everyone but the author, and only once: a second
            // report from the same member is refused by the UNIQUE index
            // anyway, so there is nothing to gain from showing the entry
            // again. This flag says only "you reported this" — never
            // whether anyone else did, nor what came of it.
            'can_report' => !isset($page['reports']['reported'][$post->id])
                && $post->authorUserAccountId !== $context->userAccountId,
            // Moderators only: everything about the hidden state stays
            // behind canModerate, so a member never learns an item exists
            // but is hidden.
            // "Vu par N", offered to the post's own author and nobody
            // else. A read mark says where somebody has been in a
            // conversation, and the one person with a legitimate claim on
            // that is the one asking "did my message reach anyone?" —
            // Controller\PostController::seenBy() enforces the same rule
            // server-side, this only decides whether the line is drawn.
            'can_see_seen_by' => $context->userAccountId !== null
                && $post->authorUserAccountId === $context->userAccountId,
            'seen_count' => $page['seen_counts'][$post->id] ?? 0,
            // How many comments arrived since this reader's last visit —
            // what decides both the thread's badge and whether it opens
            // itself (partials/post_card.html.twig).
            'new_reply_count' => $page['new_reply_counts'][$post->id] ?? 0,
            // Null whenever calendar is disabled, the event was deleted,
            // or this reader may not see the calendar it sits on — the
            // card then simply omits the line.
            'event' => $post->calendarEventId !== null
                ? ($page['events'][$post->calendarEventId] ?? null)
                : null,
            'poll' => $page['polls'][$post->id] ?? null,
            'is_hidden' => $canModerate && $post->isHidden(),
            'report_count' => $canModerate ? ($page['reports']['counts'][$post->id] ?? 0) : 0,
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
