<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\PostLinkRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class PostLinkRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private PostLinkRepository $repository;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupId = (new \Modules\Groups\Repository\GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->repository = new PostLinkRepository($this->pdo);
    }

    private function createPost(string $body = 'hello'): int
    {
        return GroupsTestHelper::createPostAt($this->pdo, $this->groupId, $body, '2026-01-01 10:00:00');
    }

    public function testFindForPostReturnsNullWhenThereIsNoLink(): void
    {
        $postId = $this->createPost();

        $this->assertNull($this->repository->findForPost($postId));
    }

    public function testAttachThenFindForPostRoundTrips(): void
    {
        $postId = $this->createPost();

        $this->repository->attach($postId, 'https://example.com/a', 'Title', 'Description', 42);

        $link = $this->repository->findForPost($postId);
        $this->assertSame($postId, $link->postId);
        $this->assertSame('https://example.com/a', $link->url);
        $this->assertSame('Title', $link->title);
        $this->assertSame('Description', $link->description);
        $this->assertSame(42, $link->imageFileId);
    }

    public function testAttachWithNoMetadataStoresAPlainLink(): void
    {
        $postId = $this->createPost();

        $this->repository->attach($postId, 'https://example.com/a', null, null, null);

        $link = $this->repository->findForPost($postId);
        $this->assertNull($link->title);
        $this->assertNull($link->description);
        $this->assertNull($link->imageFileId);
    }

    public function testFindForPostsBatchesAcrossMultiplePosts(): void
    {
        $postA = $this->createPost('a');
        $postB = $this->createPost('b');
        $postC = $this->createPost('c'); // no link

        $this->repository->attach($postA, 'https://example.com/a', 'A', null, null);
        $this->repository->attach($postB, 'https://example.com/b', 'B', null, null);

        $byPost = $this->repository->findForPosts([$postA, $postB, $postC]);

        $this->assertCount(2, $byPost);
        $this->assertSame('A', $byPost[$postA]->title);
        $this->assertSame('B', $byPost[$postB]->title);
        $this->assertArrayNotHasKey($postC, $byPost);
    }

    public function testFindForPostsWithNoIdsReturnsEmptyWithoutQuerying(): void
    {
        $this->assertSame([], $this->repository->findForPosts([]));
    }

    public function testDeleteForPostRemovesTheRow(): void
    {
        $postId = $this->createPost();
        $this->repository->attach($postId, 'https://example.com/a', null, null, null);

        $this->repository->deleteForPost($postId);

        $this->assertNull($this->repository->findForPost($postId));
    }
}
