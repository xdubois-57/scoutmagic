<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\SchemaIntrospector;
use PHPUnit\Framework\TestCase;

/**
 * @group database
 */
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
}
