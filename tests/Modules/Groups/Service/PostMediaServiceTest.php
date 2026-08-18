<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Gallery\Api\DelegatedAlbum;
use Modules\Gallery\Api\DelegatedAlbumManager;
use Modules\Gallery\Api\DelegatedMedia;
use Modules\Gallery\Service\GalleryException;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostMediaRepository;
use Modules\Groups\Service\PostMediaService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * PostMediaService's own orchestration against a mocked
 * Api\DelegatedAlbumManager — gallery's real behaviour (concurrency
 * safety, actual deletion of stored objects, /files/{id} scoping) is
 * proven in tests/Modules/Groups/PostMediaIntegrationTest.php against
 * the real gallery stack; this file only checks what this module itself
 * is responsible for: which calls it makes, in what order, and how it
 * reacts to failure.
 *
 * @group database
 */
#[Group('database')]
class PostMediaServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private PostMediaRepository $postMediaRepo;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postMediaRepo = new PostMediaRepository($this->pdo);

        $creator = GroupsTestHelper::createMember($this->pdo, 'CREATOR');
        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, $creator);
    }

    private function service(DelegatedAlbumManager $manager): PostMediaService
    {
        return new PostMediaService($manager, $this->postMediaRepo, $this->groupRepo);
    }

    public function testEnsureAlbumIdCreatesTheAlbumOnFirstUseAndCachesIt(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->once())->method('ensureAlbum')
            ->with('discussion_group', $this->groupId, 'Louveteaux', $this->anything(), 7)
            ->willReturn(new DelegatedAlbum(55, 'Louveteaux', '2026-01-01'));

        $group = $this->groupRepo->findById($this->groupId);
        $albumId = $this->service($manager)->ensureAlbumId($group, 7);

        $this->assertSame(55, $albumId);
        $this->assertSame(55, $this->groupRepo->findById($this->groupId)->galleryAlbumId);
    }

    public function testEnsureAlbumIdNeverCallsGalleryAgainOnceCached(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->never())->method('ensureAlbum');

        $this->groupRepo->setGalleryAlbumId($this->groupId, 99);
        $group = $this->groupRepo->findById($this->groupId);

        $this->assertSame(99, $this->service($manager)->ensureAlbumId($group, 7));
    }

    public function testAddMediaAttachesEachFileInOrderAndReturnsTheirIds(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('ensureAlbum')->willReturn(new DelegatedAlbum(1, 'Louveteaux', '2026-01-01'));
        $manager->method('addMedia')->willReturnOnConsecutiveCalls(
            $this->delegatedMedia(10),
            $this->delegatedMedia(11)
        );

        $group = $this->groupRepo->findById($this->groupId);
        $postId = $this->createPost();

        $mediaIds = $this->service($manager)->addMedia($group, $postId, [$this->fakeFile(), $this->fakeFile()], 3);

        $this->assertSame([10, 11], $mediaIds);
        $this->assertSame([10, 11], $this->postMediaRepo->findMediaIdsForPost($postId));
    }

    /**
     * @return DelegatedAlbumManager&\PHPUnit\Framework\MockObject\MockObject
     */
    private function managerFailingOnSecondAddMedia(): DelegatedAlbumManager
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('ensureAlbum')->willReturn(new DelegatedAlbum(1, 'Louveteaux', '2026-01-01'));
        $call = 0;
        $manager->method('addMedia')->willReturnCallback(function () use (&$call) {
            $call++;
            if ($call === 2) {
                throw new GalleryException('Type de fichier non autorisé.');
            }
            return $this->delegatedMedia(10);
        });

        return $manager;
    }

    public function testAddMediaLetsAGalleryExceptionPropagateForTheCallerToRollBack(): void
    {
        $group = $this->groupRepo->findById($this->groupId);
        $postId = $this->createPost();

        $this->expectException(GalleryException::class);
        $this->service($this->managerFailingOnSecondAddMedia())
            ->addMedia($group, $postId, [$this->fakeFile(), $this->fakeFile()], 3);
    }

    public function testAddMediaLeavesTheFirstFilesAttachmentInPlaceWhenTheSecondFails(): void
    {
        // addMedia() itself never rolls back — that is
        // Controller\PostController::create()'s job, via
        // deleteAllForPost(). This documents the boundary: after a
        // partial failure, the join row for what did succeed is still
        // there until the caller explicitly cleans up.
        $group = $this->groupRepo->findById($this->groupId);
        $postId = $this->createPost();

        try {
            $this->service($this->managerFailingOnSecondAddMedia())
                ->addMedia($group, $postId, [$this->fakeFile(), $this->fakeFile()], 3);
            $this->fail('expected GalleryException');
        } catch (GalleryException) {
        }

        $this->assertSame([10], $this->postMediaRepo->findMediaIdsForPost($postId));
    }

    public function testDeleteAllForPostRemovesEveryAttachedMediaThroughTheApi(): void
    {
        $this->groupRepo->setGalleryAlbumId($this->groupId, 42);
        $postId = $this->createPost();
        $this->postMediaRepo->attach($postId, 10, 0);
        $this->postMediaRepo->attach($postId, 11, 1);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->exactly(2))->method('deleteMedia')
            ->with(42, $this->logicalOr(10, 11));

        $group = $this->groupRepo->findById($this->groupId);
        $this->service($manager)->deleteAllForPost($group, $postId);
    }

    public function testDeleteAllForPostReadsTheAlbumIdFreshEvenWhenTheCallersGroupInstanceIsStale(): void
    {
        // Reproduces addMedia()'s own rollback path: the album is created
        // (and gallery_album_id persisted) mid-request, but the $group
        // instance PostController is still holding was fetched before
        // that write and reads galleryAlbumId as null.
        $staleGroup = $this->groupRepo->findById($this->groupId);
        $this->assertNull($staleGroup->galleryAlbumId, 'precondition: fetched before the album existed');

        $this->groupRepo->setGalleryAlbumId($this->groupId, 77);
        $postId = $this->createPost();
        $this->postMediaRepo->attach($postId, 10, 0);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->once())->method('deleteMedia')->with(77, 10);

        $this->service($manager)->deleteAllForPost($staleGroup, $postId);
    }

    public function testDeleteAllForPostNeverThrowsWhenTheMediaIsAlreadyGone(): void
    {
        $this->groupRepo->setGalleryAlbumId($this->groupId, 42);
        $postId = $this->createPost();
        $this->postMediaRepo->attach($postId, 10, 0);

        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('deleteMedia')->willThrowException(new GalleryException('déjà supprimé'));

        $group = $this->groupRepo->findById($this->groupId);
        $this->service($manager)->deleteAllForPost($group, $postId);

        $this->addToAssertionCount(1); // reaching here without throwing is the assertion
    }

    public function testDeleteAllForPostIsANoOpWhenTheGroupHasNoAlbumYet(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->never())->method('deleteMedia');

        $group = $this->groupRepo->findById($this->groupId);
        $this->service($manager)->deleteAllForPost($group, $this->createPost());
    }

    public function testMediaForPostsMapsEachPostToItsOwnMediaInAttachmentOrderAndSkipsARaceDeletedId(): void
    {
        $postA = $this->createPost();
        $postB = $this->createPost();
        $this->postMediaRepo->attach($postA, 10, 0);
        $this->postMediaRepo->attach($postA, 11, 1);
        $this->postMediaRepo->attach($postB, 12, 0);

        // 11 is missing from $mediaById entirely — a concurrent delete
        // between listMedia() and this call — and must be skipped rather
        // than breaking postA's whole render.
        $mediaById = [10 => $this->delegatedMedia(10), 12 => $this->delegatedMedia(12)];

        $result = $this->service($this->createMock(DelegatedAlbumManager::class))
            ->mediaForPosts([$postA, $postB], $mediaById);

        $this->assertSame([10], array_map(fn(DelegatedMedia $m) => $m->id, $result[$postA]));
        $this->assertSame([12], array_map(fn(DelegatedMedia $m) => $m->id, $result[$postB]));
    }

    public function testAlbumMediaByIdIsEmptyWhenTheGroupHasNoAlbumYet(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->never())->method('listMedia');

        $group = $this->groupRepo->findById($this->groupId);
        $this->assertSame([], $this->service($manager)->albumMediaById($group));
    }

    public function testGroupGalleryMediaSortsNewestFirstById(): void
    {
        $this->groupRepo->setGalleryAlbumId($this->groupId, 42);
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->expects($this->once())->method('listMedia')->with(42)->willReturn([
            $this->delegatedMedia(10), $this->delegatedMedia(11), $this->delegatedMedia(9),
        ]);

        $group = $this->groupRepo->findById($this->groupId);
        $media = $this->service($manager)->groupGalleryMedia($group);

        $this->assertSame([11, 10, 9], array_map(fn(DelegatedMedia $m) => $m->id, $media));
    }

    public function testVideoUploadAllowedDelegatesToTheApi(): void
    {
        $manager = $this->createMock(DelegatedAlbumManager::class);
        $manager->method('videoUploadAllowed')->willReturn(true);

        $this->assertTrue($this->service($manager)->videoUploadAllowed());
    }

    private function createPost(): int
    {
        return GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'Bonjour', '2026-01-01 10:00:00');
    }

    private function delegatedMedia(int $id): DelegatedMedia
    {
        return new DelegatedMedia($id, 'photo', 'done', 0, 'photo.jpg', '2026-01-01 10:00:00');
    }

    /**
     * @return array{name: string, tmp_name: string, error: int, size: int, type: string}
     */
    private function fakeFile(): array
    {
        return ['name' => 'photo.jpg', 'tmp_name' => '/tmp/x', 'error' => 0, 'size' => 100, 'type' => 'image/jpeg'];
    }
}
