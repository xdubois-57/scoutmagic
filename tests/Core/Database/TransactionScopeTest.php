<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\TransactionScope;
use PHPUnit\Framework\TestCase;

#[\PHPUnit\Framework\Attributes\Group('database')]
final class TransactionScopeTest extends TestCase
{
    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->pdo->exec('CREATE TABLE example (value INTEGER)');
    }

    public function testAStandaloneScopeCommitsAndReturnsItsResult(): void
    {
        $result = TransactionScope::run($this->pdo, function (): int {
            $this->pdo->exec('INSERT INTO example VALUES (1)');
            return 42;
        });
        $this->assertSame(42, $result);
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM example')->fetchColumn());
        $this->assertFalse($this->pdo->inTransaction());
    }

    public function testFailureRollsBackAndPreservesTheOriginalError(): void
    {
        $error = new \RuntimeException('failure');
        try {
            TransactionScope::run($this->pdo, function () use ($error): void {
                $this->pdo->exec('INSERT INTO example VALUES (1)');
                throw $error;
            });
            $this->fail('The failure must propagate.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM example')->fetchColumn());
    }

    public function testItJoinsWithoutCommittingOrRollingBackTheCallersTransaction(): void
    {
        $this->pdo->beginTransaction();
        TransactionScope::run($this->pdo, fn() => $this->pdo->exec('INSERT INTO example VALUES (1)'));
        $this->assertTrue($this->pdo->inTransaction());
        $this->pdo->rollBack();
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM example')->fetchColumn());
    }

    public function testAnAlreadyAbortedTransactionDoesNotReplaceTheOriginalError(): void
    {
        $error = new \RuntimeException('original failure');
        try {
            TransactionScope::run($this->pdo, function () use ($error): void {
                $this->pdo->rollBack();
                throw $error;
            });
            $this->fail('The failure must propagate.');
        } catch (\RuntimeException $caught) {
            $this->assertSame($error, $caught);
        }
    }

    public function testARollbackFailurePreservesTheWorkError(): void
    {
        $pdo = new class('sqlite::memory:') extends \PDO {
            public int $rollbackAttempts = 0;

            public function rollBack(): bool
            {
                ++$this->rollbackAttempts;
                throw new \PDOException('deliberate rollback failure');
            }
        };
        $error = new \RuntimeException('original work failure');
        $caught = null;
        try {
            TransactionScope::run($pdo, static fn() => throw $error);
        } catch (\Throwable $failure) {
            $caught = $failure;
        }
        $this->assertSame([$error, 1, true], [$caught, $pdo->rollbackAttempts, $pdo->inTransaction()]);
    }
}
