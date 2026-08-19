<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\LinkFetchLogRepository;
use Modules\Groups\Repository\PostLinkRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\RateLimitRepository;
use Modules\Groups\Repository\ReplyRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\GroupLifecycleService;
use Modules\Groups\Service\PostLinkService;
use Modules\Groups\Service\PostMediaService;
use Modules\Groups\Service\PostService;
use Modules\Groups\Service\RateLimitService;
use Modules\Groups\Service\ReplyService;
use Modules\Groups\Task\NullLinkPreviewFetcher;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Automatic closure and the two retention purges.
 *
 * The album manager is a recording fake rather than a mock: what these
 * tests need to assert is not "was a method called" but "is anything left
 * behind" — an orphaned media or link image after its post is gone is a
 * retention failure, and the only way to see it is to track what was
 * actually deleted.
 *
 * @group database
 */
#[Group('database')]
class GroupLifecycleServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private PostRepository $postRepo;
    private ReplyRepository $replyRepo;
    private string $storagePath;
    private int $currentYearId;

    /** @var int[] media ids the album manager was asked to delete */
    private array $deletedMedia = [];
    /** @var int[] album ids the album manager was asked to delete */
    private array $deletedAlbums = [];
    /** @var int[] media ids still "in" the fake album */
    private array $albumMedia = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->replyRepo = new ReplyRepository($this->pdo);
        $this->storagePath = sys_get_temp_dir() . '/groups_lifecycle_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0o777, true);

        $this->currentYearId = GroupsTestHelper::createScoutYear($this->pdo, '2025-2026', true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath);
    }

    // ---- closure ----

    public function testAGroupIdleLongerThanTheWindowIsClosed(): void
    {
        $groupId = $this->group('Louveteaux', lastActivity: '-18 months');

        $this->assertSame(1, $this->service()->closeInactive());
        $this->assertTrue($this->groupRepo->findById($groupId)->isClosed());
    }

    public function testAGroupStillInsideTheWindowIsLeftOpen(): void
    {
        $groupId = $this->group('Louveteaux', lastActivity: '-3 months');

        $this->assertSame(0, $this->service()->closeInactive());
        $this->assertFalse($this->groupRepo->findById($groupId)->isClosed());
    }

    /**
     * The pitfall this rule exists for: last_activity_at defaults to the
     * creation instant, so a group with no content at all is measured
     * from when it was created — not from epoch, which would close every
     * freshly created section group on the very next nightly run.
     */
    public function testAFreshlyCreatedEmptyGroupIsNotClosedOnTheNextRun(): void
    {
        $groupId = $this->groupRepo->create('Louveteaux', $this->currentYearId, null, null);

        $this->assertSame(0, $this->service()->closeInactive());
        $this->assertFalse($this->groupRepo->findById($groupId)->isClosed());
    }

    public function testClosingIsIdempotent(): void
    {
        $groupId = $this->group('Louveteaux', lastActivity: '-18 months');
        $service = $this->service();

        $this->assertSame(1, $service->closeInactive());
        $closedAt = $this->groupRepo->findById($groupId)->closedAt;

        // The second run finds nothing left to close, and in particular
        // does not re-stamp the closure date — which the purge counts from.
        $this->assertSame(0, $service->closeInactive());
        $this->assertSame($closedAt, $this->groupRepo->findById($groupId)->closedAt);
    }

    public function testAnAlreadyClosedGroupIsNeverTouchedAgain(): void
    {
        $groupId = $this->group('Louveteaux', lastActivity: '-18 months');
        $this->groupRepo->setClosed($groupId, '2020-01-01 00:00:00');

        $this->assertSame(0, $this->service()->closeInactive());
        $this->assertSame('2020-01-01 00:00:00', $this->groupRepo->findById($groupId)->closedAt);
    }

    public function testTheClosureWindowIsReadFromTheSetting(): void
    {
        $groupId = $this->group('Louveteaux', lastActivity: '-4 months');
        $this->setSetting(GroupLifecycleService::SETTING_INACTIVITY_MONTHS, '3');

        $this->assertSame(1, $this->service()->closeInactive());
        $this->assertTrue($this->groupRepo->findById($groupId)->isClosed());
    }

    public function testAZeroWindowIsFlooredAtOneMonthRatherThanClosingEverything(): void
    {
        $groupId = $this->group('Louveteaux', lastActivity: '-2 days');
        $this->setSetting(GroupLifecycleService::SETTING_INACTIVITY_MONTHS, '0');

        $this->assertSame(0, $this->service()->closeInactive());
        $this->assertFalse($this->groupRepo->findById($groupId)->isClosed());
    }

    // ---- post purge ----

    public function testAPostPastItsRetentionIsDeleted(): void
    {
        $groupId = $this->group('Louveteaux');
        $postId = $this->post($groupId, 'vieux message', '-30 months');

        $this->assertSame(1, $this->service()->purgePosts());
        $this->assertNull($this->postRepo->findById($postId));
    }

    /**
     * Measured from LAST ACTIVITY, not from creation: an old post that a
     * reply or a reaction kept alive is not stale.
     */
    public function testAnOldPostWithRecentActivitySurvives(): void
    {
        $groupId = $this->group('Louveteaux');
        $postId = $this->post($groupId, 'vieux mais actif', '-40 months');
        $this->postRepo->touchActivity($postId, $this->at('-1 month'));

        $this->assertSame(0, $this->service()->purgePosts());
        $this->assertNotNull($this->postRepo->findById($postId));
    }

    /**
     * Pinning is somebody deliberately keeping a post. It is excluded in
     * the SQL, so the purge cannot reach it at any age.
     */
    public function testAPinnedPostIsNeverPurgedHoweverOldItIs(): void
    {
        $groupId = $this->group('Louveteaux');
        $pinned = $this->post($groupId, 'épinglé', '-120 months', pinned: true);
        $ordinary = $this->post($groupId, 'ordinaire', '-120 months');

        $this->assertSame(1, $this->service()->purgePosts());
        $this->assertNotNull($this->postRepo->findById($pinned));
        $this->assertNull($this->postRepo->findById($ordinary));
    }

    public function testPurgingAPostTakesItsRepliesReactionsAndReportsWithIt(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $groupId = $this->group('Louveteaux');
        $postId = $this->post($groupId, 'vieux', '-30 months');
        $replyId = GroupsTestHelper::createReplyAt($this->pdo, $postId, 'réponse', '2024-01-01 10:00:00');
        $this->pdo->prepare('INSERT INTO discussion_group_post_reactions (post_id, member_id, reaction_key) VALUES (?, ?, ?)')
            ->execute([$postId, 3, 'heart']);
        $this->pdo->prepare('INSERT INTO discussion_group_reply_reactions (reply_id, member_id, reaction_key) VALUES (?, ?, ?)')
            ->execute([$replyId, 3, 'clap']);
        $this->pdo->prepare('INSERT INTO discussion_group_post_reports (post_id, reporter_member_id) VALUES (?, ?)')
            ->execute([$postId, 4]);

        $this->service()->purgePosts();

        $this->assertSame(0, $this->rowCount('discussion_group_replies'));
        $this->assertSame(0, $this->rowCount('discussion_group_post_reactions'));
        $this->assertSame(0, $this->rowCount('discussion_group_reply_reactions'));
        $this->assertSame(0, $this->rowCount('discussion_group_post_reports'));
    }

    /**
     * The rule that makes the whole purge honest: rows are not enough.
     */
    public function testPurgingAPostDeletesItsMediaFromStorageNotJustItsRows(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $groupId = $this->group('Louveteaux', albumId: 55);
        $postId = $this->post($groupId, 'avec photos', '-30 months');
        $this->attachMedia($postId, 11);
        $this->attachMedia($postId, 12);
        $replyMediaId = 13;
        GroupsTestHelper::createReplyAt($this->pdo, $postId, 'réponse', '2024-01-01 10:00:00', 7, 3, $replyMediaId);
        $this->albumMedia = [11, 12, 13];

        $this->service()->purgePosts();

        sort($this->deletedMedia);
        $this->assertSame([11, 12, 13], $this->deletedMedia);
        $this->assertSame([], $this->albumMedia, 'nothing may be left in the album');
        $this->assertSame(0, $this->rowCount('discussion_group_post_media'));
    }

    public function testPurgingAPostDeletesItsCachedLinkImageRowAndFile(): void
    {
        // The link ROW goes by CASCADE from the post; only the file and
        // its `files` entry need explicit deletion — so this test needs
        // SQLite's foreign keys on to observe both halves at once.
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $groupId = $this->group('Louveteaux');
        $postId = $this->post($groupId, 'avec un lien', '-30 months');
        [$fileId, $absolutePath] = $this->attachLinkWithImage($postId);

        $this->assertFileExists($absolutePath);

        $this->service()->purgePosts();

        $this->assertFileDoesNotExist($absolutePath, 'the cached OG image must leave the disk, not just the table');
        $this->assertNull((new FileRepository($this->pdo))->findById($fileId));
        $this->assertSame(0, $this->rowCount('discussion_group_post_links'));
    }

    /**
     * A crash after the media were deleted but before the row was: the
     * next run must find the post again and finish, not skip it.
     */
    public function testARerunAfterAPartialPostPurgeFinishesTheJob(): void
    {
        $groupId = $this->group('Louveteaux', albumId: 55);
        $postId = $this->post($groupId, 'avec photos', '-30 months');
        $this->attachMedia($postId, 11);
        $this->albumMedia = [11];

        // Simulates the crash: media gone, post row still there.
        $group = $this->groupRepo->findById($groupId);
        $this->mediaService()->deleteAllForPost($group, $postId);
        $this->assertNotNull($this->postRepo->findById($postId));

        $this->assertSame(1, $this->service()->purgePosts());
        $this->assertNull($this->postRepo->findById($postId));
    }

    public function testPurgingPostsIsIdempotent(): void
    {
        $groupId = $this->group('Louveteaux');
        $this->post($groupId, 'vieux', '-30 months');
        $service = $this->service();

        $this->assertSame(1, $service->purgePosts());
        $this->assertSame(0, $service->purgePosts());
    }

    public function testEachRunIsBoundedSoALargeBacklogIsSpreadOverSeveralRuns(): void
    {
        $groupId = $this->group('Louveteaux');
        for ($i = 0; $i < 60; $i++) {
            $this->post($groupId, "vieux {$i}", '-30 months');
        }
        $service = $this->service();

        // One batch, not all sixty — the expensive part of a purge is
        // deleting stored objects, which is network I/O per file.
        $this->assertSame(50, $service->purgePosts());
        $this->assertSame(10, $service->purgePosts());
        $this->assertSame(0, $service->purgePosts());
    }

    // ---- closed-group purge ----

    public function testAGroupClosedLongerAgoThanTheWindowIsPurged(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->group('Ancien', scoutYearId: $pastYearId);
        $this->groupRepo->setClosed($groupId, $this->at('-18 months'));

        $this->assertSame(1, $this->service()->purgeClosedGroups());
        $this->assertNull($this->groupRepo->findById($groupId));
    }

    public function testAGroupClosedRecentlyIsKept(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->group('Ancien', scoutYearId: $pastYearId);
        $this->groupRepo->setClosed($groupId, $this->at('-2 months'));

        $this->assertSame(0, $this->service()->purgeClosedGroups());
        $this->assertNotNull($this->groupRepo->findById($groupId));
    }

    public function testAnOpenGroupIsNeverPurgedHoweverOldItIs(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->group('Ancien', scoutYearId: $pastYearId, lastActivity: '-120 months');

        $this->assertSame(0, $this->service()->purgeClosedGroups());
        $this->assertNotNull($this->groupRepo->findById($groupId));
    }

    /**
     * The rule that replaces a tombstone table: purging a closed group of
     * the current year would have the ensure-section-groups task recreate
     * it the same night, forever.
     */
    public function testAGroupOfTheCurrentYearIsNeverPurged(): void
    {
        $groupId = $this->group('Louveteaux', scoutYearId: $this->currentYearId);
        $this->groupRepo->setClosed($groupId, $this->at('-36 months'));

        $this->assertSame(0, $this->service()->purgeClosedGroups());
        $this->assertNotNull($this->groupRepo->findById($groupId));
    }

    public function testAGroupOfAFutureYearIsNeverPurgedEither(): void
    {
        $nextYearId = GroupsTestHelper::createScoutYear($this->pdo, '2026-2027', false);
        $groupId = $this->group('L\'an prochain', scoutYearId: $nextYearId);
        $this->groupRepo->setClosed($groupId, $this->at('-36 months'));

        $this->assertSame(0, $this->service()->purgeClosedGroups());
        $this->assertNotNull($this->groupRepo->findById($groupId));
    }

    /**
     * A year-less invitation group belongs to no year, so the
     * current-year protection cannot apply to it — and a NOT IN over a
     * NULL column would silently discard it, which is why the query
     * spells the NULL case out.
     */
    public function testAYearLessGroupIsStillPurgeable(): void
    {
        $groupId = $this->group('Groupe de travail', scoutYearId: null);
        $this->groupRepo->setClosed($groupId, $this->at('-18 months'));

        $this->assertSame(1, $this->service()->purgeClosedGroups());
        $this->assertNull($this->groupRepo->findById($groupId));
    }

    public function testPurgingAGroupDeletesItsDelegatedAlbum(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->group('Ancien', scoutYearId: $pastYearId, albumId: 55);
        $this->groupRepo->setClosed($groupId, $this->at('-18 months'));

        $this->service()->purgeClosedGroups();

        $this->assertSame([55], $this->deletedAlbums);
    }

    public function testPurgingGroupsIsIdempotent(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->group('Ancien', scoutYearId: $pastYearId);
        $this->groupRepo->setClosed($groupId, $this->at('-18 months'));
        $service = $this->service();

        $this->assertSame(1, $service->purgeClosedGroups());
        $this->assertSame(0, $service->purgeClosedGroups());
    }

    /**
     * A crash after the album was deleted but before the group row was:
     * the re-run finds the album already gone and still finishes.
     */
    public function testARerunAfterAPartialGroupPurgeFinishesTheJob(): void
    {
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->group('Ancien', scoutYearId: $pastYearId, albumId: 55);
        $this->groupRepo->setClosed($groupId, $this->at('-18 months'));
        // The album is already gone — deleteAlbum() will throw.
        $this->albumMedia = [];

        $this->assertSame(1, $this->service(albumMissing: true)->purgeClosedGroups());
        $this->assertNull($this->groupRepo->findById($groupId));
    }

    // ---- full lifecycle ----

    /**
     * Create → post with media and a link → close → purge, ending with
     * nothing left anywhere: no row, no file on disk, no object in the
     * album.
     */
    public function testTheFullLifecycleLeavesNothingBehind(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $pastYearId = GroupsTestHelper::createScoutYear($this->pdo, '2020-2021', false);
        $groupId = $this->group('Ancien', scoutYearId: $pastYearId, albumId: 55);

        $postId = $this->post($groupId, 'avec tout', '-1 month');
        $this->attachMedia($postId, 11);
        $replyId = GroupsTestHelper::createReplyAt($this->pdo, $postId, 'réponse', '2024-01-01 10:00:00', 7, 3, 12);
        $this->albumMedia = [11, 12];
        [$fileId, $absolutePath] = $this->attachLinkWithImage($postId);
        $this->pdo->prepare('INSERT INTO discussion_group_post_reactions (post_id, member_id, reaction_key) VALUES (?, ?, ?)')
            ->execute([$postId, 3, 'heart']);
        $this->pdo->prepare('INSERT INTO discussion_group_reply_reports (reply_id, reporter_member_id) VALUES (?, ?)')
            ->execute([$replyId, 4]);

        // Closure, then the purge months later.
        $this->groupRepo->setClosed($groupId, $this->at('-18 months'));
        $this->assertSame(1, $this->service()->purgeClosedGroups());

        $this->assertNull($this->groupRepo->findById($groupId));
        foreach ([
            'discussion_group_posts',
            'discussion_group_replies',
            'discussion_group_post_media',
            'discussion_group_post_links',
            'discussion_group_post_reactions',
            'discussion_group_reply_reactions',
            'discussion_group_reply_reports',
            'discussion_group_sections',
            'discussion_group_members',
        ] as $table) {
            $this->assertSame(0, $this->rowCount($table), "{$table} must be empty after the purge");
        }

        $this->assertSame([], $this->albumMedia, 'no media may be left in the album');
        $this->assertSame([55], $this->deletedAlbums, 'the album itself must go');
        $this->assertFileDoesNotExist($absolutePath, 'the cached link image must leave the disk');
        $this->assertNull((new FileRepository($this->pdo))->findById($fileId));
    }

    /**
     * Journalling carries ids and durations, never a group name or a
     * message's text (SECURITY.md §11).
     */
    public function testTheJournalRecordsWhatWasPurgedWithIdsOnly(): void
    {
        $groupId = $this->group('Un nom de groupe très reconnaissable');
        $this->post($groupId, 'texte du message', '-30 months');

        $this->service()->purgePosts();

        $rows = $this->pdo->query("SELECT event_type, description, context FROM event_log WHERE category = 'groups'")
            ->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(['group_post_purged'], array_column($rows, 'event_type'));
        $this->assertStringNotContainsString('reconnaissable', (string) $rows[0]['context']);
        $this->assertStringNotContainsString('texte du message', (string) $rows[0]['context']);
    }

    // ---- fixtures ----

    private function service(bool $albumMissing = false): GroupLifecycleService
    {
        $albumManager = $this->albumManager($albumMissing);
        $postMediaService = new PostMediaService(
            $albumManager,
            new PostMediaRepository($this->pdo),
            $this->groupRepo,
            $this->replyRepo
        );
        $activityService = new GroupActivityService($this->groupRepo, $this->postRepo);
        $fileRepository = new FileRepository($this->pdo);

        return new GroupLifecycleService(
            $this->groupRepo,
            $this->postRepo,
            $postMediaService,
            new PostLinkService(
                new NullLinkPreviewFetcher(),
                new \Modules\Groups\Service\LinkFetchThrottleService(new LinkFetchLogRepository($this->pdo)),
                new PostLinkRepository($this->pdo),
                new UploadHandler($fileRepository, $this->storagePath),
                $fileRepository,
                $this->storagePath
            ),
            new ReplyService(
                $this->replyRepo,
                $activityService,
                $postMediaService,
                new RateLimitService(new RateLimitRepository($this->pdo))
            ),
            new PostService($this->postRepo, $activityService, new RateLimitService(new RateLimitRepository($this->pdo))),
            $albumManager,
            new ScoutYearService($this->pdo),
            new \Core\ScoutYear\ScoutYearResolver(
                new ScoutYearService($this->pdo),
                new SettingService(new SettingRepository($this->pdo)),
                new \Core\Import\MemberYearRepository($this->pdo)
            ),
            new SettingService(new SettingRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function mediaService(): PostMediaService
    {
        return new PostMediaService(
            $this->albumManager(),
            new PostMediaRepository($this->pdo),
            $this->groupRepo,
            $this->replyRepo
        );
    }

    /**
     * A recording fake, not a mock: these tests assert on what is LEFT,
     * which needs the album to actually lose the media it is told to
     * delete.
     */
    private function albumManager(bool $albumMissing = false): DelegatedAlbumManager
    {
        $manager = $this->createStub(DelegatedAlbumManager::class);
        $manager->method('deleteMedia')->willReturnCallback(function (int $albumId, int $mediaId): void {
            $this->deletedMedia[] = $mediaId;
            $this->albumMedia = array_values(array_filter($this->albumMedia, static fn(int $id): bool => $id !== $mediaId));
        });
        $manager->method('deleteAlbum')->willReturnCallback(function (int $albumId) use ($albumMissing): void {
            if ($albumMissing) {
                throw new \Modules\Gallery\Service\GalleryException('Album introuvable.');
            }
            $this->deletedAlbums[] = $albumId;
            $this->albumMedia = [];
        });
        $manager->method('listMedia')->willReturnCallback(
            fn(): array => array_map(
                static fn(int $id): DelegatedMedia => new DelegatedMedia($id, 'image', 'ready', 0, 'a.jpg', '2026-01-01 10:00:00'),
                $this->albumMedia
            )
        );

        return $manager;
    }

    private function group(
        string $name,
        ?int $scoutYearId = null,
        ?string $lastActivity = null,
        ?int $albumId = null
    ): int {
        $groupId = $this->groupRepo->create($name, $scoutYearId, null, null);
        if ($lastActivity !== null) {
            $this->groupRepo->touchActivity($groupId, $this->at($lastActivity));
        }
        if ($albumId !== null) {
            $this->groupRepo->setGalleryAlbumId($groupId, $albumId);
        }

        return $groupId;
    }

    private function post(int $groupId, string $body, string $lastActivity, bool $pinned = false): int
    {
        return GroupsTestHelper::createPostAt($this->pdo, $groupId, $body, $this->at($lastActivity), 7, 3, $pinned);
    }

    private function attachMedia(int $postId, int $mediaId): void
    {
        (new PostMediaRepository($this->pdo))->attach($postId, $mediaId, 0);
    }

    /**
     * A link with a real cached image on disk — the only way to assert
     * the file itself is removed and not merely its row.
     *
     * @return array{0: int, 1: string} [fileId, absolutePath]
     */
    private function attachLinkWithImage(int $postId): array
    {
        $relativePath = 'groups/link_preview.jpg';
        $absolutePath = $this->storagePath . '/' . $relativePath;
        @mkdir(dirname($absolutePath), 0o777, true);
        file_put_contents($absolutePath, 'jpeg-bytes');

        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, module_id, role_min, owner_type, owner_id, created_at, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $relativePath, 'link.jpg', 'image/jpeg', 10, 'groups', 'identified',
            'discussion_group', 1, '2024-01-01 10:00:00', 7,
        ]);
        $fileId = (int) $this->pdo->lastInsertId();

        (new PostLinkRepository($this->pdo))->attach($postId, 'https://example.org/a', 'Titre', 'Description', $fileId);

        return [$fileId, $absolutePath];
    }

    private function at(string $modifier): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->modify($modifier)->format('Y-m-d H:i:s');
    }

    private function rowCount(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    private function setSetting(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM settings WHERE module_id = ? AND setting_key = ?');
        $stmt->execute(['groups', $key]);

        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute(['groups', $key, $value, 'number', 'Durée', 'Durée']);
    }
}
