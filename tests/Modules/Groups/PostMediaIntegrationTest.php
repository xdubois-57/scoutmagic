<?php

declare(strict_types=1);

namespace Tests\Modules\Groups;

use Core\File\FileAccessGuard;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Member\MemberService;
use Core\Member\SectionMembershipRepository;
use Core\Member\SectionService;
use Core\ScoutYear\EffectiveScoutYear;
use Core\ScoutYear\ScoutYearResolver;
use Core\Config\ScoutYearService;
use Core\Config\SettingService;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;
use Modules\Gallery\Repository\S3SecretRepository;
use Modules\Gallery\Repository\StorageLocation;
use Modules\Gallery\Repository\StorageLocationRepository;
use Modules\Gallery\Service\DelegatedAlbumService;
use Modules\Gallery\Service\FfmpegAvailability;
use Modules\Gallery\Service\GalleryAccessService;
use Modules\Gallery\Service\MediaService;
use Modules\Gallery\Service\Storage\StorageBackendFactory;
use Modules\Gallery\Service\Storage\StorageBackendInterface;
use Modules\Gallery\Service\StorageLocationService;
use Modules\Groups\File\GroupFileOwnershipChecker;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Service\GroupAccessService;
use Modules\Groups\Service\GroupService;
use Modules\Groups\Service\GroupSessionContext;
use Modules\Groups\Service\PostMediaService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Gallery\GalleryTestHelper;

/**
 * PostMediaService wired against the REAL gallery stack (unlike
 * Service\PostMediaServiceTest, which mocks Api\DelegatedAlbumManager) —
 * the only place this module's DoD items about actual cross-module
 * behaviour are exercised: concurrency-safe album creation, /files/{id}
 * scoping through Core\File\FileAccessGuard, and genuine deletion of
 * stored objects (local and S3).
 *
 * @group database
 */
#[Group('database')]
class PostMediaIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private StorageLocationRepository $storageLocationRepo;
    private AlbumRepository $albumRepo;
    private MediaRepository $mediaRepo;
    private FileRepository $fileRepo;
    private StorageBackendFactory $storageBackendFactory;
    private int $groupId;
    private int $creatorMemberId;
    private int $creatorAccountId;
    private string $storagePath;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GalleryTestHelper::createTables($this->pdo);
        GroupsTestHelper::createTables($this->pdo);
        // The rest of the suite leaves SQLite's FK enforcement off (its
        // default), so ON DELETE CASCADE is normally inert in tests —
        // enabled here only for this file, which specifically verifies
        // that a deleted post's join rows are actually gone afterward.
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->creatorMemberId = GroupsTestHelper::createMember($this->pdo, 'CREATOR');
        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, $this->creatorMemberId);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute(['enc', 'idx']);
        // gallery_albums.created_by is a user_accounts.id (FK), never a
        // members.id — Service\PostMediaService::ensureAlbumId() passes
        // the account id through for exactly this reason.
        $this->creatorAccountId = (int) $this->pdo->lastInsertId();

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storageLocationRepo = new StorageLocationRepository($this->pdo, $encryption);
        $this->albumRepo = new AlbumRepository($this->pdo);
        $this->mediaRepo = new MediaRepository($this->pdo);
        $this->fileRepo = new FileRepository($this->pdo);

        $this->storagePath = sys_get_temp_dir() . '/groups_media_integration_' . uniqid();
        mkdir($this->storagePath, 0755, true);
        $this->storageBackendFactory = new StorageBackendFactory($this->storageLocationRepo, $this->storagePath);

        $this->storageLocationRepo->create(
            StorageLocation::TYPE_LOCAL, 'Stockage local', 'gallery', null, null, null, null, null, null, null
        );
    }

    private function delegatedAlbumService(?AlbumRepository $albumRepository = null): DelegatedAlbumService
    {
        $albumRepository ??= $this->albumRepo;
        $settingService = $this->createMock(SettingService::class);
        $settingService->method('get')->willReturnCallback(fn($key, $module, $default) => $default);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $storageLocationService = new StorageLocationService(
            $this->storageLocationRepo, $albumRepository, $this->storageBackendFactory,
            $settingService, new S3SecretRepository($this->pdo, $encryption), $this->storagePath
        );
        $accessService = $this->createMock(GalleryAccessService::class);
        $uploadHandler = new UploadHandler($this->fileRepo, $this->storagePath);
        $mediaService = new MediaService(
            $this->mediaRepo, $albumRepository, $uploadHandler, $this->createMock(\Core\Scheduler\SchedulerService::class),
            $settingService, $accessService, $this->storageBackendFactory, $storageLocationService,
            $this->createMock(FfmpegAvailability::class)
        );

        return new DelegatedAlbumService(
            $albumRepository, $this->mediaRepo, $mediaService, $this->storageLocationRepo,
            $storageLocationService, $this->storageBackendFactory, new ScoutYearService($this->pdo)
        );
    }

    private function postMediaService(?AlbumRepository $albumRepository = null): PostMediaService
    {
        return new PostMediaService(
            $this->delegatedAlbumService($albumRepository), new PostMediaRepository($this->pdo), $this->groupRepo
        );
    }

    private function fakeUploadedImage(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'groups_media_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $path);
        imagedestroy($image);

        return ['name' => 'photo.jpg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'type' => 'image/jpeg'];
    }

    private function fileOwnershipChecker(int $effectiveYearId): GroupFileOwnershipChecker
    {
        $sectionRepo = new \Modules\Groups\Repository\GroupSectionRepository($this->pdo);
        $memberRepo = new \Modules\Groups\Repository\GroupMemberRepository($this->pdo);
        $access = new GroupAccessService($memberRepo, $sectionRepo, new SectionMembershipRepository($this->pdo));
        $resolver = $this->createMock(ScoutYearResolver::class);
        $resolver->method('getEffectiveYear')->willReturn(new EffectiveScoutYear($effectiveYearId, '2025-2026', null));

        return new GroupFileOwnershipChecker($this->groupRepo, $access, $resolver);
    }

    /**
     * DoD: "Concurrent first-media posts create exactly one album,
     * tested." Same race-simulation technique as gallery's own
     * DelegatedAlbumServiceTest, exercised this time through
     * Service\PostMediaService::ensureAlbumId() — the actual call site a
     * concurrent first media post goes through.
     */
    public function testConcurrentFirstMediaPostsCreateExactlyOneAlbum(): void
    {
        $racingRepository = new class ($this->pdo) extends AlbumRepository {
            public function create(
                string $type, string $title, ?string $subtitle, string $albumDate, ?int $sectionId,
                int $scoutYearId, ?string $externalUrl, ?int $storageLocationId, int $createdBy,
                ?string $ownerType = null, ?int $ownerId = null
            ): int {
                // Another member's post wins the race for the same
                // group's first album, landing its own INSERT first.
                parent::create(
                    $type, 'Compétiteur', $subtitle, $albumDate, $sectionId, $scoutYearId,
                    $externalUrl, $storageLocationId, $createdBy, $ownerType, $ownerId
                );

                return parent::create(
                    $type, $title, $subtitle, $albumDate, $sectionId, $scoutYearId,
                    $externalUrl, $storageLocationId, $createdBy, $ownerType, $ownerId
                );
            }
        };

        $group = $this->groupRepo->findById($this->groupId);
        $albumId = $this->postMediaService($racingRepository)->ensureAlbumId($group, $this->creatorAccountId);

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM gallery_albums')->fetchColumn());
        // The group's cached id points at whichever row actually exists.
        $persisted = $this->groupRepo->findById($this->groupId);
        $this->assertNotNull($persisted);
        $this->assertSame($albumId, $persisted->galleryAlbumId);
    }

    /**
     * DoD: "The uploaded original is inaccessible to a non-member
     * through /files/{id}, tested."
     */
    public function testTheUploadedOriginalIsInaccessibleToANonMemberThroughFileAccessGuard(): void
    {
        $currentYearId = (int) $this->pdo->query('SELECT id FROM scout_years LIMIT 1')->fetchColumn();
        $member = GroupsTestHelper::createMember($this->pdo, 'M1');
        // Explicit membership (not section-derived) so the checker's
        // "member of the group" path is exercised directly.
        $memberRepo = new \Modules\Groups\Repository\GroupMemberRepository($this->pdo);
        $memberRepo->add($this->groupId, $member, false, null);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');

        $group = $this->groupRepo->findById($this->groupId);
        $service = $this->postMediaService();
        $media = $service->addMedia($group, $this->post(), [$this->fakeUploadedImage()], $this->creatorAccountId)[0];
        $mediaRow = $this->mediaRepo->findById($media);
        $this->assertNotNull($mediaRow);
        $fileId = $mediaRow->fileId;

        $checker = $this->fileOwnershipChecker($currentYearId);

        $memberGuard = new FileAccessGuard($this->fileRepo, Role::IDENTIFIED, [$member], [$checker]);
        $outsiderGuard = new FileAccessGuard($this->fileRepo, Role::IDENTIFIED, [$outsider], [$checker]);

        $this->assertNotNull($memberGuard->check($fileId));
        $this->assertNull($outsiderGuard->check($fileId));
    }

    /**
     * DoD: "Deleting a post removes join rows, media rows and stored
     * objects, tested on both a local and an S3-configured location with
     * no orphan left." — local half; the S3 half is
     * testDeletingAPostRemovesStoredObjectsOnAnS3Location() below.
     */
    public function testDeletingAPostRemovesStoredObjectsOnALocalLocation(): void
    {
        $group = $this->groupRepo->findById($this->groupId);
        $postMediaService = $this->postMediaService();
        $postId = $this->post();

        $mediaIds = $postMediaService->addMedia($group, $postId, [$this->fakeUploadedImage()], $this->creatorAccountId);
        $mediaId = $mediaIds[0];
        $group = $this->groupRepo->findById($this->groupId); // refreshed: now carries galleryAlbumId

        // Simulates Task\ProcessPhotoHandler having already produced the
        // three renditions gallery actually manages (and unlinked the raw
        // original — a separate, gallery-internal lifecycle stage this
        // test isn't exercising): writes real files through the same
        // local backend production code uses, so their disappearance is
        // genuinely observed rather than asserted against paths that
        // were never populated in the first place.
        $location = $this->storageLocationRepo->findDefault();
        $backend = $this->storageBackendFactory->create($location);
        $albumId = $group->galleryAlbumId;
        $backend->put("{$albumId}/thumb_{$mediaId}.jpg", 'thumb-bytes', 'image/jpeg');
        $backend->put("{$albumId}/med_{$mediaId}.jpg", 'med-bytes', 'image/jpeg');
        $backend->put("{$albumId}/lg_{$mediaId}.jpg", 'lg-bytes', 'image/jpeg');
        $this->mediaRepo->markPhotoDone(
            $mediaId, "{$albumId}/thumb_{$mediaId}.jpg", "{$albumId}/med_{$mediaId}.jpg", "{$albumId}/lg_{$mediaId}.jpg", 100, 100
        );
        $thumbPath = $this->storagePath . "/gallery/{$albumId}/thumb_{$mediaId}.jpg";
        $mediumPath = $this->storagePath . "/gallery/{$albumId}/med_{$mediaId}.jpg";
        $largePath = $this->storagePath . "/gallery/{$albumId}/lg_{$mediaId}.jpg";
        $this->assertFileExists($thumbPath);
        $this->assertFileExists($mediumPath);
        $this->assertFileExists($largePath);

        // Mirrors Controller\PostController::delete()'s own sequence:
        // media first (needs the still-existing join row to know what to
        // remove), then the post row itself, whose ON DELETE CASCADE is
        // what actually clears discussion_group_post_media.
        $postMediaService->deleteAllForPost($group, $postId);
        (new \Modules\Groups\Repository\PostRepository($this->pdo))->delete($postId);

        $this->assertNull($this->mediaRepo->findById($mediaId));
        $this->assertFileDoesNotExist($thumbPath);
        $this->assertFileDoesNotExist($mediumPath);
        $this->assertFileDoesNotExist($largePath);
        $this->assertSame([], (new PostMediaRepository($this->pdo))->findMediaIdsForPost($postId));
    }

    /**
     * S3 half of the DoD item above — asserts the storage backend
     * actually receives delete()/deletePrefix() calls for the stored
     * renditions, same mocking precedent gallery's own tests use for S3.
     */
    public function testDeletingAPostRemovesStoredObjectsOnAnS3Location(): void
    {
        $s3LocationId = $this->storageLocationRepo->create(
            StorageLocation::TYPE_S3, 'Bucket privé', null, 'custom', 'https://s3.example.com', 'eu',
            'bucket', 'ak', null, 'sk'
        );

        $backend = $this->createMock(StorageBackendInterface::class);
        $backend->method('put');
        $factory = $this->createMock(StorageBackendFactory::class);
        $factory->method('create')->willReturn($backend);
        $this->storageBackendFactory = $factory;

        $group = $this->groupRepo->findById($this->groupId);
        $postMediaService = $this->postMediaService();
        $postId = $this->post();

        $album = $this->delegatedAlbumServiceEnsureAlbumOnLocation($s3LocationId);
        $this->groupRepo->setGalleryAlbumId($this->groupId, $album);

        $mediaId = $this->mediaRepo->create($album, 'photo', $this->seedFile(), 0, null);
        $this->mediaRepo->markPhotoDone($mediaId, 'thumb.jpg', 'med.jpg', 'lg.jpg', 100, 100);
        (new PostMediaRepository($this->pdo))->attach($postId, $mediaId, 0);

        $backend->expects($this->exactly(3))->method('delete')
            ->with($this->logicalOr('thumb.jpg', 'med.jpg', 'lg.jpg'));

        $group = $this->groupRepo->findById($this->groupId);
        $postMediaService->deleteAllForPost($group, $postId);

        $this->assertNull($this->mediaRepo->findById($mediaId));
    }

    private function delegatedAlbumServiceEnsureAlbumOnLocation(int $locationId): int
    {
        $service = $this->delegatedAlbumService();
        $album = $service->ensureAlbum('discussion_group', $this->groupId, 'Louveteaux', '2026-01-01', $this->creatorAccountId, $locationId);
        return $album->id;
    }

    private function seedFile(): int
    {
        return $this->fileRepo->create('a', 'a', 'image/jpeg', 1, 'identified', 'gallery', $this->creatorMemberId);
    }

    private function post(): int
    {
        return GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-01 10:00:00');
    }

    private function replyService(): \Modules\Groups\Service\ReplyService
    {
        return new \Modules\Groups\Service\ReplyService(
            new \Modules\Groups\Repository\ReplyRepository($this->pdo),
            new \Modules\Groups\Service\GroupActivityService(
                $this->groupRepo,
                new \Modules\Groups\Repository\PostRepository($this->pdo)
            ),
            $this->postMediaService()
        );
    }

    /**
     * DoD: "A non-member cannot fetch a reply image, tested."
     *
     * A reply's image goes through the SAME delegated album as post media
     * (module spec: no second storage path), so it is guarded by the same
     * owner_type — this asserts that end-to-end rather than assuming the
     * reuse carried the guarantee with it.
     */
    public function testAReplyImageIsInaccessibleToANonMemberThroughFileAccessGuard(): void
    {
        $currentYearId = (int) $this->pdo->query('SELECT id FROM scout_years LIMIT 1')->fetchColumn();
        $member = GroupsTestHelper::createMember($this->pdo, 'M1');
        (new \Modules\Groups\Repository\GroupMemberRepository($this->pdo))->add($this->groupId, $member, false, null);
        $outsider = GroupsTestHelper::createMember($this->pdo, 'OUT');

        $group = $this->groupRepo->findById($this->groupId);
        $mediaId = $this->postMediaService()->addOne($group, $this->fakeUploadedImage(), $this->creatorAccountId);
        $fileId = $this->mediaRepo->findById($mediaId)->fileId;

        $checker = $this->fileOwnershipChecker($currentYearId);
        $memberGuard = new FileAccessGuard($this->fileRepo, Role::IDENTIFIED, [$member], [$checker]);
        $outsiderGuard = new FileAccessGuard($this->fileRepo, Role::IDENTIFIED, [$outsider], [$checker]);

        $this->assertNotNull($memberGuard->check($fileId));
        $this->assertNull($outsiderGuard->check($fileId));
    }

    /**
     * DoD: "Deleting a post cascades to replies, reactions and reply
     * images with no orphan row or orphan stored object, tested."
     *
     * Verifies the cascade actually FIRES rather than assuming it (module
     * spec's own pitfall): FK enforcement is on for this file, and the
     * reply image — which lives in another module's table, outside any
     * CASCADE reach — is checked on disk, not just in the database.
     */
    public function testDeletingAPostCascadesToRepliesReactionsAndReplyImages(): void
    {
        $group = $this->groupRepo->findById($this->groupId);
        $postId = $this->post();
        $replyService = $this->replyService();

        // A reply carrying an image, plus reactions on both the post and
        // the reply — everything a post's deletion has to sweep.
        $mediaId = $this->postMediaService()->addOne($group, $this->fakeUploadedImage(), $this->creatorAccountId);
        $group = $this->groupRepo->findById($this->groupId); // refreshed: now carries galleryAlbumId
        $replyId = GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Avec image', '2026-01-01 10:01:00', 1, 1, $mediaId);
        $textOnlyReplyId = GroupsTestHelper::createReplyAt($this->pdo, $postId, 'Sans image', '2026-01-01 10:02:00');

        $member = GroupsTestHelper::createMember($this->pdo, 'REACTOR');
        \Modules\Groups\Repository\ReactionRepository::forPosts($this->pdo)->set($postId, $member, 'heart');
        \Modules\Groups\Repository\ReactionRepository::forReplies($this->pdo)->set($replyId, $member, 'clap');
        \Modules\Groups\Repository\ReactionRepository::forReplies($this->pdo)->set($textOnlyReplyId, $member, 'joy');

        // Give the reply's media real stored renditions, so their
        // disappearance is genuinely observed on disk.
        $location = $this->storageLocationRepo->findDefault();
        $backend = $this->storageBackendFactory->create($location);
        $albumId = $group->galleryAlbumId;
        $backend->put("{$albumId}/thumb_{$mediaId}.jpg", 'thumb-bytes', 'image/jpeg');
        $this->mediaRepo->markPhotoDone(
            $mediaId, "{$albumId}/thumb_{$mediaId}.jpg", "{$albumId}/med_{$mediaId}.jpg", "{$albumId}/lg_{$mediaId}.jpg", 10, 10
        );
        $thumbPath = $this->storagePath . "/gallery/{$albumId}/thumb_{$mediaId}.jpg";
        $this->assertFileExists($thumbPath);

        // Mirrors Controller\PostController::delete()'s own sequence.
        $this->postMediaService()->deleteAllForPost($group, $postId);
        $replyService->deleteAllMediaForPost($group, $postId);
        (new \Modules\Groups\Repository\PostRepository($this->pdo))->delete($postId);

        // The reply's stored image is gone from disk and from gallery.
        $this->assertFileDoesNotExist($thumbPath);
        $this->assertNull($this->mediaRepo->findById($mediaId));

        // And the CASCADE really fired: no reply row, no reaction row of
        // either kind, anywhere.
        $this->assertSame(0, $this->countRows('discussion_group_replies'));
        $this->assertSame(0, $this->countRows('discussion_group_post_reactions'));
        $this->assertSame(0, $this->countRows('discussion_group_reply_reactions'));
    }

    /**
     * The negative control for the test above: without FK enforcement the
     * cascade silently does nothing, so this pins that the fixture really
     * has it on — otherwise the assertions above would pass vacuously on a
     * database that never cascaded at all.
     */
    public function testForeignKeyEnforcementIsActuallyOnInThisFixture(): void
    {
        $this->assertSame(1, (int) $this->pdo->query('PRAGMA foreign_keys')->fetchColumn());
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->storagePath);
    }

    private function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }
}
