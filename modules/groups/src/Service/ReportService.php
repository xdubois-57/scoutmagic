<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Journal\JournalService;
use Core\Config\SettingService;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReportRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Support\Timestamps;

/**
 * Member reporting, the auto-hide threshold, and a moderator's two
 * answers to it: restore or delete.
 *
 * Three rules this class exists to hold:
 *
 * - **Hiding is the maximum automatic consequence.** Reaching the
 *   threshold hides an item from everyone but the group's moderators.
 *   Nothing is ever auto-deleted; a human decides that, always.
 * - **A restored item never auto-hides again.** The report count only
 *   grows, so recomputing visibility from it would let the next single
 *   report undo a moderator's decision. restore() persists
 *   moderation_cleared for exactly that reason, and shouldHide() honours it.
 * - **The reporter learns nothing.** No outcome is returned to them, and
 *   who reported an item is never exposed anywhere.
 *
 * Journalling is ids only — never the item's text, never the reporter's
 * or the author's name (module spec, and SECURITY.md §11).
 */
class ReportService
{
    public const SETTING_THRESHOLD = 'groups_report_hide_threshold';
    private const DEFAULT_THRESHOLD = 2;

    public function __construct(
        private ReportRepository $postReports,
        private ReportRepository $replyReports,
        private PostRepository $postRepository,
        private ReplyRepository $replyRepository,
        private SettingService $settingService,
        private JournalService $journalService
    ) {
    }

    /**
     * Records a report of a post and applies the threshold. Returns
     * false when this member had already reported it — the caller shows
     * the same neutral confirmation either way, so a repeat report is
     * never distinguishable from a first one.
     */
    public function reportPost(int $groupId, int $postId, int $reporterMemberId, ?int $actorAccountId = null): bool
    {
        if (!$this->postReports->record($postId, $reporterMemberId)) {
            return false;
        }

        $this->journal('group_post_reported', 'Message de groupe signalé', [
            'group_id' => $groupId,
            'post_id' => $postId,
        ], $actorAccountId);

        $post = $this->postRepository->findById($postId);
        if ($post !== null && $this->shouldHide($post->hiddenAt, $post->moderationCleared,
            $this->postReports->countFor($postId))) {
            $this->postRepository->setHiddenAt($postId, Timestamps::now());
            $this->journal('group_post_auto_hidden', 'Message de groupe masqué automatiquement', [
                'group_id' => $groupId,
                'post_id' => $postId,
                'report_count' => $this->postReports->countFor($postId),
            ], null);
        }

        return true;
    }

    /**
     * @see reportPost() — identical contract one level down.
     */
    public function reportReply(int $groupId, int $replyId, int $reporterMemberId, ?int $actorAccountId = null): bool
    {
        if (!$this->replyReports->record($replyId, $reporterMemberId)) {
            return false;
        }

        $this->journal('group_reply_reported', 'Réponse de groupe signalée', [
            'group_id' => $groupId,
            'reply_id' => $replyId,
        ], $actorAccountId);

        $reply = $this->replyRepository->findById($replyId);
        if ($reply !== null && $this->shouldHide($reply->hiddenAt, $reply->moderationCleared,
            $this->replyReports->countFor($replyId))) {
            $this->replyRepository->setHiddenAt($replyId, Timestamps::now());
            $this->journal('group_reply_auto_hidden', 'Réponse de groupe masquée automatiquement', [
                'group_id' => $groupId,
                'reply_id' => $replyId,
                'report_count' => $this->replyReports->countFor($replyId),
            ], null);
        }

        return true;
    }

    /**
     * A report about content written by one of the group's own moderators
     * is recorded separately from an ordinary one.
     *
     * Distinctly, because it is a different fact: an ordinary report says
     * a member objected to something, this one says the group's own
     * moderation is the subject of the objection — which is why the
     * notification escalates to site admins and why the author cannot
     * restore it themselves. A chief reading the journal should be able to
     * find those without inferring them from a member id.
     */
    public function journalEscalation(string $kind, int $groupId, int $itemId, ?int $actorAccountId = null): void
    {
        $isPost = $kind === 'post';
        $this->journal(
            $isPost ? 'group_post_report_escalated' : 'group_reply_report_escalated',
            $isPost
                ? 'Signalement escaladé : message écrit par un modérateur du groupe'
                : 'Signalement escaladé : réponse écrite par un modérateur du groupe',
            ['group_id' => $groupId, ($isPost ? 'post_id' : 'reply_id') => $itemId],
            $actorAccountId
        );
    }

    /**
     * A moderator clears a hidden post: visible again, and immune to
     * further auto-hiding.
     */
    public function restorePost(int $groupId, int $postId, ?int $actorAccountId): void
    {
        $this->postRepository->restore($postId);
        $this->journal('group_post_restored', 'Message de groupe rétabli par un modérateur', [
            'group_id' => $groupId,
            'post_id' => $postId,
        ], $actorAccountId);
    }

    public function restoreReply(int $groupId, int $replyId, ?int $actorAccountId): void
    {
        $this->replyRepository->restore($replyId);
        $this->journal('group_reply_restored', 'Réponse de groupe rétablie par un modérateur', [
            'group_id' => $groupId,
            'reply_id' => $replyId,
        ], $actorAccountId);
    }

    /**
     * Journalling for a moderator's deletion. The deletion itself stays
     * where it already was (Controller\PostController / ReplyController,
     * which own the media cleanup ordering); this only records that a
     * moderator made the call.
     */
    public function journalModeratorDeletion(string $kind, int $groupId, int $itemId, ?int $actorAccountId): void
    {
        $isPost = $kind === 'post';
        $this->journal(
            $isPost ? 'group_post_moderator_deleted' : 'group_reply_moderator_deleted',
            $isPost ? 'Message de groupe supprimé par un modérateur' : 'Réponse de groupe supprimée par un modérateur',
            ['group_id' => $groupId, ($isPost ? 'post_id' : 'reply_id') => $itemId],
            $actorAccountId
        );
    }

    /**
     * Everything a moderator has to look at in ONE group: the posts that
     * were reported, and the posts whose comments were.
     *
     * A conversation rather than a list of fragments — a reported comment
     * is judged inside the message it answers, and acting on it (hiding
     * it, clearing it, deleting it) already happens on its own card
     * there. Ordered by the id of the post so the list is stable, with
     * the report counts alongside for the page to show.
     *
     * @return array{post_ids: int[], post_counts: array<int, int>, reply_counts: array<int, int>}
     */
    public function reportedInGroup(int $groupId): array
    {
        $postCounts = $this->postReports->countsInGroup($groupId);
        $replyCounts = $this->replyReports->countsInGroup($groupId);

        $postIds = array_keys($postCounts);
        foreach ($this->replyRepository->postIdsForReplyIds(array_keys($replyCounts)) as $postId) {
            if (!in_array($postId, $postIds, true)) {
                $postIds[] = $postId;
            }
        }

        rsort($postIds);

        return ['post_ids' => $postIds, 'post_counts' => $postCounts, 'reply_counts' => $replyCounts];
    }

    /**
     * A moderator hides an item by hand, before any threshold.
     *
     * The threshold is what the site does on its own; this is what a
     * human does when one report is already enough — the judgement a
     * count cannot make. Nothing is deleted: hiding is still the maximum
     * consequence short of a deliberate deletion, and restore() undoes
     * exactly this.
     */
    public function hidePost(int $groupId, int $postId, ?int $actorAccountId): void
    {
        $this->postRepository->setHiddenAt($postId, Timestamps::now());
        $this->journal('group_post_hidden', 'Message de groupe masqué par un modérateur', [
            'group_id' => $groupId,
            'post_id' => $postId,
        ], $actorAccountId);
    }

    public function hideReply(int $groupId, int $replyId, ?int $actorAccountId): void
    {
        $this->replyRepository->setHiddenAt($replyId, Timestamps::now());
        $this->journal('group_reply_hidden', 'Réponse de groupe masquée par un modérateur', [
            'group_id' => $groupId,
            'reply_id' => $replyId,
        ], $actorAccountId);
    }

    /**
     * The configured threshold, floored at 1: a 0 or negative setting
     * would hide every item on its first report — almost certainly a
     * typo rather than an intent, and the kind of misconfiguration that
     * silently empties a group.
     */
    public function threshold(): int
    {
        $raw = $this->settingService->get(self::SETTING_THRESHOLD, 'groups', self::DEFAULT_THRESHOLD);
        // A blank or non-numeric setting means "never configured", which
        // is the default. A numeric one is honoured as written and only
        // then floored — so a deliberate 1 stays 1, and a 0 becomes 1
        // rather than silently jumping back up to the default.
        $configured = is_numeric($raw) ? (int) $raw : self::DEFAULT_THRESHOLD;

        return max(1, $configured);
    }

    /**
     * @param int[] $postIds
     * @param int[] $memberIds
     * @return array{reported: array<int, true>, counts: array<int, int>}
     */
    public function forPosts(array $postIds, array $memberIds): array
    {
        return [
            'reported' => $this->postReports->reportedByFor($postIds, $memberIds),
            'counts' => $this->postReports->countsFor($postIds),
        ];
    }

    /**
     * @param int[] $replyIds
     * @param int[] $memberIds
     * @return array{reported: array<int, true>, counts: array<int, int>}
     */
    public function forReplies(array $replyIds, array $memberIds): array
    {
        return [
            'reported' => $this->replyReports->reportedByFor($replyIds, $memberIds),
            'counts' => $this->replyReports->countsFor($replyIds),
        ];
    }

    /**
     * Already hidden → nothing to do. Already cleared by a moderator →
     * never again, however many further reports arrive. Otherwise, hide
     * at the threshold.
     */
    private function shouldHide(?string $hiddenAt, bool $moderationCleared, int $reportCount): bool
    {
        if ($hiddenAt !== null || $moderationCleared) {
            return false;
        }

        return $reportCount >= $this->threshold();
    }

    /**
     * @param array<string, mixed> $context ids and counts only — never the
     *        item's text, never a name (module spec, SECURITY.md §11)
     */
    private function journal(string $type, string $description, array $context, ?int $actorAccountId): void
    {
        $this->journalService->log('groups', $type, 'info', $description, $context, $actorAccountId);
    }
}
