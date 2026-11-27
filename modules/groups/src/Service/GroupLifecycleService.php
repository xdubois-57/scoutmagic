<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Core\Config\AppClock;
use Core\Config\ScoutYearService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Config\SettingService;
use Core\Journal\JournalService;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\GalleryException;
use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\Post;
use Modules\Groups\Repository\PostRepository;

/**
 * Automatic archiving and the two retention purges — everything that
 * happens to a group without anybody asking.
 *
 * A group is archived (issue #1350: one state, « Archivé », replacing the
 * old « Clôturé » next to « Année passée ») in three ways, and this
 * service owns the two automatic ones — after a stretch of inactivity,
 * and once its scout year is over. The third, by hand, is
 * Service\GroupMembershipService::archive(). Whatever the cause, the
 * archive date is stamped ONCE: no pass here selects a group that already
 * has one, so a daily task can never push back the purge that counts from
 * it.
 *
 * The three durations are settings, re-read on every run, and none of
 * them is configurable per group: a per-group override would let a
 * moderator quietly opt their group out of the retention bound, which is
 * the one thing a retention policy may not allow.
 *
 * Three properties every method here holds:
 *
 * - **Bounded.** Each pass takes a batch and returns; the daily task runs
 *   again tomorrow. A first run on an install with years of history must
 *   not try to delete thousands of posts — and their stored media, which
 *   is network I/O against an S3 bucket — inside one request.
 * - **Re-runnable after a partial failure.** Files go before rows,
 *   always: a crash mid-purge leaves a post whose media are already gone
 *   but whose row still exists, so the next run finds it again and
 *   finishes the job. The reverse order would leave the row deleted and
 *   the bytes orphaned in a bucket forever, with nothing left pointing at
 *   them — a retention failure, not an untidy detail.
 * - **UTC throughout.** Every cutoff is computed in UTC because that is
 *   how the columns are written (Support\Timestamps).
 */
class GroupLifecycleService
{
    public const SETTING_INACTIVITY_MONTHS = 'groups_inactivity_close_months';
    public const SETTING_POST_RETENTION_MONTHS = 'groups_post_retention_months';
    // The key keeps its historical name (no setting migration, #1350);
    // what it measures is now the time since the ARCHIVE date.
    public const SETTING_ARCHIVED_PURGE_MONTHS = 'groups_closed_purge_months';

    private const DEFAULT_INACTIVITY_MONTHS = 12;
    private const DEFAULT_POST_RETENTION_MONTHS = 24;
    private const DEFAULT_ARCHIVED_PURGE_MONTHS = 12;

    /**
     * How much one run may do. Deliberately modest: the expensive part of
     * a purge is not the SQL, it is deleting each media's renditions from
     * storage, which for an S3-backed album is a network round trip per
     * file.
     */
    private const BATCH_SIZE = 50;

    public function __construct(
        private GroupRepository $groupRepository,
        private PostRepository $postRepository,
        private PostMediaService $postMediaService,
        private PostLinkService $postLinkService,
        private ReplyService $replyService,
        private PostService $postService,
        private DelegatedAlbumManager $delegatedAlbumManager,
        private ScoutYearService $scoutYearService,
        private ScoutYearResolver $scoutYearResolver,
        private SettingService $settingService,
        private JournalService $journalService,
        private GroupArchiveRule $archiveRule
    ) {
    }

    /**
     * Archives every active group idle for longer than the configured
     * window.
     *
     * An archived group is read-only and stays **fully visible** to its
     * members — archiving is not hiding, and this is the whole reason
     * archiving and purge are two separate steps months apart.
     *
     * @return int groups archived in this pass
     */
    public function archiveInactive(?\DateTimeImmutable $now = null): int
    {
        $now ??= $this->now();
        $months = $this->months(self::SETTING_INACTIVITY_MONTHS, self::DEFAULT_INACTIVITY_MONTHS);
        $cutoff = $now->modify("-{$months} months")->format('Y-m-d H:i:s');

        $archived = 0;
        foreach ($this->groupRepository->findActiveInactiveBefore($cutoff, self::BATCH_SIZE) as $group) {
            // last_activity_at defaults to the creation instant and is
            // only ever bumped forward, so a group that has never held
            // anything is already measured from its creation date — which
            // is exactly what stops every freshly created section group
            // being archived on the very next nightly run.
            $this->groupRepository->setArchived($group->id, $now->format('Y-m-d H:i:s'));
            $this->journal('group_auto_archived', 'Groupe archivé automatiquement pour inactivité', [
                'group_id' => $group->id,
                'inactive_months' => $months,
            ]);
            $archived++;
        }

        return $archived;
    }

    /**
     * Stamps an archive date on every group whose scout year is over —
     * ended before the PUBLIC year started (Service\GroupArchiveRule).
     *
     * Such a group is already archived for every reader the moment the
     * public year changes (the rule's past-year branch); what this pass
     * adds is the DATE, which is what the purge counts from. Without it a
     * past-year group that was still active at the year change would
     * never reach the purge at all.
     *
     * Bounded like the other passes, and replayable: a group that already
     * has a date — archived by hand, for inactivity, or by an earlier run
     * of this one — is never selected again, so its date never moves.
     *
     * @return int groups archived in this pass
     */
    public function archiveEndedYears(?\DateTimeImmutable $now = null): int
    {
        $now ??= $this->now();

        $candidates = $this->groupRepository->findActiveOfYearsEndedBefore(
            $this->archiveRule->publicYearStartDate(),
            self::BATCH_SIZE
        );

        $archived = 0;
        foreach ($candidates as $group) {
            $this->groupRepository->setArchived($group->id, $now->format('Y-m-d H:i:s'));
            $this->journal('group_auto_archived_year_end', 'Groupe archivé automatiquement en fin d\'année scoute', [
                'group_id' => $group->id,
                'scout_year_id' => $group->scoutYearId,
            ]);
            $archived++;
        }

        return $archived;
    }

    /**
     * Deletes posts whose last activity is older than the retention
     * window, with everything hanging off them. Pinned posts are never
     * reachable here (PostRepository::findPurgeable() excludes them in
     * the SQL) at any age.
     *
     * @return int posts purged in this pass
     */
    public function purgePosts(?\DateTimeImmutable $now = null): int
    {
        $now ??= $this->now();
        $months = $this->months(self::SETTING_POST_RETENTION_MONTHS, self::DEFAULT_POST_RETENTION_MONTHS);
        $cutoff = $now->modify("-{$months} months")->format('Y-m-d H:i:s');

        $purged = 0;
        foreach ($this->postRepository->findPurgeable($cutoff, self::BATCH_SIZE) as $post) {
            $group = $this->groupRepository->findById($post->groupId);
            if ($group === null) {
                // The group went in the same window — its own purge takes
                // the post with it by CASCADE, so there is nothing to do
                // and nothing to log.
                continue;
            }

            $this->deletePostWithEverything($group, $post);
            $this->journal('group_post_purged', 'Message de groupe supprimé par la conservation', [
                'group_id' => $group->id,
                'post_id' => $post->id,
                'retention_months' => $months,
            ]);
            $purged++;
        }

        return $purged;
    }

    /**
     * Deletes groups archived longer ago than the configured window —
     * counted from the archive date, whatever archived them — with every
     * post, reply, reaction, report and media in them, and the delegated
     * gallery album itself.
     *
     * A group of the public year or of a later one is never purged (see
     * protectedScoutYearIds()), and a past-year group that has no date
     * yet waits for archiveEndedYears() to give it one.
     *
     * @return int groups purged in this pass
     */
    public function purgeArchivedGroups(?\DateTimeImmutable $now = null): int
    {
        $now ??= $this->now();
        $months = $this->months(self::SETTING_ARCHIVED_PURGE_MONTHS, self::DEFAULT_ARCHIVED_PURGE_MONTHS);
        $cutoff = $now->modify("-{$months} months")->format('Y-m-d H:i:s');

        $candidates = $this->groupRepository->findArchivedBefore(
            $cutoff,
            $this->protectedScoutYearIds(),
            self::BATCH_SIZE
        );

        $purged = 0;
        foreach ($candidates as $group) {
            $this->deleteGroupWithEverything($group);
            $this->journal('group_purged', 'Groupe supprimé par la conservation', [
                'group_id' => $group->id,
                'archived_purge_months' => $months,
            ]);
            $purged++;
        }

        return $purged;
    }

    /**
     * Scout years a group may never be purged from: the public one and
     * every later one.
     *
     * Without this, purging an archived section group of the current year
     * would have Service\SectionGroupSyncService recreate it on its very
     * next run — a delete/recreate loop, forever. Comparing by start_date
     * rather than by id because ids are insertion order, not chronology:
     * a year imported late would otherwise sort as "past".
     *
     * @return int[]
     */
    private function protectedScoutYearIds(): array
    {
        // The CONFIGURED public year, not the date-computed one: an admin
        // who pinned current_scout_year_id to something else would
        // otherwise have this protecting the wrong year, and the purge
        // would be free to delete the groups people are actually using.
        $current = $this->scoutYearResolver->getCurrentPublicYear();
        $currentStart = (string) $current['start_date'];

        $protected = [(int) $current['id']];
        foreach ($this->scoutYearService->getAll() as $year) {
            if ((string) $year['start_date'] >= $currentStart) {
                $protected[] = (int) $year['id'];
            }
        }

        return array_values(array_unique($protected));
    }

    /**
     * One post and everything that only it owns.
     *
     * The order is the one Controller\PostController::delete() already
     * established and is not arbitrary: everything living outside the
     * post row's own CASCADE reach goes first, while the rows pointing at
     * it still exist — its media, its link's cached image, and its
     * replies' images. The reply rows themselves, and every reaction,
     * report and debounce row on the post and on those replies, are
     * removed by the CASCADE.
     */
    private function deletePostWithEverything(DiscussionGroup $group, Post $post): void
    {
        $this->postMediaService->deleteAllForPost($group, $post->id);
        $this->postLinkService->deleteForPost($post->id);
        $this->replyService->deleteAllMediaForPost($group, $post->id);
        $this->postService->delete($post);
    }

    /**
     * One group and everything in it, including its delegated album.
     *
     * Every post is taken down individually first even though the group's
     * own CASCADE would remove all their rows: the CASCADE cannot reach
     * gallery's tables or the stored objects behind them, and deleting
     * the album alone would not remove a link preview's cached image,
     * which is a `files` row and not album media at all.
     */
    private function deleteGroupWithEverything(DiscussionGroup $group): void
    {
        foreach ($this->postRepository->findAllForGroup($group->id) as $post) {
            $this->deletePostWithEverything($group, $post);
        }

        if ($group->galleryAlbumId !== null) {
            try {
                $this->delegatedAlbumManager->deleteAlbum($group->galleryAlbumId);
            } catch (GalleryException) {
                // Already gone — the group's own removal must not be
                // blocked by an album that beat it to it. A re-run finds
                // no album and reaches the delete below.
            }
        }

        $this->groupRepository->delete($group->id);
    }

    /**
     * Floored at 1: a 0 or negative retention would purge everything on
     * the next run, which is never what somebody meant to type. Same
     * posture as Service\ReportService::threshold().
     */
    private function months(string $key, int $default): int
    {
        $raw = $this->settingService->get($key, 'groups', $default);
        $configured = is_numeric($raw) ? (int) $raw : $default;

        return max(1, $configured);
    }

    private function now(): \DateTimeImmutable
    {
        return AppClock::now();
    }

    /**
     * @param array<string, mixed> $context ids and durations only — never
     *        a group name, a message's text or a member (SECURITY.md §11)
     */
    private function journal(string $type, string $description, array $context): void
    {
        $this->journalService->log('groups', $type, 'info', $description, $context, null);
    }
}
