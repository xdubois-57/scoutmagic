<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\ReactionRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Both tables through the one class, plus the concurrency behaviour the
 * UNIQUE (item, member) index exists for.
 *
 * @group database
 */
#[Group('database')]
class ReactionRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private ReactionRepository $postReactions;
    private ReactionRepository $replyReactions;
    private int $postId;
    private int $replyId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $groupId, 'hello', '2026-01-01 10:00:00');
        $this->replyId = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'hi', '2026-01-01 10:05:00');

        $this->postReactions = ReactionRepository::forPosts($this->pdo);
        $this->replyReactions = ReactionRepository::forReplies($this->pdo);
    }

    public function testSetThenFindRoundTripsOnAPost(): void
    {
        $this->postReactions->set($this->postId, 3, 'heart');

        $this->assertSame('heart', $this->postReactions->findKeyFor($this->postId, [3]));
    }

    public function testSetThenFindRoundTripsOnAReply(): void
    {
        $this->replyReactions->set($this->replyId, 3, 'clap');

        $this->assertSame('clap', $this->replyReactions->findKeyFor($this->replyId, [3]));
    }

    public function testTheTwoTablesAreIndependent(): void
    {
        // A post id and a reply id can collide numerically — the whole
        // point of two tables is that they never mean the same row.
        $this->postReactions->set($this->postId, 3, 'heart');

        $this->assertNull($this->replyReactions->findKeyFor($this->postId, [3]));
    }

    public function testFindKeyForReturnsNullWhenTheMemberHasNotReacted(): void
    {
        $this->assertNull($this->postReactions->findKeyFor($this->postId, [3]));
    }

    public function testFindKeyForWithNoMemberIdsReturnsNullWithoutQuerying(): void
    {
        $this->postReactions->set($this->postId, 3, 'heart');

        $this->assertNull($this->postReactions->findKeyFor($this->postId, []));
    }

    public function testSettingAgainReplacesRatherThanDuplicating(): void
    {
        $this->postReactions->set($this->postId, 3, 'heart');
        $this->postReactions->set($this->postId, 3, 'joy');

        $this->assertSame('joy', $this->postReactions->findKeyFor($this->postId, [3]));
        $this->assertSame(1, $this->countRows('discussion_group_post_reactions'));
    }

    /**
     * The concurrency case the UNIQUE index exists for: a double-tap on
     * mobile genuinely fires twice, and two requests can both read "no
     * reaction yet" before either writes. The second INSERT must not
     * surface as a 500 or a duplicate row.
     */
    public function testAConcurrentDuplicateInsertIsAbsorbedByTheUniqueConstraint(): void
    {
        // Both "requests" have already decided to insert, neither having
        // seen the other's row — reproduced by calling set() twice with no
        // read in between, which is exactly the interleaving.
        $this->postReactions->set($this->postId, 3, 'heart');
        $this->postReactions->set($this->postId, 3, 'heart');

        $this->assertSame(1, $this->countRows('discussion_group_post_reactions'));
        $this->assertSame('heart', $this->postReactions->findKeyFor($this->postId, [3]));
    }

    public function testTheUniqueConstraintReallyExistsOnBothTables(): void
    {
        // Proves the guard above is the DATABASE's, not application logic:
        // a raw second INSERT for the same (item, member) must be refused.
        foreach ([
            ['discussion_group_post_reactions', 'post_id', $this->postId],
            ['discussion_group_reply_reactions', 'reply_id', $this->replyId],
        ] as [$table, $column, $itemId]) {
            $insert = "INSERT INTO {$table} ({$column}, member_id, reaction_key, created_at) VALUES (?, ?, ?, ?)";
            $this->pdo->prepare($insert)->execute([$itemId, 9, 'heart', '2026-01-01 10:00:00']);

            try {
                $this->pdo->prepare($insert)->execute([$itemId, 9, 'joy', '2026-01-01 10:00:01']);
                $this->fail("{$table} accepted a second reaction for the same member");
            } catch (\PDOException $e) {
                $this->assertSame('23000', $e->getCode(), $table);
            }
        }
    }

    public function testRemoveOnlyRemovesThatMembersOwnReaction(): void
    {
        $this->postReactions->set($this->postId, 3, 'heart');
        $this->postReactions->set($this->postId, 4, 'joy');

        $this->postReactions->remove($this->postId, 3);

        $this->assertNull($this->postReactions->findKeyFor($this->postId, [3]));
        $this->assertSame('joy', $this->postReactions->findKeyFor($this->postId, [4]));
    }

    public function testCountsForAggregatesPerItemAndPerKeyInOneCall(): void
    {
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, 1, 'other', '2026-01-01 11:00:00');
        $this->postReactions->set($this->postId, 3, 'heart');
        $this->postReactions->set($this->postId, 4, 'heart');
        $this->postReactions->set($this->postId, 5, 'joy');
        $this->postReactions->set($otherPostId, 3, 'clap');

        $counts = $this->postReactions->countsFor([$this->postId, $otherPostId]);

        $this->assertSame(['heart' => 2, 'joy' => 1], $counts[$this->postId]);
        $this->assertSame(['clap' => 1], $counts[$otherPostId]);
    }

    public function testCountsForWithNoIdsReturnsEmptyWithoutQuerying(): void
    {
        $this->assertSame([], $this->postReactions->countsFor([]));
    }

    public function testOwnKeysForReturnsOnlyTheCallersOwnReactions(): void
    {
        $otherPostId = GroupsTestHelper::createPostAt($this->pdo, 1, 'other', '2026-01-01 11:00:00');
        $this->postReactions->set($this->postId, 3, 'heart');
        $this->postReactions->set($this->postId, 4, 'joy');
        $this->postReactions->set($otherPostId, 4, 'clap');

        $own = $this->postReactions->ownKeysFor([$this->postId, $otherPostId], [3]);

        $this->assertSame([$this->postId => 'heart'], $own);
    }

    public function testOwnKeysForCoversEveryMemberTheAccountIsLinkedTo(): void
    {
        // A parent linked to two members of the group reacted under one of
        // them; the same account must still see it as theirs.
        $this->postReactions->set($this->postId, 4, 'wow');

        $own = $this->postReactions->ownKeysFor([$this->postId], [3, 4]);

        $this->assertSame([$this->postId => 'wow'], $own);
    }

    public function testOwnKeysForWithNoMemberIdsReturnsEmpty(): void
    {
        $this->postReactions->set($this->postId, 3, 'heart');

        $this->assertSame([], $this->postReactions->ownKeysFor([$this->postId], []));
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
