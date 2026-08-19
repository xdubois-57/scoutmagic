<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\DiscussionGroup;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\PostRepository;
use Modules\Groups\Repository\ReactionRepository;
use Modules\Groups\Service\GroupActivityService;
use Modules\Groups\Service\ReactionService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Toggle, replace and remove — for posts and for replies — plus the
 * server-side key validation and the activity bump.
 *
 * @group database
 */
#[Group('database')]
class ReactionServiceTest extends TestCase
{
    private \PDO $pdo;
    private GroupRepository $groupRepo;
    private PostRepository $postRepo;
    private ReactionRepository $postReactions;
    private ReactionRepository $replyReactions;
    private ReactionService $service;
    private int $groupId;
    private int $postId;
    private int $replyId;

    private const MEMBER = 3;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupRepo = new GroupRepository($this->pdo);
        $this->postRepo = new PostRepository($this->pdo);
        $this->groupId = $this->groupRepo->create('Louveteaux', null, null, 1);
        $this->postId = GroupsTestHelper::createPostAt($this->pdo, $this->groupId, 'hello', '2026-01-01 10:00:00');
        $this->replyId = GroupsTestHelper::createReplyAt($this->pdo, $this->postId, 'hi', '2026-01-01 10:05:00');

        $this->postReactions = ReactionRepository::forPosts($this->pdo);
        $this->replyReactions = ReactionRepository::forReplies($this->pdo);
        $this->service = new ReactionService(
            $this->postReactions,
            $this->replyReactions,
            new GroupActivityService($this->groupRepo, $this->postRepo)
        );
    }

    private function group(): DiscussionGroup
    {
        return $this->groupRepo->findById($this->groupId);
    }

    public function testAFirstReactionOnAPostIsRecorded(): void
    {
        $this->assertTrue($this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart'));

        $this->assertSame('heart', $this->postReactions->findKeyFor($this->postId, [self::MEMBER]));
    }

    public function testClickingTheSameReactionAgainRemovesIt(): void
    {
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');

        $this->assertNull($this->postReactions->findKeyFor($this->postId, [self::MEMBER]));
    }

    public function testClickingADifferentReactionReplacesIt(): void
    {
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'joy');

        $this->assertSame('joy', $this->postReactions->findKeyFor($this->postId, [self::MEMBER]));
        $this->assertSame(1, $this->countRows('discussion_group_post_reactions'));
    }

    public function testRemovingThenReactingAgainWorks(): void
    {
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'clap');

        $this->assertSame('clap', $this->postReactions->findKeyFor($this->postId, [self::MEMBER]));
    }

    public function testTheSameThreeWayToggleWorksOnAReply(): void
    {
        $this->service->toggleOnReply($this->group(), $this->replyId, $this->postId, self::MEMBER, 'wow');
        $this->assertSame('wow', $this->replyReactions->findKeyFor($this->replyId, [self::MEMBER]));

        $this->service->toggleOnReply($this->group(), $this->replyId, $this->postId, self::MEMBER, 'sad');
        $this->assertSame('sad', $this->replyReactions->findKeyFor($this->replyId, [self::MEMBER]));

        $this->service->toggleOnReply($this->group(), $this->replyId, $this->postId, self::MEMBER, 'sad');
        $this->assertNull($this->replyReactions->findKeyFor($this->replyId, [self::MEMBER]));
    }

    public function testDifferentMembersReactIndependently(): void
    {
        $this->service->toggleOnPost($this->group(), $this->postId, 3, 'heart');
        $this->service->toggleOnPost($this->group(), $this->postId, 4, 'joy');

        $this->assertSame('heart', $this->postReactions->findKeyFor($this->postId, [3]));
        $this->assertSame('joy', $this->postReactions->findKeyFor($this->postId, [4]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'unknown key' => ['rocket'];
        yield 'empty' => [''];
        yield 'raw emoji instead of a key' => ['👍'];
        yield 'case mismatch' => ['HEART'];
        yield 'injection-ish' => ["heart'); DROP TABLE discussion_group_post_reactions;--"];
    }

    #[DataProvider('invalidKeys')]
    public function testAnInvalidKeyIsRefusedAndWritesNothingOnAPost(string $key): void
    {
        $this->assertFalse($this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, $key));

        $this->assertSame(0, $this->countRows('discussion_group_post_reactions'));
    }

    #[DataProvider('invalidKeys')]
    public function testAnInvalidKeyIsRefusedAndWritesNothingOnAReply(string $key): void
    {
        $this->assertFalse($this->service->toggleOnReply($this->group(), $this->replyId, $this->postId, self::MEMBER, $key));

        $this->assertSame(0, $this->countRows('discussion_group_reply_reactions'));
    }

    public function testAnInvalidKeyNeverBumpsActivityEither(): void
    {
        $before = $this->postRepo->findById($this->postId)->lastActivityAt;

        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'rocket');

        $this->assertSame($before, $this->postRepo->findById($this->postId)->lastActivityAt);
    }

    public function testAnInvalidKeyLeavesAnExistingReactionUntouched(): void
    {
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');

        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'rocket');

        $this->assertSame('heart', $this->postReactions->findKeyFor($this->postId, [self::MEMBER]));
    }

    public function testReactingOnAPostBumpsThePostAndGroupActivity(): void
    {
        $before = $this->postRepo->findById($this->postId)->lastActivityAt;

        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');

        $this->assertGreaterThan($before, $this->postRepo->findById($this->postId)->lastActivityAt);
        $this->assertGreaterThan($before, $this->group()->lastActivityAt);
    }

    /**
     * A reply has no last_activity_at of its own — the conversation's
     * freshness is the post's, so reacting to a reply bumps the POST.
     */
    public function testReactingOnAReplyBumpsItsPostNotJustTheGroup(): void
    {
        $before = $this->postRepo->findById($this->postId)->lastActivityAt;

        $this->service->toggleOnReply($this->group(), $this->replyId, $this->postId, self::MEMBER, 'clap');

        $this->assertGreaterThan($before, $this->postRepo->findById($this->postId)->lastActivityAt);
    }

    public function testRemovingAReactionAlsoCountsAsActivity(): void
    {
        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');
        $this->postRepo->touchActivity($this->postId, '2020-01-01 00:00:00');

        $this->service->toggleOnPost($this->group(), $this->postId, self::MEMBER, 'heart');

        $this->assertGreaterThan('2020-01-01 00:00:00', $this->postRepo->findById($this->postId)->lastActivityAt);
    }

    public function testForPostsAggregatesCountsAndTheCallersOwnReaction(): void
    {
        $this->service->toggleOnPost($this->group(), $this->postId, 3, 'heart');
        $this->service->toggleOnPost($this->group(), $this->postId, 4, 'heart');
        $this->service->toggleOnPost($this->group(), $this->postId, 5, 'joy');

        $result = $this->service->forPosts([$this->postId], [3]);

        $this->assertSame(['heart' => 2, 'joy' => 1], $result['counts'][$this->postId]);
        $this->assertSame('heart', $result['own'][$this->postId]);
    }

    public function testForRepliesAggregatesTheSameWay(): void
    {
        $this->service->toggleOnReply($this->group(), $this->replyId, $this->postId, 3, 'clap');

        $result = $this->service->forReplies([$this->replyId], [3]);

        $this->assertSame(['clap' => 1], $result['counts'][$this->replyId]);
        $this->assertSame('clap', $result['own'][$this->replyId]);
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
