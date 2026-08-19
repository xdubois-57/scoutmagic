<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Support\Timestamps;

/**
 * The single writer of last_activity_at, on both the group and the post.
 *
 * Written once, here, because everything that counts as activity — a new
 * post, a reply, a reaction — bumps the same two columns in the same way,
 * and a second implementation would be a second answer to "when was this
 * group last alive?", which the group list orders on and the later purge
 * measures.
 *
 * Editing is deliberately NOT activity, for a post or a reply:
 * correcting a typo on an old post must not resurrect it to the top of
 * the feed. A reaction IS activity, which also means it postpones that
 * post's eventual purge — intended, not an oversight: a post people are
 * still reacting to is not dormant.
 */
class GroupActivityService
{
    public function __construct(
        private GroupRepository $groupRepository,
        private PostRepository $postRepository
    ) {
    }

    /**
     * Marks activity on a post and, with it, on its group. $at defaults to
     * now (UTC) — callers pass one only to keep a batch consistent.
     */
    public function bump(int $groupId, ?int $postId = null, ?string $at = null): void
    {
        $at ??= Timestamps::now();

        if ($postId !== null) {
            $this->postRepository->touchActivity($postId, $at);
        }

        $this->groupRepository->touchActivity($groupId, $at);
    }
}
