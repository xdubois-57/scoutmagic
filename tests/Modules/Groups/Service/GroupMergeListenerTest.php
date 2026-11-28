<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Service;

use Core\Member\Duplicate\MergeRowRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Modules\Groups\Repository\GroupMemberRepository;
use Modules\Groups\Repository\PollRepository;
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
        $this->listener = new GroupMergeListener(
            new MergeRowRepository($this->pdo),
            new PollRepository($this->pdo),
            new GroupMemberRepository($this->pdo),
            new JournalService(new JournalRepository($this->pdo))
        );
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

    /**
     * A ballot is keyed `m:<member id>`: the key follows the merge, so the
     * kept member sees and can withdraw the vote, and a poll both
     * identities answered keeps the kept identity's answer — one voter,
     * counted once.
     */
    public function testBallotsFollowByTheirKeyAndASharedPollKeepsTheKeptIdentitysAnswer(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        $polls = new PollRepository($this->pdo);
        $this->pdo->exec(
            "INSERT INTO discussion_group_polls (id, post_id, question, vote_scope, allow_multiple, created_at)
             VALUES (1, 1, 'Date ?', 'member', 0, '2026-11-01'), (2, 2, 'Lieu ?', 'member', 0, '2026-11-01')"
        );
        $this->pdo->exec(
            "INSERT INTO discussion_group_poll_options (id, poll_id, label, position)
             VALUES (11, 1, 'Samedi', 1), (12, 1, 'Dimanche', 2), (21, 2, 'Bois', 1)"
        );
        $polls->castBallot(1, 11, 'm:10', null, 10, false, '2026-11-02 10:00:00');
        $polls->castBallot(1, 12, 'm:20', null, 20, false, '2026-11-03 10:00:00');
        $polls->castBallot(2, 21, 'm:20', null, 20, false, '2026-11-03 10:00:00');

        $this->listener->onMembersMerged(10, 20);

        $this->assertSame(
            [[1, 11, 10], [2, 21, 10]],
            array_map(
                static fn(array $row): array => array_map('intval', $row),
                $this->pdo->query(
                    "SELECT poll_id, option_id, voter_member_id FROM discussion_group_poll_ballots
                     WHERE voter_key = 'm:10' ORDER BY poll_id"
                )->fetchAll(\PDO::FETCH_NUM)
            )
        );
        $this->assertSame(0, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM discussion_group_poll_ballots WHERE voter_key = 'm:20' OR voter_member_id = 20"
        )->fetchColumn());
    }

    /**
     * Both identities moderate the group, but only the absorbed one's grant
     * is bound to an account: the kept membership takes that binding, or
     * the merge would leave a grant that moderates nothing. The collision
     * is journalled, ids and counts only.
     */
    public function testAWorkingModeratorGrantSurvivesAndTheCollisionIsJournalled(): void
    {
        $this->pdo->exec(
            'INSERT INTO discussion_group_members (group_id, member_id, is_moderator, moderator_user_account_id)
             VALUES (1, 10, 1, NULL), (1, 20, 1, 7)'
        );

        $this->listener->onMembersMerged(10, 20);

        $this->assertSame(7, (int) $this->pdo->query(
            'SELECT moderator_user_account_id FROM discussion_group_members WHERE group_id = 1 AND member_id = 10'
        )->fetchColumn());
        $context = json_decode((string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'groups_merge_collisions'"
        )->fetchColumn(), true);
        $this->assertSame(
            ['kept_member_id' => 10, 'merged_member_id' => 20, 'collisions' => ['discussion_group_members' => 1]],
            $context
        );
    }
}
