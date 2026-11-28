<?php

declare(strict_types=1);

namespace Tests\Modules\Groups\Repository;

use Modules\Groups\Repository\PollRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1462, on the production engine: PollRepository::mergeVoter() reads
 * the shared polls FOR UPDATE and counts what it moved with rowCount()
 * (AGENTS.md § Database).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PollBallotMergeOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private function seed(\PDO $pdo): void
    {
        $pdo->exec("INSERT INTO members (id, desk_id) VALUES (10, 'PB-KEPT'), (20, 'PB-MERGED')");
        // The ballots are what is under test; the group and post they hang
        // from are not, so the keys those would need are not checked while
        // the fixture is written.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $pdo->exec(
            "INSERT INTO discussion_group_polls (id, post_id, question, vote_scope, allow_multiple, created_at)
             VALUES (1, 1, 'Date ?', 'member', 0, NOW()), (2, 2, 'Lieu ?', 'member', 0, NOW())"
        );
        $pdo->exec(
            "INSERT INTO discussion_group_poll_options (id, poll_id, label, position)
             VALUES (11, 1, 'Samedi', 1), (12, 1, 'Dimanche', 2), (21, 2, 'Bois', 1)"
        );
        $pdo->exec(
            "INSERT INTO discussion_group_poll_ballots (poll_id, option_id, voter_key, voter_member_id, created_at)
             VALUES (1, 11, 'm:10', 10, NOW()), (1, 12, 'm:20', 20, NOW()), (2, 21, 'm:20', 20, NOW())"
        );
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** The premise first (AGENTS.md § Database): this engine counts changed rows. */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $pdo = $this->productionEngine();
        $this->seed($pdo);

        $update = $pdo->prepare('UPDATE discussion_group_poll_ballots SET voter_key = ? WHERE voter_key = ?');
        $update->execute(['m:10', 'm:10']);

        $this->assertSame(0, $update->rowCount(), 'the engine counts matched rows: the counts below prove nothing.');
    }

    public function testASharedPollKeepsTheKeptAnswerAndTheCountsAreTruthful(): void
    {
        $pdo = $this->productionEngine();
        $this->seed($pdo);

        $pdo->beginTransaction();
        $this->assertSame(
            ['handled' => 2, 'collisions' => 1],
            (new PollRepository($pdo))->mergeVoter(10, 20)
        );
        $pdo->commit();

        $this->assertSame(
            [[1, 11, 10], [2, 21, 10]],
            array_map(
                static fn(array $row): array => array_map('intval', $row),
                $pdo->query(
                    "SELECT poll_id, option_id, voter_member_id FROM discussion_group_poll_ballots
                     WHERE voter_key = 'm:10' ORDER BY poll_id"
                )->fetchAll(\PDO::FETCH_NUM)
            )
        );
    }
}
