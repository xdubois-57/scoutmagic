<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\Reply;
use Modules\Groups\Repository\ReplyRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * @group database
 */
#[Group('database')]
class ReplyRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ReplyRepository $repository;
    private int $groupId;
    private int $postId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'hello', '2026-01-01 10:00:00');
        $this->repository = new ReplyRepository($this->pdo);
    }

    /**
     * @param Reply[] $replies
     * @return string[]
     */
    private function bodies(array $replies): array
    {
        return array_map(fn(Reply $r) => $r->body, $replies);
    }

    private function seed(int $n, ?int $postId = null): void
    {
        for ($i = 1; $i <= $n; $i++) {
            GroupsTestHelper::createReplyAt(
                $this->pdo,
                $postId ?? $this->postId,
                'reply ' . $i,
                (new \DateTimeImmutable('2026-01-01 10:00:00'))->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s')
            );
        }
    }

    public function testCreateThenFindByIdRoundTrips(): void
    {
        $id = $this->repository->create($this->postId, 7, 3, 'Bonjour', 42, '2026-01-01 10:01:00');

        $reply = $this->repository->findById($id);
        $this->assertSame($this->postId, $reply->postId);
        $this->assertSame(7, $reply->authorUserAccountId);
        $this->assertSame(3, $reply->authorMemberId);
        $this->assertSame('Bonjour', $reply->body);
        $this->assertSame(42, $reply->galleryMediaId);
        $this->assertFalse($reply->isEdited());
    }

    public function testAReplyWithNoImageStoresANullMediaId(): void
    {
        $id = $this->repository->create($this->postId, 7, 3, 'Texte seul', null, '2026-01-01 10:01:00');

        $this->assertNull($this->repository->findById($id)->galleryMediaId);
    }

    public function testFindByIdReturnsNullForAnUnknownId(): void
    {
        $this->assertNull($this->repository->findById(999));
    }

    public function testRepliesAreReturnedOldestFirst(): void
    {
        $this->seed(3);

        $this->assertSame(['reply 1', 'reply 2', 'reply 3'], $this->bodies($this->repository->findPage($this->postId, 10)));
    }

    public function testPaginationWalksForwardWithoutGapOrRepeat(): void
    {
        $this->seed(7);

        $first = $this->repository->findPage($this->postId, 3);
        $second = $this->repository->findPage($this->postId, 3, $first[2]->id);
        $third = $this->repository->findPage($this->postId, 3, $second[2]->id);

        $this->assertSame(['reply 1', 'reply 2', 'reply 3'], $this->bodies($first));
        $this->assertSame(['reply 4', 'reply 5', 'reply 6'], $this->bodies($second));
        $this->assertSame(['reply 7'], $this->bodies($third));
    }

    public function testANewReplyArrivingMidScrollDoesNotShiftTheNextPage(): void
    {
        // The reason for keyset rather than OFFSET, one level down from
        // the feed's own: the cursor is anchored to a row, so a reply
        // landing at the end between two page loads cannot skip anything.
        $this->seed(4);
        $first = $this->repository->findPage($this->postId, 2);

        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'brand new', '2026-06-01 12:00:00');

        $second = $this->repository->findPage($this->postId, 2, $first[1]->id);

        $this->assertSame(['reply 3', 'reply 4'], $this->bodies($second));
    }

    public function testFindPageIsScopedToItsOwnPost(): void
    {
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'other', '2026-01-01 11:00:00');
        $this->seed(2);
        $this->seed(2, $otherPostId);

        $this->assertCount(2, $this->repository->findPage($this->postId, 10));
    }

    public function testCountForPostCountsOnlyThatPost(): void
    {
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'other', '2026-01-01 11:00:00');
        $this->seed(3);
        $this->seed(5, $otherPostId);

        $this->assertSame(3, $this->repository->countForPost($this->postId));
        $this->assertSame(5, $this->repository->countForPost($otherPostId));
    }

    public function testFindFirstForPostsLimitsPerPostAndReportsTrueTotals(): void
    {
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'other', '2026-01-01 11:00:00');
        $this->seed(6);
        $this->seed(2, $otherPostId);

        $result = $this->repository->findFirstForPosts([$this->postId, $otherPostId], 3);

        // The per-post limit is applied inside the database, so a post
        // with many replies still transfers only the first few.
        $this->assertSame(['reply 1', 'reply 2', 'reply 3'], $this->bodies($result['replies'][$this->postId]));
        $this->assertCount(2, $result['replies'][$otherPostId]);
        // …but the count is the real one, which is what decides whether
        // "Voir plus de réponses" appears.
        $this->assertSame(6, $result['counts'][$this->postId]);
        $this->assertSame(2, $result['counts'][$otherPostId]);
    }

    public function testFindFirstForPostsOmitsAPostWithNoReplies(): void
    {
        $emptyPostId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'quiet', '2026-01-01 11:00:00');
        $this->seed(1);

        $result = $this->repository->findFirstForPosts([$this->postId, $emptyPostId], 3);

        $this->assertArrayNotHasKey($emptyPostId, $result['replies']);
        $this->assertArrayNotHasKey($emptyPostId, $result['counts']);
    }

    public function testFindFirstForPostsWithNoIdsReturnsEmptyWithoutQuerying(): void
    {
        $this->assertSame(['replies' => [], 'counts' => []], $this->repository->findFirstForPosts([], 3));
    }

    public function testFindMediaIdsForPostReturnsOnlyTheRepliesThatHaveOne(): void
    {
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'with image', '2026-01-01 10:01:00', 1, 1, 11);
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'text only', '2026-01-01 10:02:00');
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'another image', '2026-01-01 10:03:00', 1, 1, 12);

        $this->assertSame([11, 12], $this->repository->findMediaIdsForPost($this->postId));
    }

    public function testUpdateBodyMarksTheReplyEdited(): void
    {
        $id = $this->repository->create($this->postId, 7, 3, 'avant', null, '2026-01-01 10:01:00');

        $this->repository->updateBody($id, 'après', '2026-01-01 10:05:00');

        $reply = $this->repository->findById($id);
        $this->assertSame('après', $reply->body);
        $this->assertTrue($reply->isEdited());
    }

    public function testDeleteRemovesTheRow(): void
    {
        $id = $this->repository->create($this->postId, 7, 3, 'à supprimer', null, '2026-01-01 10:01:00');

        $this->repository->delete($id);

        $this->assertNull($this->repository->findById($id));
    }
}
