<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Gallery\Api\DelegatedMedia;
use Modules\Groups\Repository\Reply;

/**
 * Turns Repository\Reply rows into the rows the templates render: author
 * labels, the optional image, the reaction summary, and which actions this
 * viewer may take.
 *
 * It exists as its own service rather than as a private method of
 * Service\GroupFeedService because two entry points need exactly this:
 * the feed page (the first few replies under each post) and
 * Controller\ReplyController's own "Charger plus" endpoint. Two copies
 * would be two chances for the two to disagree about, say, whether the
 * edit window is still open.
 *
 * Every can_* flag here only decides what the UI offers — each action
 * re-checks its own rule server-side.
 */
class ReplyPresenter
{
    public function __construct(
        private PostAuthorResolver $authorResolver,
        private ReplyService $replyService,
        private ReactionService $reactionService,
        private ReportService $reportService
    ) {
    }

    /**
     * @param Reply[] $replies
     * @param array<int, DelegatedMedia> $mediaById the group album's media,
     *        already fetched once by the caller (Service\PostMediaService::
     *        albumMediaById()) — never re-fetched per reply
     * @return array<int, array<string, mixed>>
     */
    public function decorate(
        array $replies,
        GroupSessionContext $context,
        bool $canModerate,
        int $scoutYearId,
        array $mediaById
    ): array {
        if ($replies === []) {
            return [];
        }

        // A fixed handful of batched queries for the whole set of
        // replies, never one per reply — same no-N+1 contract as the
        // feed's own author labels.
        $labels = $this->authorResolver->resolve($replies, $scoutYearId);
        $replyIds = array_map(fn(Reply $r) => $r->id, $replies);
        $reactions = $this->reactionService->forReplies($replyIds, $context->linkedMemberIds);
        $reports = $this->reportService->forReplies($replyIds, $context->linkedMemberIds);

        return array_map(
            fn(Reply $reply) => [
                'reply' => $reply,
                'identity' => $labels[$reply->id] ?? ['account_name' => '', 'member_names' => []],
                // A media id present on the reply but absent from the
                // album listing (a race with a concurrent delete) renders
                // as no image rather than breaking the whole reply.
                'media' => $reply->galleryMediaId !== null ? ($mediaById[$reply->galleryMediaId] ?? null) : null,
                'reactions' => ReactionSummary::build(
                    $reactions['counts'][$reply->id] ?? [],
                    $reactions['own'][$reply->id] ?? null
                ),
                'can_edit' => $this->replyService->canEdit($reply, $context),
                'can_delete' => $this->replyService->canDelete($reply, $context, $canModerate),
                // Same contract as a post's: offered to everyone but the
                // author, and only until they have used it. It says
                // nothing about anyone else's reports or their outcome.
                'can_report' => !isset($reports['reported'][$reply->id])
                    && !in_array($reply->authorMemberId, $context->linkedMemberIds, true),
                // The hidden state and the count are moderator-only, so a
                // member never learns a reply exists but is hidden.
                'is_hidden' => $canModerate && $reply->isHidden(),
                'report_count' => $canModerate ? ($reports['counts'][$reply->id] ?? 0) : 0,
            ],
            $replies
        );
    }
}
