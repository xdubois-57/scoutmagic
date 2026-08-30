<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SchemaIntrospectorTest extends TestCase
{
    private ?\PDO $pdo = null;
    private ?SchemaIntrospector $introspector = null;

    protected function setUp(): void
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: 3306);
        $dbName = getenv('TEST_DB_NAME') ?: 'test_db';
        $user = getenv('TEST_DB_USER') ?: 'root';
        $password = getenv('TEST_DB_PASSWORD') ?: '';

        try {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $dbName);
            $this->pdo = new \PDO($dsn, $user, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $this->introspector = new SchemaIntrospector($this->pdo);
        } catch (\PDOException $e) {
            $this->markTestSkipped('Database connection not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('DROP TABLE IF EXISTS test_introspect_child');
            $this->pdo->exec('DROP TABLE IF EXISTS test_introspect_table');
        }
    }

    public function testGetTablesReturnsArrayOfTableNames(): void
    {
        $this->pdo->exec('CREATE TABLE IF NOT EXISTS test_introspect_table (id INT PRIMARY KEY)');

        $tables = $this->introspector->getTables();

        $this->assertIsArray($tables);
        $this->assertContains('test_introspect_table', $tables);
    }

    public function testGetColumnsReturnsColumnDefinitions(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS test_introspect_table');
        $this->pdo->exec('CREATE TABLE test_introspect_table (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            description TEXT,
            active BOOLEAN NOT NULL DEFAULT TRUE
        )');

        $columns = $this->introspector->getColumns('test_introspect_table');

        $this->assertCount(4, $columns);

        $idCol = $columns[0];
        $this->assertSame('id', $idCol->name);
        $this->assertTrue($idCol->autoIncrement);

        $nameCol = $columns[1];
        $this->assertSame('name', $nameCol->name);
        $this->assertFalse($nameCol->nullable);

        $descCol = $columns[2];
        $this->assertSame('description', $descCol->name);
        $this->assertTrue($descCol->nullable);
    }

    public function testGetIndexesReturnsIndexDefinitions(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS test_introspect_table');
        $this->pdo->exec('CREATE TABLE test_introspect_table (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(255) NOT NULL,
            name VARCHAR(100),
            UNIQUE INDEX idx_email (email),
            INDEX idx_name (name)
        )');

        $indexes = $this->introspector->getIndexes('test_introspect_table');

        $this->assertNotEmpty($indexes);

        $indexNames = array_map(fn($i) => $i->name, $indexes);
        $this->assertContains('PRIMARY', $indexNames);
        $this->assertContains('idx_email', $indexNames);
        $this->assertContains('idx_name', $indexNames);
    }

    public function testGetForeignKeysReturnsForeignKeyDefinitions(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS test_introspect_child');
        $this->pdo->exec('DROP TABLE IF EXISTS test_introspect_table');
        $this->pdo->exec('CREATE TABLE test_introspect_table (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
        )');
        $this->pdo->exec('CREATE TABLE test_introspect_child (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            parent_id INT UNSIGNED NOT NULL,
            CONSTRAINT fk_child_parent FOREIGN KEY (parent_id) REFERENCES test_introspect_table (id) ON DELETE CASCADE
        )');

        $foreignKeys = $this->introspector->getForeignKeys('test_introspect_child');

        $this->assertCount(1, $foreignKeys);
        $this->assertSame('fk_child_parent', $foreignKeys[0]->name);
        $this->assertSame('parent_id', $foreignKeys[0]->column);
        $this->assertSame('test_introspect_table', $foreignKeys[0]->referencedTable);
        $this->assertSame('id', $foreignKeys[0]->referencedColumn);
        $this->assertSame('CASCADE', $foreignKeys[0]->onDelete);
    }

    /**
     * The bulk read must describe the schema exactly as the per-table
     * methods do — it replaces them on the hot path, so any divergence
     * would show up as spurious ALTER statements on every migration.
     */
    public function testBulkDefinitionsMatchThePerTableOnes(): void
    {
        $this->pdo->exec(
            'CREATE TABLE test_introspect_table (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(50) NOT NULL,
                slug VARCHAR(50) NULL,
                UNIQUE KEY uniq_slug (slug),
                KEY idx_name (name)
            ) ENGINE=InnoDB'
        );
        $this->pdo->exec(
            'CREATE TABLE test_introspect_child (
                id INT PRIMARY KEY,
                parent_id INT NOT NULL,
                CONSTRAINT fk_introspect_parent FOREIGN KEY (parent_id)
                    REFERENCES test_introspect_table(id) ON DELETE CASCADE
            ) ENGINE=InnoDB'
        );

        $bulk = $this->introspector->getTableDefinitions(
            ['test_introspect_table', 'test_introspect_child']
        );

        foreach (['test_introspect_table', 'test_introspect_child'] as $table) {
            $one = $this->introspector->getTableDefinition($table);
            $this->assertEquals($one->columns, $bulk[$table]->columns, "columns differ for {$table}");
            $this->assertEquals($one->indexes, $bulk[$table]->indexes, "indexes differ for {$table}");
            $this->assertEquals($one->foreignKeys, $bulk[$table]->foreignKeys, "foreign keys differ for {$table}");
        }
    }

    /**
     * A name that is not a table is simply absent — the caller learns the
     * same thing getTables() would have told it, without an exception and
     * without an empty TableDefinition that would read as "a table with no
     * columns" and provoke a CREATE.
     */
    public function testATableThatDoesNotExistIsAbsentRatherThanEmpty(): void
    {
        $this->pdo->exec('CREATE TABLE test_introspect_table (id INT PRIMARY KEY) ENGINE=InnoDB');

        $bulk = $this->introspector->getTableDefinitions(
            ['test_introspect_table', 'a_table_that_was_never_created']
        );

        $this->assertArrayHasKey('test_introspect_table', $bulk);
        $this->assertArrayNotHasKey('a_table_that_was_never_created', $bulk);
    }

    public function testNoTablesAskedForCostsNoQuery(): void
    {
        $this->assertSame([], $this->introspector->getTableDefinitions([]));
    }

    /**
     * The measurement the whole iteration exists for: reading N tables
     * costs three INFORMATION_SCHEMA queries, not three per table.
     * Counted through the server's own Com_select rather than by
     * instrumenting the code, so it measures what actually reached MySQL.
     */
    public function testReadingManyTablesCostsThreeQueriesNotThreePerTable(): void
    {
        for ($i = 0; $i < 8; $i++) {
            $this->pdo->exec("CREATE TABLE test_bulk_count_{$i} (id INT PRIMARY KEY, name VARCHAR(20)) ENGINE=InnoDB");
        }
        $names = array_map(static fn(int $i): string => "test_bulk_count_{$i}", range(0, 7));

        try {
            $before = $this->comSelect();
            $this->introspector->getTableDefinitions($names);
            // No adjustment for the probe: SHOW SESSION STATUS increments
            // Com_show_status, not Com_select.
            $bulk = $this->comSelect() - $before;

            $before = $this->comSelect();
            foreach ($names as $name) {
                $this->introspector->getTableDefinition($name);
            }
            $perTable = $this->comSelect() - $before;

            $this->assertSame(3, $bulk, 'the bulk read must be exactly three queries');
            $this->assertSame(24, $perTable, 'eight tables, three queries each');
        } finally {
            for ($i = 0; $i < 8; $i++) {
                $this->pdo->exec("DROP TABLE IF EXISTS test_bulk_count_{$i}");
            }
        }
    }

    private function comSelect(): int
    {
        $row = $this->pdo->query("SHOW SESSION STATUS LIKE 'Com_select'")->fetch(\PDO::FETCH_ASSOC);

        return (int) $row['Value'];
    }

    // --- MariaDB reports an expression, not a value ---

    /**
     * The 534-statement bug, and the reason it went unnoticed for so long:
     * nothing was failing. Every migration pass regenerated the same
     * `MODIFY COLUMN` statements, they all succeeded, introspection
     * reported the same "difference" back, and the schema never converged
     * — a permanent cost paid on every schema change, with no error
     * anywhere to point at it.
     *
     * On MariaDB 10.2+ COLUMN_DEFAULT holds a SQL expression rather than a
     * value: a nullable column with no default reports the bare text
     * `NULL`, which read literally means "this column defaults to the
     * four-character string NULL".
     */
    public function testANullableColumnWithNoDefaultReportsNoDefault(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS default_probe');
        $this->pdo->exec('CREATE TABLE default_probe (plain VARCHAR(10) NULL, required INT NOT NULL)');

        $columns = $this->indexByName((new SchemaIntrospector($this->pdo))->getColumns('default_probe'));

        $this->assertNull($columns['plain']->default);
        $this->assertNull($columns['required']->default);
    }

    /**
     * The case that makes the rule above decidable rather than a guess: a
     * column that genuinely defaults to the string "NULL" is reported
     * WITH quotes, so the unquoted form can only ever mean "no default".
     * Collapse the two and a real default silently disappears from the
     * schema's description of itself.
     */
    public function testAColumnWhoseDefaultIsTheStringNullKeepsIt(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS default_probe');
        $this->pdo->exec("CREATE TABLE default_probe (odd VARCHAR(10) NULL DEFAULT 'NULL')");

        $columns = $this->indexByName((new SchemaIntrospector($this->pdo))->getColumns('default_probe'));

        $this->assertSame('NULL', $columns['odd']->default);
    }

    /**
     * String defaults come back as SQL literals — quoted, with quotes and
     * backslashes doubled — while a schema file declares `DEFAULT 'public'`
     * and the parser hands back `public`. Sixty columns differed on
     * nothing but those quotes.
     */
    public function testStringDefaultsAreUnquotedAndUnescaped(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS default_probe');
        $this->pdo->exec(
            "CREATE TABLE default_probe (
                plain VARCHAR(30) DEFAULT 'public',
                empty_string VARCHAR(30) DEFAULT '',
                apostrophe VARCHAR(30) DEFAULT 'it''s',
                backslash VARCHAR(30) DEFAULT 'back\\\\slash'
            )"
        );

        $columns = $this->indexByName((new SchemaIntrospector($this->pdo))->getColumns('default_probe'));

        $this->assertSame('public', $columns['plain']->default);
        $this->assertSame('', $columns['empty_string']->default);
        $this->assertSame("it's", $columns['apostrophe']->default);
        $this->assertSame('back\\slash', $columns['backslash']->default);
    }

    /**
     * Expression defaults are NOT unquoted — they arrive unquoted and mean
     * what they say. `current_timestamp()` versus `CURRENT_TIMESTAMP` is a
     * spelling difference between two real defaults, reconciled where
     * things are compared rather than here.
     */
    public function testExpressionAndNumericDefaultsArePassedThroughUntouched(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS default_probe');
        $this->pdo->exec(
            'CREATE TABLE default_probe (
                stamped DATETIME NULL DEFAULT CURRENT_TIMESTAMP,
                counted INT NULL DEFAULT 0
            )'
        );

        $columns = $this->indexByName((new SchemaIntrospector($this->pdo))->getColumns('default_probe'));

        $this->assertStringContainsStringIgnoringCase('current_timestamp', (string) $columns['stamped']->default);
        $this->assertSame('0', $columns['counted']->default);
    }

    /**
     * The bulk read is what MigrationRunner actually calls, so it must
     * decode identically — a fix that only reached the per-table path
     * would leave the 534 statements exactly where they were.
     */
    public function testTheBulkReadDecodesDefaultsTheSameWayAsThePerTableRead(): void
    {
        $this->pdo->exec('DROP TABLE IF EXISTS default_probe');
        $this->pdo->exec("CREATE TABLE default_probe (plain VARCHAR(10) NULL, named VARCHAR(10) DEFAULT 'public')");

        $introspector = new SchemaIntrospector($this->pdo);
        $perTable = $this->indexByName($introspector->getColumns('default_probe'));
        $bulk = $this->indexByName($introspector->getTableDefinitions(['default_probe'])['default_probe']->columns);

        $this->assertNull($bulk['plain']->default);
        $this->assertSame($perTable['plain']->default, $bulk['plain']->default);
        $this->assertSame($perTable['named']->default, $bulk['named']->default);
    }

    /**
     * @param array<int, \Core\Database\ColumnDefinition> $columns
     * @return array<string, \Core\Database\ColumnDefinition>
     */
    private function indexByName(array $columns): array
    {
        $byName = [];
        foreach ($columns as $column) {
            $byName[$column->name] = $column;
        }

        return $byName;
    }
}
