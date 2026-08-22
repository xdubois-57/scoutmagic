<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Api;

use Core\Module\HomeGroupActivityProvider;
use Core\Notification\NotificationRepository;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Modules\Groups\Repository\GroupReadRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReactionRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupListItem;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupNotificationService;
use Modules\Groups\Service\GroupSessionContextFactory;

/**
 * This module's answer to core's Core\Module\HomeGroupActivityProvider
 * hook: one banner-shaped summary of "what happened in your groups since
 * you last looked" — a count of active groups and three counters (new
 * posts, replies-or-reactions, mentions), never a per-group list.
 *
 * Lives under Api\ rather than Service\ for the same reason
 * Modules\Gallery\Api\* does (ARCHITECTURE.md §7.5): it is the surface
 * another part of the codebase consumes, not an internal collaborator of
 * this module. Group visibility goes through Service\GroupListService,
 * which is the batched form of the same membership rules
 * Service\GroupAccessService applies to a single group — so a group the
 * caller cannot read can never be counted here, exactly as it cannot
 * appear on the group list itself.
 *
 * Freshness comes from discussion_group_reads (the same last-read rows
 * the group list's own unread badge uses), so every counter goes back to
 * zero the moment the member actually opens the group — including the
 * mention counter, which is sourced from the caller's unread
 * groups.mentioned notifications but re-filtered against each group's
 * last-read time precisely so it cannot outlive a visit the way the bell
 * badge does.
 *
 * Reads the session directly, unlike every other service in this module.
 * That is deliberate and confined to this one class: the core hook is
 * called from a page that knows nothing about groups and has no
 * GroupSessionContext to hand it, and inventing a parameter for core to
 * fill in would mean core learning what a group membership is.
 */
class HomeActivityService implements HomeGroupActivityProvider
{
    /**
     * Counting stops after this many unread groups. The counters are a
     * "worth opening the list" signal, not bookkeeping — someone with
     * more unread groups than this has long stopped needing exact
     * numbers, and each group costs four COUNT queries.
     */
    private const MAX_GROUPS_COUNTED = 10;

    public function __construct(
        private GroupListService $listService,
        private GroupSessionContextFactory $contextFactory,
        private GroupReadRepository $readRepository,
        private PostRepository $postRepository,
        private ReplyRepository $replyRepository,
        private ReactionRepository $postReactions,
        private ReactionRepository $replyReactions,
        private ?NotificationRepository $notificationRepository = null
    ) {
    }

    public function getHomeActivitySummaryForCurrentUser(): ?array
    {
        $email = AuthSession::getEmail();
        if ($email === null || $email === '') {
            return null;
        }

        $context = $this->contextFactory->build(
            $email,
            AuthSession::getRole(),
            AuthSession::getUserAccountId(),
            ScoutYearSession::getPreviewId()
        );

        // findCurrent() already excludes archived (past-year) groups: a
        // read-only archive has nothing to catch up on, so counting it on
        // the homepage would be a call to action with nowhere to go.
        $unread = array_values(array_filter(
            $this->listService->findCurrent($context),
            fn(GroupListItem $item) => $item->hasUnread
        ));

        if ($unread === []) {
            return null;
        }

        // Most recently active first — so when the cap below kicks in,
        // the groups left uncounted are the least stale ones to miss.
        usort($unread, fn(GroupListItem $a, GroupListItem $b) => $b->group->lastActivityAt <=> $a->group->lastActivityAt);
        $counted = array_slice($unread, 0, self::MAX_GROUPS_COUNTED);

        $groupIds = array_map(fn(GroupListItem $item) => $item->group->id, $counted);
        $lastRead = $this->readRepository->lastReadFor($groupIds, $context->linkedMemberIds);

        // "Since when" per group: the member's own last visit, or the
        // group's creation for one never opened — hasUnread() upstream
        // guarantees such a group has had activity since then.
        $since = [];
        foreach ($counted as $item) {
            $since[$item->group->id] = $lastRead[$item->group->id] ?? $item->group->createdAt;
        }

        $own = $context->linkedMemberIds;
        $newPosts = 0;
        $repliesOrReactions = 0;
        foreach ($since as $groupId => $sinceAt) {
            $newPosts += $this->postRepository->countNewerForGroup($groupId, $sinceAt, $own);
            $repliesOrReactions += $this->replyRepository->countNewerForGroup($groupId, $sinceAt, $own)
                + $this->postReactions->countNewerForGroup($groupId, $sinceAt, $own)
                + $this->replyReactions->countNewerForGroup($groupId, $sinceAt, $own);
        }

        return [
            'group_count' => count($unread),
            'single_group' => count($unread) === 1
                ? ['id' => $unread[0]->group->id, 'name' => $unread[0]->group->name]
                : null,
            'new_posts' => $newPosts,
            'new_replies_or_reactions' => $repliesOrReactions,
            'new_mentions' => $this->countUnseenMentions($since),
        ];
    }

    /**
     * Mentions of the caller they have not seen yet: their unread
     * groups.mentioned notifications, kept only when the mention's group
     * is still unread AND the mention arrived after that group's
     * last-read time. The double filter is what makes this counter agree
     * with the other two about what "new" means — an unread bell row
     * about a group the member has since opened counts for nothing here.
     *
     * @param array<int, string> $since group id => count-from timestamp
     */
    private function countUnseenMentions(array $since): int
    {
        $accountId = AuthSession::getUserAccountId();
        if ($this->notificationRepository === null || $accountId === null) {
            return 0;
        }

        $mentions = 0;
        $rows = $this->notificationRepository->findUnreadOfType(
            $accountId,
            GroupNotificationService::TYPE_MENTIONED
        );
        foreach ($rows as $row) {
            if ($row['url'] === null || preg_match('#^/groups/(\d+)#', $row['url'], $m) !== 1) {
                continue;
            }
            $groupId = (int) $m[1];
            if (isset($since[$groupId]) && $row['created_at'] > $since[$groupId]) {
                $mentions++;
            }
        }

        return $mentions;
    }
}
