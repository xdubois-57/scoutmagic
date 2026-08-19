<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Retention purge for individual posts, daily: a post is deleted
 * groups_post_retention_months after its LAST ACTIVITY — its publication,
 * a reply or a reaction — with its replies, reactions, reports, media and
 * cached link image, rows and stored objects alike.
 *
 * A pinned post is never purged, at any age: pinning is somebody
 * deliberately keeping it.
 *
 * One batch per run (Service\GroupLifecycleService), so the first run on
 * an install with years of history does not attempt thousands of
 * deletions — each of which is network I/O against a storage bucket — in
 * a single pass.
 */
class PurgePostsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_posts';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        GroupsTaskFactory::lifecycle($context)->purgePosts();

        GroupsTaskFactory::rescheduleDaily($context, self::TASK_KEY);
    }
}
