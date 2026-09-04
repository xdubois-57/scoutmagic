<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\InstrumentedPdo;
use Core\Database\InstrumentedStatement;
use Core\Database\QueryCounter;
use PHPUnit\Framework\TestCase;

class InstrumentedPdoTest extends TestCase
{
    protected function setUp(): void
    {
        QueryCounter::reset();
    }

    private function pdo(): InstrumentedPdo
    {
        $pdo = new InstrumentedPdo('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }

    public function testExecAndQueryCountOneStatementEach(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE t (a INTEGER)');
        $this->assertSame(1, QueryCounter::count());

        $this->assertSame('0', (string) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
        $this->assertSame(2, QueryCounter::count());
    }

    public function testAPreparedStatementCountsOncePerExecuteNeverAtPrepare(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE t (a INTEGER)');
        QueryCounter::reset();

        $statement = $pdo->prepare('INSERT INTO t (a) VALUES (?)');
        $this->assertInstanceOf(InstrumentedStatement::class, $statement);
        $this->assertSame(0, QueryCounter::count(), 'prepare() is not a statement');

        $statement->execute([1]);
        $statement->execute([2]);
        $statement->execute([3]);

        $this->assertSame(3, QueryCounter::count());
        $this->assertSame('3', (string) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn());
    }

    public function testQueryWithAFetchModeStillReturnsRowsAndCounts(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE t (a INTEGER)');
        $pdo->exec('INSERT INTO t (a) VALUES (7)');
        QueryCounter::reset();

        $rows = $pdo->query('SELECT a FROM t', \PDO::FETCH_COLUMN, 0)->fetchAll();

        $this->assertSame([7], array_map('intval', $rows));
        $this->assertSame(1, QueryCounter::count());
    }

    public function testTimeIsAccumulatedAndResetClearsBoth(): void
    {
        $pdo = $this->pdo();
        $pdo->exec('CREATE TABLE t (a INTEGER)');

        $this->assertGreaterThanOrEqual(0.0, QueryCounter::milliseconds());
        QueryCounter::reset();
        $this->assertSame(0, QueryCounter::count());
        $this->assertSame(0.0, QueryCounter::milliseconds());
    }

    public function testAFailingStatementIsStillCountedAndStillThrows(): void
    {
        $pdo = $this->pdo();
        QueryCounter::reset();

        try {
            $pdo->exec('SELECT * FROM no_such_table');
            $this->fail('expected a PDOException');
        } catch (\PDOException) {
            $this->assertSame(1, QueryCounter::count());
        }
    }
}
