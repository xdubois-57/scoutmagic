<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Api;

use Core\Module\HomeGroupActivityProvider;
use Core\Notification\NotificationRepository;
use Core\Security\AuthSession;

/**
 * This module's answer to core's Core\Module\HomeGroupActivityProvider
 * hook: "how much is waiting for you in your groups".
 *
 * Lives under Api\ rather than Service\ for the same reason
 * Modules\Gallery\Api\* does (ARCHITECTURE.md §7.5): it is the surface
 * another part of the codebase consumes, not an internal collaborator of
 * this module.
 *
 * **Counted from the notification centre, not from the read marks.** The
 * three numbers are unread `notifications` rows of this module's own
 * types, which is deliberate on two grounds:
 *
 * - A mention has no other home. Mentions are resolved from the stored
 *   body every time it is rendered (Service\MentionService) and are
 *   never persisted, so "how many messages named me since I last looked"
 *   cannot be answered from `discussion_group_posts` without re-parsing
 *   every unread body — per group, on the one page every visitor loads.
 *   The dispatched `groups.mentioned` notification is the only record
 *   that a mention happened at all.
 * - It is what this block is for. The homepage summary exists precisely
 *   for the member the bell never reaches; counting the very rows the
 *   bell counts means the two can never disagree about what is waiting.
 *
 * The consequence to know: `read_at` is set from the notification centre,
 * not by opening a group (Core\Http\Controller\NotificationController) —
 * so reading a group does not by itself clear these numbers. That is the
 * same thing the bell already says, and making the two disagree would be
 * worse than either answer alone.
 *
 * Reads the session directly, unlike every other service in this module.
 * That is deliberate and confined to this one class: the core hook is
 * called from a page that knows nothing about groups and has no
 * GroupSessionContext to hand it, and inventing a parameter for core to
 * fill in would mean core learning what a group membership is.
 */
class HomeActivityService implements HomeGroupActivityProvider
{
    private const TYPE_POST = 'groups.post_published';
    private const TYPE_REPLY = 'groups.reply_received';
    private const TYPE_REACTION = 'groups.reaction_received';
    private const TYPE_MENTION = 'groups.mentioned';

    public function __construct(
        private NotificationRepository $notificationRepository
    ) {
    }

    /**
     * @return array{posts: int, activity: int, mentions: int}
     */
    public function getUnreadActivitySummaryForCurrentUser(): array
    {
        $empty = ['posts' => 0, 'activity' => 0, 'mentions' => 0];

        $userAccountId = AuthSession::getUserAccountId();
        if ($userAccountId === null) {
            return $empty;
        }

        $counts = $this->notificationRepository->countUnreadByTypes($userAccountId, [
            self::TYPE_POST,
            self::TYPE_REPLY,
            self::TYPE_REACTION,
            self::TYPE_MENTION,
        ]);

        return [
            'posts' => $counts[self::TYPE_POST],
            // Replies and reactions are one number on purpose: both mean
            // "the conversation moved", and a member decides whether to
            // open the group on that, not on which of the two it was.
            'activity' => $counts[self::TYPE_REPLY] + $counts[self::TYPE_REACTION],
            'mentions' => $counts[self::TYPE_MENTION],
        ];
    }
}
