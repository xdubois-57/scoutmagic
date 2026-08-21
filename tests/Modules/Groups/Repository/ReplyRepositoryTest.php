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

    /**
     * What a collapsed conversation's "2 nouveaux" badge counts
     * (partials/post_card.html.twig).
     */
    public function testCountNewerForPostsCountsOnlyWhatArrivedAfterTheGivenMoment(): void
    {
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'avant', '2026-01-01 10:05:00', 1, 5);
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'après 1', '2026-01-01 12:00:00', 1, 5);
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'après 2', '2026-01-01 13:00:00', 1, 6);

        $this->assertSame(
            [$this->postId => 2],
            $this->repository->countNewerForPosts([$this->postId], '2026-01-01 11:00:00')
        );
    }

    /**
     * A comment you wrote yourself is never new to you — without this,
     * every thread you had just taken part in would wear a badge.
     */
    public function testCountNewerForPostsNeverCountsTheReadersOwnComments(): void
    {
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'la mienne', '2026-01-01 12:00:00', 1, 5);
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, "d'un autre", '2026-01-01 12:30:00', 1, 9);

        $this->assertSame(
            [$this->postId => 1],
            $this->repository->countNewerForPosts([$this->postId], '2026-01-01 11:00:00', [5])
        );
    }

    public function testCountNewerForPostsExcludesAHiddenReplyUnlessTheReaderModerates(): void
    {
        $visible = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'visible', '2026-01-01 12:00:00', 1, 9);
        $hidden = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'masquée', '2026-01-01 12:30:00', 1, 9);
        $this->repository->setHiddenAt($hidden, '2026-01-01 12:31:00');

        $this->assertSame(
            [$this->postId => 1],
            $this->repository->countNewerForPosts([$this->postId], '2026-01-01 11:00:00', [], false),
            'a badge must never announce something the thread would not show'
        );
        $this->assertSame(
            [$this->postId => 2],
            $this->repository->countNewerForPosts([$this->postId], '2026-01-01 11:00:00', [], true)
        );
        $this->assertNotSame(0, $visible);
    }

    public function testCountNewerForPostsLeavesOutAPostWithNothingNew(): void
    {
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'autre', '2026-01-01 10:00:00');
        GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'récente', '2026-01-01 12:00:00', 1, 9);
        GroupsTestHelper::createReplyAt($this->pdo, $otherPostId, 'ancienne', '2026-01-01 10:30:00', 1, 9);

        $counts = $this->repository->countNewerForPosts([$this->postId, $otherPostId], '2026-01-01 11:00:00');

        $this->assertSame([$this->postId => 1], $counts);
        $this->assertArrayNotHasKey($otherPostId, $counts, 'absent, never present with a zero');
    }

    public function testCountNewerForPostsAnswersEmptyForNoPosts(): void
    {
        $this->assertSame([], $this->repository->countNewerForPosts([], '2026-01-01 11:00:00'));
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

    /**
     * The LAST few, not the first: a thread of forty comments opened on
     * its five oldest is a thread nobody can use. Still returned
     * oldest-first, because that is the order they are read in once on
     * screen.
     */
    public function testFindLastForPostsKeepsTheEndOfTheThreadAndReportsTrueTotals(): void
    {
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'other', '2026-01-01 11:00:00');
        $this->seed(6);
        $this->seed(2, $otherPostId);

        $result = $this->repository->findLastForPosts([$this->postId, $otherPostId], 3);

        // The per-post limit is applied inside the database, so a post
        // with many replies still transfers only the tail of them.
        $this->assertSame(['reply 4', 'reply 5', 'reply 6'], $this->bodies($result['replies'][$this->postId]));
        $this->assertCount(2, $result['replies'][$otherPostId]);
        // …but the count is the real one, which is what decides whether
        // "Voir les commentaires précédents" appears.
        $this->assertSame(6, $result['counts'][$this->postId]);
        $this->assertSame(2, $result['counts'][$otherPostId]);
    }

    public function testFindLastForPostsOmitsAPostWithNoReplies(): void
    {
        $emptyPostId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'quiet', '2026-01-01 11:00:00');
        $this->seed(1);

        $result = $this->repository->findLastForPosts([$this->postId, $emptyPostId], 3);

        $this->assertArrayNotHasKey($emptyPostId, $result['replies']);
        $this->assertArrayNotHasKey($emptyPostId, $result['counts']);
    }

    public function testFindLastForPostsWithNoIdsReturnsEmptyWithoutQuerying(): void
    {
        $this->assertSame(['replies' => [], 'counts' => []], $this->repository->findLastForPosts([], 3));
    }

    /**
     * Paging backwards from the oldest reply on screen, and still handed
     * back oldest-first — what is fetched last is displayed first.
     */
    public function testFindPageBeforeWalksBackwardsAndComesBackInReadingOrder(): void
    {
        $this->seed(6);
        $all = $this->repository->findPage($this->postId, 10);
        $fourth = $all[3]->id;

        $page = $this->repository->findPageBefore($this->postId, 2, $fourth);

        $this->assertSame(['reply 2', 'reply 3'], $this->bodies($page));
    }

    public function testFindPageBeforeStopsAtTheStartOfTheThread(): void
    {
        $this->seed(3);
        $first = $this->repository->findPage($this->postId, 10)[0]->id;

        $this->assertSame([], $this->repository->findPageBefore($this->postId, 5, $first));
    }

    /**
     * The same rule the rest of this class follows: an auto-hidden reply
     * is excluded in the SQL for everyone but a moderator, so paging can
     * never be the way a member reaches one.
     */
    public function testFindPageBeforeHidesAnAutoHiddenReplyFromOrdinaryMembers(): void
    {
        $this->seed(4);
        $all = $this->repository->findPage($this->postId, 10);
        $this->pdo->prepare('UPDATE discussion_group_replies SET hidden_at = ? WHERE id = ?')
            ->execute(['2026-02-01 10:00:00', $all[1]->id]);

        $this->assertSame(
            ['reply 1', 'reply 3'],
            $this->bodies($this->repository->findPageBefore($this->postId, 5, $all[3]->id))
        );
        $this->assertSame(
            ['reply 1', 'reply 2', 'reply 3'],
            $this->bodies($this->repository->findPageBefore($this->postId, 5, $all[3]->id, true))
        );
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
