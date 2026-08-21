<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Modules\Groups\Repository\GroupRepository;
use Modules\Groups\Repository\ReactionRepository;
use Modules\Groups\Service\ReactorListService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Who reacted, and with what — grouped by reaction key in the fixed
 * display order, and named the way this whole module names people: the
 * account, then its memberships (Service\MemberIdentityService).
 *
 * The identity service here is the REAL one, resolving over real
 * user_accounts and member_years rows through the same blind-index join
 * production uses. A stub would only prove this class asks it politely.
 *
 * @group database
 */
#[Group('database')]
class ReactorListServiceTest extends TestCase
{
    private \PDO $pdo;
    private ReactionRepository $postReactions;
    private ReactionRepository $replyReactions;
    private ReactorListService $service;
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
        $this->service = new ReactorListService(
            $this->postReactions,
            $this->replyReactions,
            GroupsTestHelper::identityService($this->pdo)
        );
    }

    /**
     * @param array<int, array{first_name: string, totem?: string}> $members
     * @return int[] the seeded member ids
     */
    private function seed(string $email, string $firstName, string $lastName, array $members): array
    {
        return GroupsTestHelper::seedAccountWithMembers(
            $this->pdo,
            $email,
            $firstName,
            $lastName,
            $members
        )['member_ids'];
    }

    public function testGroupsReactorsByKeyInTheFixedDisplayOrder(): void
    {
        [$akela] = $this->seed('claire@example.test', 'Claire', 'Martin', [['first_name' => 'Claire', 'totem' => 'Akéla']]);
        [$baloo] = $this->seed('luc@example.test', 'Luc', 'Bernard', [['first_name' => 'Luc', 'totem' => 'Baloo']]);
        [$marie] = $this->seed('marie@example.test', 'Marie', 'Dupont', [['first_name' => 'Marie']]);

        // Support\Reactions::EMOJI's order is thumbs_up, heart, joy, wow,
        // sad, clap — set in a different order to prove the RESULT order
        // is not just the insertion order.
        $this->postReactions->set($this->postId, $akela, 'joy');
        $this->postReactions->set($this->postId, $baloo, 'thumbs_up');
        $this->postReactions->set($this->postId, $marie, 'joy');

        $groups = $this->service->forPost($this->postId, 1);

        $this->assertSame('thumbs_up', $groups[0]['key']);
        $this->assertSame(['Luc Bernard (Baloo)'], $groups[0]['names']);
        $this->assertSame('joy', $groups[1]['key']);
        $this->assertSame(['Claire Martin (Akéla)', 'Marie Dupont (Marie)'], $groups[1]['names']);
    }

    public function testIsEmptyWhenNobodyHasReacted(): void
    {
        $this->assertSame([], $this->service->forPost($this->postId, 1));
    }

    public function testDropsAReactorThereIsNothingLeftToNameThemBy(): void
    {
        // A member who has since left the unit: the reaction row is still
        // there, but there is neither a member_year for this scout year
        // nor an account behind them.
        [$baloo] = $this->seed('luc@example.test', 'Luc', 'Bernard', [['first_name' => 'Luc', 'totem' => 'Baloo']]);
        $this->postReactions->set($this->postId, 999, 'heart');
        $this->postReactions->set($this->postId, $baloo, 'heart');

        $groups = $this->service->forPost($this->postId, 1);

        $this->assertSame([['key' => 'heart', 'emoji' => '❤️', 'names' => ['Luc Bernard (Baloo)']]], $groups);
    }

    /**
     * A parent linked to two children in the same group is one human, and
     * one line — with both memberships already inside their own
     * parentheses. Listing them twice would say two people reacted.
     */
    public function testAParentWhoseTwoChildrenBothReactedIsListedOnce(): void
    {
        [$first, $second] = $this->seed('marie@example.test', 'Marie', 'Dupont', [
            ['first_name' => 'Léa', 'totem' => 'Akéla'],
            ['first_name' => 'Tom', 'totem' => 'Baloo'],
        ]);

        $this->postReactions->set($this->postId, $first, 'clap');
        $this->postReactions->set($this->postId, $second, 'clap');

        $groups = $this->service->forPost($this->postId, 1);

        $this->assertSame([['key' => 'clap', 'emoji' => '👏', 'names' => ['Marie Dupont (Akéla, Baloo)']]], $groups);
    }

    /**
     * Most animés have no account at all, and there is no human name to
     * put in front of them — their own display name is the whole answer.
     */
    public function testAMemberWithNoAccountIsNamedByTheirOwnDisplayName(): void
    {
        $memberId = GroupsTestHelper::createMember($this->pdo, 'NO-ACCOUNT');
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, totem_encrypted, is_active)
             VALUES (?, 1, ?, ?, ?, 1)'
        )->execute([
            $memberId,
            GroupsTestHelper::testEncryption()->encrypt('Chil', 'member_years.first_name'),
            GroupsTestHelper::testEncryption()->encrypt('Loup', 'member_years.last_name'),
            GroupsTestHelper::testEncryption()->encrypt('Chil', 'member_years.totem'),
        ]);

        $this->postReactions->set($this->postId, $memberId, 'wow');

        $this->assertSame([['key' => 'wow', 'emoji' => '😮', 'names' => ['Chil']]], $this->service->forPost($this->postId, 1));
    }

    public function testForReplyReadsFromTheReplyTableNotThePostTable(): void
    {
        [$akela] = $this->seed('claire@example.test', 'Claire', 'Martin', [['first_name' => 'Claire', 'totem' => 'Akéla']]);

        $this->replyReactions->set($this->replyId, $akela, 'clap');
        $this->postReactions->set($this->postId, $akela, 'wow');

        $groups = $this->service->forReply($this->replyId, 1);

        $this->assertSame([['key' => 'clap', 'emoji' => '👏', 'names' => ['Claire Martin (Akéla)']]], $groups);
    }
}
