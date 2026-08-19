<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\MemberService;
use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\ReactionRepository;
use Modules\Groups\Service\ReactorListService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Who reacted, and with what — grouped by reaction key in the fixed
 * display order, names resolved through MemberService like every other
 * author label in this module.
 *
 * @group database
 */
#[Group('database')]
class ReactorListServiceTest extends TestCase
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

    /**
     * @param array<int, string> $names member id => display name
     */
    private function service(array $names): ReactorListService
    {
        $memberService = $this->createMock(MemberService::class);
        $memberService->method('findDisplayNamesByMemberIds')->willReturn($names);

        return new ReactorListService($this->postReactions, $this->replyReactions, $memberService);
    }

    public function testGroupsReactorsByKeyInTheFixedDisplayOrder(): void
    {
        // Support\Reactions::EMOJI's order is thumbs_up, heart, joy, wow,
        // sad, clap — set in a different order to prove the RESULT order
        // is not just the insertion order.
        $this->postReactions->set($this->postId, 3, 'joy');
        $this->postReactions->set($this->postId, 4, 'thumbs_up');
        $this->postReactions->set($this->postId, 5, 'joy');

        $groups = $this->service([3 => 'Akéla', 4 => 'Baloo', 5 => 'Marie Dupont'])->forPost($this->postId, 1);

        $this->assertSame('thumbs_up', $groups[0]['key']);
        $this->assertSame(['Baloo'], $groups[0]['names']);
        $this->assertSame('joy', $groups[1]['key']);
        $this->assertSame(['Akéla', 'Marie Dupont'], $groups[1]['names']);
    }

    public function testIsEmptyWhenNobodyHasReacted(): void
    {
        $this->assertSame([], $this->service([])->forPost($this->postId, 1));
    }

    public function testDropsAReactorWithNoDisplayNameForThisScoutYear(): void
    {
        // A member who has since left the unit — the reaction row is
        // still there, but MemberService has nothing to name them with.
        $this->postReactions->set($this->postId, 3, 'heart');
        $this->postReactions->set($this->postId, 4, 'heart');

        $groups = $this->service([4 => 'Baloo'])->forPost($this->postId, 1);

        $this->assertSame([['key' => 'heart', 'emoji' => '❤️', 'names' => ['Baloo']]], $groups);
    }

    public function testForReplyReadsFromTheReplyTableNotThePostTable(): void
    {
        $this->replyReactions->set($this->replyId, 3, 'clap');
        $this->postReactions->set($this->postId, 3, 'wow');

        $groups = $this->service([3 => 'Akéla'])->forReply($this->replyId, 1);

        $this->assertSame([['key' => 'clap', 'emoji' => '👏', 'names' => ['Akéla']]], $groups);
    }
}
