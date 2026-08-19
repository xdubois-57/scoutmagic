<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Notification\NotificationService;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\Reply;
use Modules\Groups\Support\ReportedAuthor;

/**
 * The module's four notification types, and everything that decides what
 * goes into one. All the plumbing — channels, member preferences,
 * quiet hours, batching, push — is core's (ARCHITECTURE.md §8.24); this
 * class only answers "who" and "what does it say".
 *
 * Four rules it exists to hold:
 *
 * - **Never the text of something hidden, reported or flagged.** A push
 *   notification lands on a lock screen, where it is readable by whoever
 *   is holding the phone and outlives the message it quotes. An item
 *   whose text was refused by moderation is never stored at all, and one
 *   that reports have hidden must not come back through a notification
 *   after the feed stopped showing it. excerptOf() is the single place
 *   that decides, and it returns nothing for a hidden item.
 * - **A reaction carries no excerpt at all**, hidden or not: there is
 *   nothing to quote. "Quelqu'un a réagi à votre message" is the whole
 *   content of that notification.
 * - **The title names the group.** A notification that says only
 *   "Nouveau message" is unactionable on a phone belonging to someone in
 *   five groups.
 * - **Never fatal.** Every method here is fire-and-forget from the
 *   caller's point of view: a notification that cannot be sent must never
 *   roll back a post that was published perfectly well. The
 *   NotificationService is nullable for the same reason it is nullable in
 *   Modules\Calendar (ARCHITECTURE.md §7.5), and every dispatch is
 *   wrapped.
 */
class GroupNotificationService
{
    public const TYPE_POST_PUBLISHED = 'groups.post_published';
    public const TYPE_REPLY_RECEIVED = 'groups.reply_received';
    public const TYPE_REACTION_RECEIVED = 'groups.reaction_received';
    public const TYPE_ITEM_REPORTED = 'groups.item_reported';

    /**
     * Bounds the excerpt carried in a title/body. Deliberately short: this
     * is a preview on a notification shelf, not the message — the deep
     * link is what opens the real thing.
     */
    private const EXCERPT_LENGTH = 120;

    public function __construct(
        private GroupRecipientResolver $recipientResolver,
        private ReactionNoticeThrottle $throttle,
        private ?NotificationService $notificationService = null
    ) {
    }

    /**
     * Type 1 — a new post, to everyone who can currently read the group.
     *
     * The actor stays in the recipient list on purpose: dispatch() already
     * suppresses their push, and having your own post appear in your own
     * centre is coherent for a "what happened in your groups" feed.
     */
    public function postPublished(DiscussionGroup $group, Post $post, int $effectiveScoutYearId): void
    {
        $this->send(
            self::TYPE_POST_PUBLISHED,
            fn(): array => $this->recipientResolver->forGroup($group, $effectiveScoutYearId),
            [
                'title' => 'Nouveau message — ' . $group->name,
                'body' => $this->excerptOf($post->body, $post->isHidden()),
                'url' => $this->urlFor($group->id, $post->id),
            ],
            $post->authorUserAccountId
        );
    }

    /**
     * Type 2 — someone replied to my post: the post's author only, and
     * never when they replied to themselves.
     */
    public function replyReceived(DiscussionGroup $group, Post $post, Reply $reply, int $effectiveScoutYearId): void
    {
        if ($reply->authorUserAccountId === $post->authorUserAccountId) {
            return;
        }

        $this->send(
            self::TYPE_REPLY_RECEIVED,
            fn(): array => $this->recipientsForAuthorOf($post, $group, $reply->authorUserAccountId, $effectiveScoutYearId),
            [
                'title' => 'Réponse à votre message — ' . $group->name,
                // The REPLY's text, and only if the reply itself is
                // visible — a reply hidden by reports must not reach the
                // author's phone with its text intact.
                'body' => $this->excerptOf($reply->body, $reply->isHidden() || $post->isHidden()),
                'url' => $this->urlFor($group->id, $post->id),
            ],
            $reply->authorUserAccountId
        );
    }

    /**
     * Type 3 — someone reacted to my post. Debounced per item
     * (Service\ReactionNoticeThrottle): a burst of reactions on one post
     * produces one notification, not one each.
     *
     * No excerpt, ever — a reaction has no content of its own to show, and
     * quoting the post back to the person who wrote it adds nothing.
     */
    public function reactionOnPost(DiscussionGroup $group, Post $post, int $actorUserAccountId, int $effectiveScoutYearId): void
    {
        if ($actorUserAccountId === $post->authorUserAccountId) {
            return;
        }
        if (!$this->throttle->shouldNotifyForPost($post->id)) {
            return;
        }

        $this->send(
            self::TYPE_REACTION_RECEIVED,
            fn(): array => $this->recipientsForAuthorOf($post, $group, $actorUserAccountId, $effectiveScoutYearId),
            [
                'title' => 'Réaction à votre message — ' . $group->name,
                'body' => 'Quelqu\'un a réagi à votre message.',
                'url' => $this->urlFor($group->id, $post->id),
            ],
            $actorUserAccountId
        );
    }

    public function reactionOnReply(
        DiscussionGroup $group,
        Post $post,
        Reply $reply,
        int $actorUserAccountId,
        int $effectiveScoutYearId
    ): void {
        if ($actorUserAccountId === $reply->authorUserAccountId) {
            return;
        }
        if (!$this->throttle->shouldNotifyForReply($reply->id)) {
            return;
        }

        $this->send(
            self::TYPE_REACTION_RECEIVED,
            fn(): array => $this->recipientsForMember(
                $reply->authorMemberId,
                $group,
                $actorUserAccountId,
                $effectiveScoutYearId
            ),
            [
                'title' => 'Réaction à votre réponse — ' . $group->name,
                'body' => 'Quelqu\'un a réagi à votre réponse.',
                'url' => $this->urlFor($group->id, $post->id),
            ],
            $actorUserAccountId
        );
    }

    /**
     * Type 4 — a member reported something: the group's moderators only.
     *
     * The body names neither the reporter nor the reported text. The
     * reporter is never revealed to anyone (module spec, prompt 9), and
     * the text is exactly what a report says might not belong on a
     * screen — a moderator opens the item to judge it, which is what the
     * deep link is for.
     */
    public function itemReported(
        DiscussionGroup $group,
        int $postId,
        string $kind,
        ?int $reporterAccountId,
        ?ReportedAuthor $author = null
    ): void {
        $escalated = $author !== null && $this->recipientResolver->isExplicitModerator($group, $author->memberId);

        $this->send(
            self::TYPE_ITEM_REPORTED,
            fn(): array => $this->recipientResolver->excluding(
                $escalated
                    ? $this->recipientResolver->moderatorsAndSiteAdminsFor($group)
                    : $this->recipientResolver->moderatorsFor($group),
                $this->excludedFromReport($reporterAccountId, $author)
            ),
            [
                'title' => ($escalated ? 'Contenu signalé (modérateur) — ' : 'Contenu signalé — ') . $group->name,
                'body' => $this->reportBody($kind, $escalated),
                'url' => $this->urlFor($group->id, $postId),
            ],
            $reporterAccountId
        );
    }

    /**
     * Who never receives a report notification: the reporter (it would
     * tell them the outcome, which prompt 9 forbids) and — always, whether
     * or not they moderate — the item's own AUTHOR.
     *
     * Notifying an author that their own message was reported would tell
     * them a report exists, which is exactly what a reporter is promised
     * will not happen; for a moderator-author it would additionally hand
     * the subject of the report the message asking somebody to judge it.
     *
     * @return int[]
     */
    private function excludedFromReport(?int $reporterAccountId, ?ReportedAuthor $author): array
    {
        $excluded = [];
        if ($reporterAccountId !== null) {
            $excluded[] = $reporterAccountId;
        }
        if ($author !== null) {
            $excluded[] = $author->userAccountId;
        }

        return $excluded;
    }

    private function reportBody(string $kind, bool $escalated): string
    {
        $item = $kind === 'reply' ? 'Une réponse' : 'Un message';
        $verb = $kind === 'reply' ? 'signalée' : 'signalé';

        if ($escalated) {
            // Said plainly, because it is the reason a site admin is
            // reading this at all: the group's own moderators cannot be
            // the only people judging a report about one of them.
            return $item . ' écrit' . ($kind === 'reply' ? 'e' : '') . ' par un modérateur de ce groupe a été '
                . $verb . ' et attend une relecture indépendante.';
        }

        return $item . ' de ce groupe a été ' . $verb . ' et attend votre relecture.';
    }
    /**
     * The post's author as a recipient list, minus the actor. Built from
     * the group's own audience rather than from the author's account id
     * alone, so an author who has since left the group is not notified
     * about a group they can no longer open — the deep link would 404 for
     * them.
     *
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function recipientsForAuthorOf(
        Post $post,
        DiscussionGroup $group,
        int $actorUserAccountId,
        int $effectiveScoutYearId
    ): array {
        return $this->recipientsForMember($post->authorMemberId, $group, $actorUserAccountId, $effectiveScoutYearId);
    }

    /**
     * One member as a recipient list — empty when they have no account,
     * when they are no longer in the group, or when they are the actor.
     *
     * Membership is re-read here rather than trusted from the stored row:
     * that is the "resolved at dispatch time" rule, and it is what stops
     * an author who left the section being told about replies to a post
     * they can no longer open.
     *
     * @return array<int, array{userAccountId: int, memberId: ?int}>
     */
    private function recipientsForMember(
        int $memberId,
        DiscussionGroup $group,
        int $actorUserAccountId,
        int $effectiveScoutYearId
    ): array {
        if (!$this->recipientResolver->isCurrentMember($group, $memberId, $effectiveScoutYearId)) {
            return [];
        }

        $accountId = $this->recipientResolver->accountIdForMember($memberId);
        if ($accountId === null || $accountId === $actorUserAccountId) {
            return [];
        }

        return [['userAccountId' => $accountId, 'memberId' => $memberId]];
    }

    /**
     * @param callable(): array<int, array{userAccountId: int, memberId: ?int}> $recipients
     *        resolved lazily, so a disabled notification service costs
     *        nothing — no membership queries, no role resolution
     * @param array{title: string, body: string, url?: ?string} $payload
     */
    private function send(string $typeId, callable $recipients, array $payload, ?int $actorUserAccountId): void
    {
        if ($this->notificationService === null) {
            return;
        }

        $resolved = $recipients();
        if ($resolved === []) {
            return;
        }

        try {
            $this->notificationService->dispatch($typeId, $resolved, $payload, $actorUserAccountId);
        } catch (\Throwable) {
            // A post that published perfectly well is never rolled back
            // because its notification failed. Core journals its own
            // failures; there is nothing useful to add here, and nothing
            // about the message's text may be logged (SECURITY.md §18).
        }
    }

    /**
     * The one place that decides whether a notification may quote an item.
     *
     * $suppressed is "this item is hidden": reports have taken it out of
     * the feed for everyone but the group's moderators, so its text must
     * not reappear on a phone. (An AI-flagged item never reaches here at
     * all — it is refused before anything is stored, prompt 9.)
     */
    private function excerptOf(string $body, bool $suppressed): string
    {
        if ($suppressed) {
            return 'Ce contenu a été masqué.';
        }

        $normalized = trim((string) preg_replace('/\s+/u', ' ', $body));
        if ($normalized === '') {
            return 'Message sans texte.';
        }

        return mb_strlen($normalized) > self::EXCERPT_LENGTH
            ? mb_substr($normalized, 0, self::EXCERPT_LENGTH - 1) . '…'
            : $normalized;
    }

    private function urlFor(int $groupId, int $postId): string
    {
        return '/groups/' . $groupId . '/posts/' . $postId;
    }
}
