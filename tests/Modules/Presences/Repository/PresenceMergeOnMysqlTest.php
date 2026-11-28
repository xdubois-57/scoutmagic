<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Repository;

use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1462, on the production engine: PresenceRepository::mergeMember()
 * compares two timestamps in SQL and counts what moved with rowCount()
 * (AGENTS.md § Database).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PresenceMergeOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    /** The premise first (AGENTS.md § Database): this engine counts changed rows. */
    public function testThisEngineReportsChangedRowsRatherThanMatchedOnes(): void
    {
        $pdo = $this->productionEngine();
        $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)')->execute(['PM-PROBE']);
        $member = (int) $pdo->lastInsertId();
        $pdo->prepare('INSERT INTO presences_records (calendar_event_id, member_id, status) VALUES (?, ?, ?)')
            ->execute([99, $member, 'present']);

        $update = $pdo->prepare('UPDATE presences_records SET member_id = ? WHERE member_id = ?');
        $update->execute([$member, $member]);

        $this->assertSame(0, $update->rowCount(), 'the engine counts matched rows: the counts below prove nothing.');
    }

    public function testTheLatestDecisionWinsAndTheCountsAreTruthful(): void
    {
        $pdo = $this->productionEngine();
        $repository = new PresenceRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $insertMember = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $insertMember->execute(['PM-KEPT']);
        $kept = (int) $pdo->lastInsertId();
        $insertMember->execute(['PM-MERGED']);
        $merged = (int) $pdo->lastInsertId();
        $row = $pdo->prepare(
            'INSERT INTO presences_records (calendar_event_id, member_id, status, updated_at) VALUES (?, ?, ?, ?)'
        );
        $row->execute([10, $kept, 'absent', '2026-01-01 10:00:00']);
        $row->execute([10, $merged, 'present', '2026-01-02 10:00:00']);
        $row->execute([11, $kept, 'excused', '2026-01-05 10:00:00']);
        $row->execute([11, $merged, 'absent', '2026-01-03 10:00:00']);
        $row->execute([12, $merged, 'present', '2026-01-04 10:00:00']);

        $this->assertSame(['handled' => 3, 'collisions' => 2], $repository->mergeMember($kept, $merged));

        $statuses = $pdo->prepare(
            'SELECT calendar_event_id, status FROM presences_records WHERE member_id = ? ORDER BY calendar_event_id'
        );
        $statuses->execute([$kept]);
        $this->assertSame(
            [10 => 'present', 11 => 'excused', 12 => 'present'],
            $statuses->fetchAll(\PDO::FETCH_KEY_PAIR)
        );
        $this->assertSame(0, $repository->countForMember($merged));
    }
}
