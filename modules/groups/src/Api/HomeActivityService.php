<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Api;

use Core\Module\HomeGroupActivityProvider;
use Core\ScoutYear\ScoutYearSession;
use Core\Security\AuthSession;
use Modules\Groups\Service\GroupListItem;
use Modules\Groups\Service\GroupListService;
use Modules\Groups\Service\GroupSessionContextFactory;

/**
 * This module's answer to core's Core\Module\HomeGroupActivityProvider
 * hook: "which of your groups have something you have not seen yet".
 *
 * Lives under Api\ rather than Service\ for the same reason
 * Modules\Gallery\Api\* does (ARCHITECTURE.md §7.5): it is the surface
 * another part of the codebase consumes, not an internal collaborator of
 * this module. Everything it returns has already been through
 * Service\GroupListService, which is the batched form of the same
 * membership rules Service\GroupAccessService applies to a single group —
 * so a group the caller cannot read can never appear here, exactly as it
 * cannot appear on the group list itself.
 *
 * Reads the session directly, unlike every other service in this module.
 * That is deliberate and confined to this one class: the core hook is
 * called from a page that knows nothing about groups and has no
 * GroupSessionContext to hand it, and inventing a parameter for core to
 * fill in would mean core learning what a group membership is.
 */
class HomeActivityService implements HomeGroupActivityProvider
{
    public function __construct(
        private GroupListService $listService,
        private GroupSessionContextFactory $contextFactory
    ) {
    }

    /**
     * @return array<int, array{id: int, name: string, last_activity_at: string}>
     */
    public function getUnreadGroupsForCurrentUser(int $limit): array
    {
        $email = AuthSession::getEmail();
        if ($email === null || $email === '') {
            return [];
        }

        $context = $this->contextFactory->build(
            $email,
            AuthSession::getRole(),
            AuthSession::getUserAccountId(),
            ScoutYearSession::getPreviewId()
        );

        // findCurrent() already excludes archived (past-year) groups: a
        // read-only archive has nothing to catch up on, so surfacing it on
        // the homepage would be a call to action with nowhere to go.
        $unread = array_filter(
            $this->listService->findCurrent($context),
            fn(GroupListItem $item) => $item->hasUnread
        );

        // Most recently active first — the same ordering the group list
        // itself uses, so the two never disagree about which group is the
        // one worth opening.
        usort($unread, fn(GroupListItem $a, GroupListItem $b) => $b->group->lastActivityAt <=> $a->group->lastActivityAt);

        return array_map(
            fn(GroupListItem $item) => [
                'id' => $item->group->id,
                'name' => $item->group->name,
                'last_activity_at' => $item->group->lastActivityAt,
            ],
            array_slice($unread, 0, max(1, $limit))
        );
    }
}
