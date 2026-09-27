<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Database;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The fixture every `…OnMysqlTest` stands on, checked for the four things
 * it promises: the real engine, the declared schema, a clean slate, and a
 * database of its own that does not outlive the class.
 */
#[Group('database')]
final class UsesProductionEngineTest extends TestCase
{
    use UsesProductionEngine;

    public function testItIsTheRealEngineInADatabaseOfItsOwn(): void
    {
        $pdo = $this->productionEngine();

        $this->assertSame('mysql', $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $this->assertNotSame(
            self::productionEngineCredentials()['dbName'],
            $pdo->query('SELECT DATABASE()')->fetchColumn(),
            'the shared TEST_DB_NAME would make a result depend on what other classes left in it'
        );
    }

    /**
     * Built by the migration runner from the schema files: a core table, a
     * module's table, and what an installed site really has — which is why
     * the migration's own output is what gets looked at, not the files.
     * (Issue #590 is what that difference looks like when it bites.)
     */
    public function testItHoldsTheDeclaredSchemaRatherThanOneWrittenByHand(): void
    {
        $pdo = $this->productionEngine();

        $tables = $pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertContains('members', $tables);
        $this->assertContains('mail_providers', $tables);
        $this->assertContains('rental_bookings', $tables);

        $type = $pdo->query("SHOW COLUMNS FROM settings LIKE 'setting_type'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($type);
        $this->assertSame('text', trim((string) $type['Default'], "'"), 'the declared default');
    }

    public function testEveryTestStartsFromEmptyTables(): void
    {
        $pdo = $this->productionEngine();
        $pdo->exec("INSERT INTO settings (setting_key, setting_value, label, description) VALUES ('probe', 'x', 'p', 'p')");
        $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn());

        $pdo = $this->productionEngine();

        $this->assertSame(
            0,
            (int) $pdo->query('SELECT COUNT(*) FROM settings')->fetchColumn(),
            'a row one test wrote was still there for the next — including the schema hash the migration stores'
        );
    }

    /**
     * The site's connection, not one of the test's own: an UPDATE that
     * changes nothing reports no row, because `MYSQL_ATTR_FOUND_ROWS` is not
     * set. A fixture that turned it on would hide the very defect
     * `BounceSendReceiptMysqlTest` was written for.
     */
    public function testItConnectsTheWayTheSiteDoes(): void
    {
        $pdo = $this->productionEngine();
        $pdo->exec("INSERT INTO settings (setting_key, setting_value, label, description) VALUES ('probe', 'x', 'p', 'p')");

        $update = $pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $update->execute(['x', 'probe']);

        $this->assertSame(0, $update->rowCount());
        $this->assertSame(\PDO::FETCH_ASSOC, $pdo->getAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE));
    }

    public function testTheDatabaseIsDroppedAfterTheClass(): void
    {
        $name = (string) $this->productionEngine()->query('SELECT DATABASE()')->fetchColumn();

        self::dropProductionEngineDatabase();

        $left = self::productionEngineConnection()->getPdo()->prepare(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?'
        );
        $left->execute([$name]);
        $this->assertSame(0, (int) $left->fetchColumn(), $name . ' outlived its class');
    }
}
