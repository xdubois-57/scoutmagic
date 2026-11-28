<?php

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments\Repository;

use Core\Security\EncryptionService;
use Modules\OfficialDocuments\Repository\HealthSheetRepository;
use Modules\OfficialDocuments\Value\HealthSheet;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Issue #1462, on the production engine: mergeMember() reads the two sheets
 * it compares FOR UPDATE, inside the merge's transaction, so a family's
 * save or a document's touch() cannot slip in between the comparison and
 * the delete. Shown with a second connection whose write has to wait.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class HealthSheetMergeLockOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    public function testAWriteToAComparedSheetWaitsForTheMergeToFinish(): void
    {
        $pdo = $this->productionEngine();
        $sheets = new HealthSheetRepository(
            $pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $member = $pdo->prepare('INSERT INTO members (desk_id) VALUES (?)');
        $member->execute(['HL-KEPT']);
        $kept = (int) $pdo->lastInsertId();
        $member->execute(['HL-MERGED']);
        $merged = (int) $pdo->lastInsertId();
        // The kept sheet is the fresher one: the merge only READS it, and
        // deletes the other — so the kept row is held by FOR UPDATE alone.
        $sheets->save($kept, HealthSheet::fromArray(['allergies' => 'a']), new \DateTimeImmutable('2026-02-01'));
        $sheets->save($merged, HealthSheet::fromArray(['allergies' => 'b']), new \DateTimeImmutable('2026-01-01'));

        $other = self::productionEngineConnection(self::$productionEngineDatabase)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $pdo->beginTransaction();
        try {
            $this->assertSame('kept_existing', $sheets->mergeMember($kept, $merged));

            try {
                $other->prepare(
                    'UPDATE official_documents_health_sheets SET last_used_at = NOW() WHERE member_id = ?'
                )->execute([$kept]);
                $this->fail('the sheet the merge compared was written while the merge still held it');
            } catch (\PDOException $e) {
                $this->assertSame('HY000', $e->getCode());
                $this->assertStringContainsString('1205', $e->getMessage(), 'a lock wait, nothing else');
            }
        } finally {
            $pdo->rollBack();
        }
    }
}
