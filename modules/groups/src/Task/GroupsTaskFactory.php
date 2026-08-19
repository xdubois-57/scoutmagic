<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Task;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Import\MemberYearRepository;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedAlbumManagerFactory;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\GroupSectionRepository;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupLifecycleService;
use Modules\Groups\Service\PostLinkService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Service\RateLimitService;
use Modules\Groups\Service\ReplyService;
use Modules\Groups\Service\SectionGroupSyncService;

/**
 * Builds this module's lifecycle services from a TaskContext.
 *
 * Core\Scheduler\SchedulerRunner instantiates every handler with a bare
 * `new $handlerClass()`, so a handler has no constructor injection to
 * lean on and must assemble what it needs from $context. Four handlers
 * needing overlapping stacks would otherwise be four copies of the same
 * twenty lines, drifting apart the first time a constructor changed —
 * hence one place.
 *
 * The gallery half comes from gallery's own
 * Api\DelegatedAlbumManagerFactory rather than being reassembled here:
 * a consuming module has no business knowing gallery's internal
 * constructors (ARCHITECTURE.md §7.5).
 */
final class GroupsTaskFactory
{
    public static function lifecycle(TaskContext $context, ?DelegatedAlbumManager $albumManager = null): GroupLifecycleService
    {
        $pdo = $context->connection->getPdo();
        $albumManager ??= DelegatedAlbumManagerFactory::fromTaskContext($context);

        $groupRepository = new GroupRepository($pdo);
        $postRepository = new PostRepository($pdo);
        $replyRepository = new ReplyRepository($pdo);
        $fileRepository = new FileRepository($pdo);

        $postMediaService = new PostMediaService(
            $albumManager,
            new PostMediaRepository($pdo),
            $groupRepository,
            $replyRepository
        );

        return new GroupLifecycleService(
            $groupRepository,
            $postRepository,
            $postMediaService,
            // The link service is only ever asked to DELETE here, so its
            // fetch-side collaborators are the cheapest thing that
            // satisfies the constructor — nothing below reaches the
            // network on a deletion path.
            new PostLinkService(
                new NullLinkPreviewFetcher(),
                new \Modules\Groups\Service\LinkFetchThrottleService(
                    new \Modules\Groups\Repository\LinkFetchLogRepository($pdo)
                ),
                new PostLinkRepository($pdo),
                new UploadHandler($fileRepository, $context->storagePath),
                $fileRepository,
                $context->storagePath
            ),
            new ReplyService(
                $replyRepository,
                new GroupActivityService($groupRepository, $postRepository),
                $postMediaService,
                new RateLimitService(new \Modules\Groups\Repository\RateLimitRepository($pdo))
            ),
            new PostService(
                $postRepository,
                new GroupActivityService($groupRepository, $postRepository),
                new RateLimitService(new \Modules\Groups\Repository\RateLimitRepository($pdo))
            ),
            $albumManager,
            new ScoutYearService($pdo),
            self::scoutYearResolver($context),
            $context->settings,
            $context->journal
        );
    }

    /**
     * The CONFIGURED public year is what both the ensure task and the
     * purge protection have to agree on — see
     * Service\GroupLifecycleService::protectedScoutYearIds().
     */
    public static function scoutYearResolver(TaskContext $context): ScoutYearResolver
    {
        $pdo = $context->connection->getPdo();

        return new ScoutYearResolver(
            new ScoutYearService($pdo),
            $context->settings,
            new MemberYearRepository($pdo)
        );
    }

    public static function sectionGroupSync(TaskContext $context): SectionGroupSyncService
    {
        $pdo = $context->connection->getPdo();

        return new SectionGroupSyncService(
            new SectionService($context->connection, $context->encryption, new MemberBadgeRepository($pdo)),
            new GroupRepository($pdo),
            new GroupSectionRepository($pdo)
        );
    }

    /**
     * The daily self-reschedule every handler in this module ends with.
     * Core\Scheduler has no first-class recurring task, so each run books
     * its own successor — the same pattern as Core\Notification\Task\
     * PurgeNotificationsHandler and Core\Maintenance\Task\
     * AutoBackupHandler, and the reason a changed setting takes effect on
     * the next run rather than needing a restart.
     */
    public static function rescheduleDaily(TaskContext $context, string $taskKey): void
    {
        $scheduler = new SchedulerService(new SchedulerRepository($context->connection->getPdo()));
        $scheduler->scheduleAfter('groups', $taskKey, 86400, [], 'daily');
    }
}
