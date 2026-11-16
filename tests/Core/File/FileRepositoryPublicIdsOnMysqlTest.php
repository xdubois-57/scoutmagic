<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\FilePublicIds;
use Core\File\FileRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The retry of a refused public id, against the REAL engine.
 *
 * `FileRepository` writes a freshly minted public id with one prepared
 * `UPDATE` and, when the engine refuses it, executes the same statement
 * again with another id, three times in all. That needs the engine
 * production runs: SQLite will not execute a statement again after a
 * trigger refused it (« bad parameter or other API misuse »), so the
 * suite's in-memory database never reaches a second attempt.
 *
 * The refusal is a `SIGNAL` trigger. It counts the attempts in a session
 * variable, which the rollback of the refused statement does not undo.
 */
#[Group('database')]
final class FileRepositoryPublicIdsOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private FileRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new FileRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo)) {
            $this->pdo->exec('DROP TRIGGER IF EXISTS refuse_public_id');
        }
    }

    /** Two refusals, and the third attempt still gives the file its address. */
    public function testARefusedPublicIdIsRetriedWithAFreshOne(): void
    {
        $legacy = $this->legacyFile('retry.jpg');
        $this->refusePublicIdWrites(2);

        $found = $this->repository->mintPublicIdsFor([$legacy]);

        $this->assertSame(3, $this->attempts());
        $this->assertTrue(FilePublicIds::isWellFormed($found[$legacy] ?? ''));
        $this->assertSame($found[$legacy], $this->repository->findById($legacy)?->publicId);
    }

    /** Past the third refusal the failure is the engine's, and it comes out. */
    public function testAPublicIdRefusedThreeTimesIsReportedAndNothingIsWritten(): void
    {
        $legacy = $this->legacyFile('refused.jpg');
        $this->refusePublicIdWrites(99);

        try {
            $this->repository->mintPublicIdsFor([$legacy]);
            $this->fail('A write refused every time must be neither retried for ever nor swallowed.');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('public id refused', $e->getMessage());
        }

        $this->assertSame(3, $this->attempts());
        $this->assertNull($this->repository->findById($legacy)?->publicId);
    }

    /** A file that predates the public id column: its row has none. */
    private function legacyFile(string $name): int
    {
        $id = $this->repository->create($name, $name, 'image/jpeg', 1, 'public', null, null);
        $this->pdo->prepare('UPDATE files SET public_id = NULL WHERE id = ?')->execute([$id]);

        return $id;
    }

    /** Refuse the first $refusals writes of a public id, counting every attempt. */
    private function refusePublicIdWrites(int $refusals): void
    {
        $this->pdo->exec('SET @public_id_attempts = 0');
        $this->pdo->prepare('SET @public_id_refusals = ?')->execute([$refusals]);
        $this->pdo->exec(
            "CREATE TRIGGER refuse_public_id BEFORE UPDATE ON files FOR EACH ROW
             BEGIN
                 IF NEW.public_id IS NOT NULL THEN
                     SET @public_id_attempts = @public_id_attempts + 1;
                     IF @public_id_attempts <= @public_id_refusals THEN
                         SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'public id refused';
                     END IF;
                 END IF;
             END"
        );
    }

    private function attempts(): int
    {
        return (int) $this->pdo->query('SELECT @public_id_attempts')->fetchColumn();
    }
}
