<?php

declare(strict_types=1);

namespace Tests\Core\Member\Duplicate;

use Core\Member\Duplicate\MergeRowRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1462, on the production engine: repointUnique() decides the winner
 * with a CASE over a self-join and counts with rowCount() (AGENTS.md §
 * Database). Run on a real module table, the reenrollment answers.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class MergeRowRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    /** The premise first (AGENTS.md § Database): this engine counts changed rows. */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $pdo = $this->productionEngine();
        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['MR-PROBE']);
        $member = (int) $pdo->lastInsertId();

        $this->assertSame(
            0,
            (new MergeRowRepository($pdo))->repoint('members', 'id', $member, $member),
            'the engine counts matched rows: the counts below prove nothing.'
        );
    }

    public function testTheLatestAnswerWinsAndTheCountsAreTruthful(): void
    {
        $pdo = $this->productionEngine();
        $rows = new MergeRowRepository($pdo);
        $member = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $member->execute(['MR-KEPT']);
        $kept = (int) $pdo->lastInsertId();
        $member->execute(['MR-MERGED']);
        $merged = (int) $pdo->lastInsertId();
        $year = $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)');
        $year->execute(['2030-2031', '2030-09-01', '2031-08-31']);
        $first = (int) $pdo->lastInsertId();
        $year->execute(['2031-2032', '2031-09-01', '2032-08-31']);
        $second = (int) $pdo->lastInsertId();
        $answer = $pdo->prepare(
            'INSERT INTO registration_reenrollments (member_id, scout_year_id, decision, answered_at)
             VALUES (?, ?, ?, ?)'
        );
        $answer->execute([$kept, $first, 'leaves', '2030-05-01 10:00:00']);
        $answer->execute([$merged, $first, 'stays', '2030-06-01 10:00:00']);
        $answer->execute([$merged, $second, 'stays', '2031-06-01 10:00:00']);

        $this->assertSame(
            ['handled' => 2, 'collisions' => 1],
            $rows->repointUnique(
                'registration_reenrollments',
                'member_id',
                ['scout_year_id'],
                'answered_at',
                $kept,
                $merged
            )
        );

        $decisions = $pdo->prepare(
            'SELECT scout_year_id, decision FROM registration_reenrollments WHERE member_id = ? ORDER BY scout_year_id'
        );
        $decisions->execute([$kept]);
        $this->assertSame([$first => 'stays', $second => 'stays'], $decisions->fetchAll(\PDO::FETCH_KEY_PAIR));
        $this->assertSame(0, $rows->count('registration_reenrollments', 'member_id', $merged));
    }

    /**
     * The compared rows are read FOR UPDATE inside the merge's transaction:
     * a write to the kept row has to wait until the merge is over.
     */
    public function testAWriteToAComparedRowWaitsForTheMergeToFinish(): void
    {
        $pdo = $this->productionEngine();
        $member = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $member->execute(['ML-KEPT']);
        $kept = (int) $pdo->lastInsertId();
        $member->execute(['ML-MERGED']);
        $merged = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES (?, ?, ?, 0)')
            ->execute(['2030-2031', '2030-09-01', '2031-08-31']);
        $year = (int) $pdo->lastInsertId();
        // The kept answer is the fresher one: the merge only READS it, and
        // deletes the other — so the kept row is held by FOR UPDATE alone.
        $answer = $pdo->prepare(
            'INSERT INTO registration_reenrollments (member_id, scout_year_id, decision, answered_at)
             VALUES (?, ?, ?, ?)'
        );
        $answer->execute([$kept, $year, 'stays', '2030-06-01 10:00:00']);
        $answer->execute([$merged, $year, 'leaves', '2030-05-01 10:00:00']);

        $rows = new MergeRowRepository($pdo);
        $other = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $pdo->beginTransaction();
        try {
            $this->assertSame(
                ['handled' => 1, 'collisions' => 1],
                $rows->repointUnique(
                    'registration_reenrollments',
                    'member_id',
                    ['scout_year_id'],
                    'answered_at',
                    $kept,
                    $merged
                )
            );

            try {
                $other->prepare("UPDATE registration_reenrollments SET decision = 'leaves' WHERE member_id = ?")
                    ->execute([$kept]);
                $this->fail('the row the merge compared was written while the merge still held it');
            } catch (\PDOException $e) {
                $this->assertSame('HY000', $e->getCode());
                $this->assertStringContainsString('1205', $e->getMessage(), 'a lock wait, nothing else');
            }
        } finally {
            $pdo->rollBack();
        }
    }
}
