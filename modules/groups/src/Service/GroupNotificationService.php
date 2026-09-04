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
 * The module's notification types, and everything that decides what
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
    public const TYPE_MENTIONED = 'groups.mentioned';
    public const TYPE_ITEM_REPORTED = 'groups.item_reported';
    public const TYPE_INVITED = 'groups.invited';

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
     * Type 1 — a new post, to everyone who can currently read the group
     * EXCEPT its author.
     *
     * This used to keep the actor in the list, on the reading that a
     * "what happened in your groups" feed is coherent with your own post
     * in it. It is not: NotificationService::dispatch() only suppresses
     * the actor's push and email, so the in-app row was still written —
     * and that row is what feeds the unread badge and the home page's
     * « Du nouveau dans vos groupes ». A member publishing a message was
     * therefore told, by the site, that there was something new for them
     * to read, about the thing they had just written themselves.
     *
     * The exclusion belongs HERE and not in dispatch(): core's rule is
     * shared with the calendar, the gallery, the camps and the finances,
     * where an actor's own row can be exactly what is wanted. This is the
     * only type of this module whose audience can contain its own actor —
     * replyReceived(), the two reactions, mentioned() and memberInvited()
     * each already drop the actor where they resolve their recipients,
     * and itemReported() excludes both the reporter and the author.
     */
    public function postPublished(DiscussionGroup $group, Post $post, int $effectiveScoutYearId): void
    {
        $this->send(
            self::TYPE_POST_PUBLISHED,
            fn(): array => $this->recipientResolver->forGroupExcluding(
                $group,
                $effectiveScoutYearId,
                [$post->authorUserAccountId]
            ),
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
            fn(): array => $this->recipientsForAuthorOf($post, $group, $reply->authorUserAccountId,
                $effectiveScoutYearId),
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
    public function reactionOnPost(
        DiscussionGroup $group,
        Post $post,
        int $actorUserAccountId,
        int $effectiveScoutYearId
    ): void
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
     * Type 5 — somebody wrote your name after an "@".
     *
     * Deliberately a type of its own even though a new post already
     * notifies the whole group: the two say different things, and the
     * point of separating them is the preferences page. A member who
     * mutes "nouveau message" because their section group is busy still
     * wants to hear it when somebody is actually talking to them. It
     * earns its keep most on a REPLY, which otherwise reaches only the
     * post's author — a question aimed at somebody three replies down a
     * thread reached nobody before this.
     *
     * $memberIds comes from Service\MentionService, which read them back
     * out of the stored body. Each is re-checked as a current member of
     * the group by recipientsForMember() below, so a name that resolved
     * at write time still cannot notify somebody who has since left.
     *
     * @param int[] $memberIds
     */
    public function mentioned(
        DiscussionGroup $group,
        int $postId,
        array $memberIds,
        string $body,
        bool $suppressed,
        int $actorUserAccountId,
        int $effectiveScoutYearId
    ): void {
        if ($memberIds === []) {
            return;
        }

        $this->send(
            self::TYPE_MENTIONED,
            function () use ($memberIds, $group, $actorUserAccountId, $effectiveScoutYearId): array {
                $recipients = [];
                foreach ($memberIds as $memberId) {
                    foreach ($this->recipientsForMember(
                        $memberId,
                        $group,
                        $actorUserAccountId,
                        $effectiveScoutYearId
                    ) as $recipient) {
                        $recipients[] = $recipient;
                    }
                }

                // One account linked to two mentioned members of the same
                // group is still one notification, the same rule
                // GroupRecipientResolver holds for every other audience.
                return array_values(array_column($recipients, null, 'userAccountId'));
            },
            [
                'title' => 'Vous êtes cité — ' . $group->name,
                // Same excerpt rule as everything else here: a hidden
                // item's text never reaches a lock screen, whoever was
                // named in it.
                'body' => $this->excerptOf($body, $suppressed),
                'url' => $this->urlFor($group->id, $postId),
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
        $escalated = $author !== null && $this->recipientResolver->isExplicitModeratorAccount($group,
            $author->userAccountId);

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
     * "You have been invited" — sent only when the moderator doing the
     * inviting asked for it (Controller\GroupMemberController).
     *
     * An invitation notifies nobody by itself: filling a group in one
     * sitting would otherwise buzz twenty phones about a group they will
     * find on their own. The checkbox is per invitation, and unticked is
     * the default.
     *
     * Every login that reaches the invited member is told, not just the
     * one their mail happens to resolve to first: this is an invitation
     * to a place, and any of their addresses can walk into it.
     */
    public function memberInvited(DiscussionGroup $group, int $memberId, ?int $actorUserAccountId): void
    {
        $this->send(
            self::TYPE_INVITED,
            function () use ($memberId, $actorUserAccountId): array {
                $recipients = [];
                foreach ($this->recipientResolver->accountIdsForMember($memberId) as $accountId) {
                    if ($accountId !== $actorUserAccountId) {
                        $recipients[] = ['userAccountId' => $accountId, 'memberId' => $memberId];
                    }
                }

                return $recipients;
            },
            [
                'title' => 'Invitation — ' . $group->name,
                // No excerpt to give and nothing about the group's
                // contents: an invitation says where, never what is being
                // said there.
                'body' => 'Vous avez été invité·e à rejoindre ce groupe de discussion.',
                'url' => '/groups/' . $group->id,
            ],
            $actorUserAccountId
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
