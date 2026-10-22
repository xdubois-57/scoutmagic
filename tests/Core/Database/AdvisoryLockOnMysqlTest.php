<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\AdvisoryLock;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\UsesProductionEngine;

/**
 * A named lock holds for one site's database, not for the whole server.
 *
 * `GET_LOCK()` names are server-wide, and shared hosting puts many
 * customers' databases on one MySQL server: with the bare names, one
 * ScoutMagic site's cron pass or schema migration made every other site on
 * that server stand down. Two PHPUnit runs on two databases of one server
 * tripped over each other the same way. So these tests sit on two
 * databases — `TEST_DB_NAME` and a bare one created for the class — and
 * check both halves: the same name on another database is free, and on the
 * same database it is still refused to a second connection, which is what
 * every lock here exists for.
 */
#[Group('database')]
final class AdvisoryLockOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const NAME = 'scoutmagic_schema_migration';

    /** The other database, created empty for the class; '' until then. */
    private static string $otherDatabase = '';

    /** @var list<\PDO> every connection a test opened, released in tearDown() */
    private array $opened = [];

    /** @var list<string> */
    private array $names = [];

    protected function tearDown(): void
    {
        foreach ($this->opened as $pdo) {
            foreach ($this->names as $name) {
                AdvisoryLock::release($pdo, $name);
            }
        }
        $this->opened = [];
        $this->names = [];
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$otherDatabase === '') {
            return;
        }
        $database = self::$otherDatabase;
        self::$otherDatabase = '';
        self::productionEngineConnection()->getPdo()->exec('DROP DATABASE IF EXISTS `' . $database . '`');
    }

    public function testTheSameNameOnAnotherDatabaseIsNotHeldUp(): void
    {
        $siteA = $this->connect();
        $siteB = $this->connect($this->otherDatabase());
        $this->assertNotSame($this->databaseOf($siteA), $this->databaseOf($siteB), 'the premise: two databases');

        $this->assertTrue($this->acquire($siteA, self::NAME));

        $this->assertTrue($this->acquire($siteB, self::NAME), 'a lock on one database must not block another');
    }

    public function testTheSameNameOnTheSameDatabaseIsStillRefusedToASecondConnection(): void
    {
        $first = $this->connect();
        $second = $this->connect();
        $this->assertSame($this->databaseOf($first), $this->databaseOf($second), 'the premise: one database');

        $this->assertTrue($this->acquire($first, self::NAME));

        $this->assertFalse($this->acquire($second, self::NAME));
        AdvisoryLock::release($first, self::NAME);
        $this->assertTrue($this->acquire($second, self::NAME), 'and granted once released');
    }

    /**
     * The name the server sees is hashed, so neither the caller's name nor
     * the database's — here the longest MySQL allows — can push it past
     * `GET_LOCK()`'s 64 characters. Refused to the second connection, which
     * proves a real lock was taken: an over-long name raises an error that
     * acquire() would read as « no GET_LOCK() here » and answer true to both.
     */
    public function testALongNameOnALongDatabaseNameIsARealLock(): void
    {
        $name = str_repeat('registration_auto_accept:', 12);
        $database = $this->otherDatabase();
        $this->assertSame(64, strlen($database), 'the premise: the longest database name MySQL allows');
        $first = $this->connect($database);
        $second = $this->connect($database);

        $this->assertTrue($this->acquire($first, $name));

        $this->assertFalse($this->acquire($second, $name));
    }

    /**
     * Released under the same scoped name it was taken under — a release
     * that missed would leave the lock held until the connection closed —
     * and with the cursor closed, so the connection takes its next query.
     */
    public function testReleaseFreesTheScopedNameAndLeavesTheConnectionUsable(): void
    {
        $first = $this->connect();
        $second = $this->connect();
        $this->assertTrue($this->acquire($first, self::NAME));

        AdvisoryLock::release($first, self::NAME);

        $this->assertSame('1', (string) $first->query('SELECT 1')->fetchColumn());
        $this->assertTrue($this->acquire($second, self::NAME));
    }

    private function acquire(\PDO $pdo, string $name): bool
    {
        $this->names[] = $name;

        return AdvisoryLock::acquire($pdo, $name);
    }

    /** A fresh connection — a fresh session, which is what a lock belongs to. */
    private function connect(?string $database = null): \PDO
    {
        $pdo = self::productionEngineConnection($database)->getPdo();
        $this->opened[] = $pdo;

        return $pdo;
    }

    private function databaseOf(\PDO $pdo): string
    {
        return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    }

    /**
     * An empty database of this class's own, 64 characters long — the
     * longest name MySQL accepts. Concatenated into the DDL for the reason
     * `Tests\UsesProductionEngine` gives: an identifier cannot be bound,
     * and every character here is generated (`[a-z0-9_]`).
     */
    private function otherDatabase(): string
    {
        if (self::$otherDatabase !== '') {
            return self::$otherDatabase;
        }

        $database = str_pad('sm_advisorylock_' . getmypid() . '_' . bin2hex(random_bytes(4)) . '_', 64, 'x');
        try {
            self::productionEngineConnection()->getPdo()->exec(
                'CREATE DATABASE `' . $database . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        } catch (\PDOException $e) {
            DatabaseTestHelper::skipOnlyWhenNoServerWasPromised(
                'The second MySQL database this class needs could not be created: ' . $e->getMessage()
            );
        }
        self::$otherDatabase = $database;

        return $database;
    }
}
