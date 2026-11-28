<?php

declare(strict_types=1);

namespace Tests\Modules\Presences\Repository;

use Core\Security\EncryptionService;
use Modules\Presences\Repository\PresenceRepository;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1462, on the production engine: mergeMember() reads the evenings
 * it compares FOR UPDATE, inside the merge's transaction, so a chef's
 * saveStatus() cannot slip in between the comparison and the delete. Shown
 * with a second connection whose write has to wait.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class PresenceMergeLockOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testAWriteToAComparedEveningWaitsForTheMergeToFinish(): void
    {
        $pdo = $this->productionEngine();
        $presences = new PresenceRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $member = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $member->execute(['PL-KEPT']);
        $kept = (int) $pdo->lastInsertId();
        $member->execute(['PL-MERGED']);
        $merged = (int) $pdo->lastInsertId();
        // The kept evening is the fresher one: the merge only READS it, and
        // deletes the other — so the kept row is held by FOR UPDATE alone.
        $row = $pdo->prepare(
            'INSERT INTO presences_records (calendar_event_id, member_id, status, updated_at) VALUES (?, ?, ?, ?)'
        );
        $row->execute([10, $kept, 'present', '2026-01-02 10:00:00']);
        $row->execute([10, $merged, 'absent', '2026-01-01 10:00:00']);

        $other = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $pdo->beginTransaction();
        try {
            $this->assertSame(['handled' => 1, 'collisions' => 1], $presences->mergeMember($kept, $merged));

            try {
                $other->prepare("UPDATE presences_records SET status = 'excused' WHERE member_id = ?")
                    ->execute([$kept]);
                $this->fail('the evening the merge compared was written while the merge still held it');
            } catch (\PDOException $e) {
                $this->assertSame('HY000', $e->getCode());
                $this->assertStringContainsString('1205', $e->getMessage(), 'a lock wait, nothing else');
            }
        } finally {
            $pdo->rollBack();
        }
    }
}
