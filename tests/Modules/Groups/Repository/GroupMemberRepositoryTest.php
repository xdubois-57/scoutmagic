<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\GroupRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * The two guarded writes — leaving the group, and losing the moderator
 * flag — when the database refuses the write itself. Each runs its count
 * and its write in one transaction, so a refusal must leave the row as it
 * was AND hand the connection back with no transaction still open: the
 * next statement of the request would otherwise run inside a transaction
 * nobody will ever commit.
 *
 * Their ordinary rules (the last bound moderator stays) are covered
 * through Service\GroupService, in GroupServiceTest.
 *
 * @group database
 */
#[Group('database')]
class GroupMemberRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private GroupMemberRepository $repository;
    private int $groupId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);

        $this->groupId = (new GroupRepository($this->pdo))->create('Louveteaux', null, null, 1);
        $this->repository = new GroupMemberRepository($this->pdo);
    }

    public function testARemovalTheDatabaseRefusesLeavesTheMemberAndNoOpenTransaction(): void
    {
        $this->repository->add($this->groupId, 5);
        $this->pdo->exec(
            "CREATE TRIGGER refuse_member_delete BEFORE DELETE ON discussion_group_members
             BEGIN SELECT RAISE(ABORT, 'members are read-only'); END"
        );

        try {
            $this->repository->removeUnlessLastModerator($this->groupId, 5);
            $this->fail('A refused DELETE must reach the caller.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('members are read-only', $e->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction(), 'the refused removal must roll its transaction back');
        $this->assertNotNull($this->repository->find($this->groupId, 5));
    }

    public function testADemotionTheDatabaseRefusesKeepsTheFlagAndNoOpenTransaction(): void
    {
        $this->repository->add($this->groupId, 5, true, null, 50);
        $this->repository->add($this->groupId, 6, true, null, 60);
        $this->pdo->exec(
            "CREATE TRIGGER refuse_member_update BEFORE UPDATE ON discussion_group_members
             BEGIN SELECT RAISE(ABORT, 'members are read-only'); END"
        );

        try {
            $this->repository->demoteUnlessLastModerator($this->groupId, 5);
            $this->fail('A refused UPDATE must reach the caller.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('members are read-only', $e->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction(), 'the refused demotion must roll its transaction back');
        $this->assertTrue($this->repository->find($this->groupId, 5)?->isModerator);
        $this->assertSame(2, $this->repository->countModerators($this->groupId));
    }
}
