<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\Duplicate\MergeRowRepository;
use Modules\Groups\Service\GroupMergeListener;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Groups\GroupsTestHelper;

/**
 * Issue #1462: an explicit group membership granted to the absorbed
 * identity is still there after the merge, and a membership held by both
 * keeps the moderator one.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class GroupMergeListenerTest extends TestCase
{
    private \PDO $pdo;
    private GroupMergeListener $listener;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        GroupsTestHelper::createTables($this->pdo);
        $this->listener = new GroupMergeListener(new MergeRowRepository($this->pdo));
        $this->pdo->exec("INSERT INTO discussion_groups (name) VALUES ('Patrouille'), ('Camp')");
    }

    /** @return array<int, int> group => is_moderator, for $member */
    private function membershipsOf(int $member): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT group_id, is_moderator FROM discussion_group_members WHERE member_id = ? ORDER BY group_id'
        );
        $stmt->execute([$member]);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_KEY_PAIR));
    }

    public function testAMembershipFollowsAndASharedOneKeepsTheModerator(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO discussion_group_members (group_id, member_id, is_moderator) VALUES (?, ?, ?)'
        );
        $insert->execute([1, 10, 0]);
        $insert->execute([1, 20, 1]);
        $insert->execute([2, 20, 0]);

        $this->assertSame(
            2,
            $this->listener->mergePreview(20)['appartenance(s) à un groupe de discussion']
        );
        $this->listener->onMembersMerged(10, 20);

        $this->assertSame([1 => 1, 2 => 0], $this->membershipsOf(10));
        $this->assertSame([], $this->membershipsOf(20));
    }
}
