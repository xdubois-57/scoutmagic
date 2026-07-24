<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\SqlParser;
use PHPUnit\Framework\TestCase;

class SqlParserTest extends TestCase
{
    private SqlParser $parser;

    protected function setUp(): void
    {
        $this->parser = new SqlParser();
    }

    public function testParsingSimpleCreateTableWithColumns(): void
    {
        $sql = "CREATE TABLE users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(255) NOT NULL,
            bio TEXT,
            data BLOB
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $tables = $this->parser->parse($sql);

        $this->assertCount(1, $tables);
        $this->assertSame('users', $tables[0]->name);
        $this->assertCount(4, $tables[0]->columns);

        $idCol = $tables[0]->getColumn('id');
        $this->assertNotNull($idCol);
        $this->assertTrue($idCol->autoIncrement);
        $this->assertFalse($idCol->nullable);

        $nameCol = $tables[0]->getColumn('name');
        $this->assertNotNull($nameCol);
        $this->assertFalse($nameCol->nullable);

        $bioCol = $tables[0]->getColumn('bio');
        $this->assertNotNull($bioCol);
        $this->assertTrue($bioCol->nullable);

        $dataCol = $tables[0]->getColumn('data');
        $this->assertNotNull($dataCol);
        $this->assertStringContainsString('blob', $dataCol->getNormalizedType());
    }

    public function testParsingPrimaryKeyUniqueNotNullDefaultConstraints(): void
    {
        $sql = "CREATE TABLE items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            code VARCHAR(10) NOT NULL,
            active BOOLEAN NOT NULL DEFAULT TRUE,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE INDEX idx_code (code)
        ) ENGINE=InnoDB;";

        $tables = $this->parser->parse($sql);
        $table = $tables[0];

        // Check PRIMARY KEY index
        $primaryIndex = null;
        foreach ($table->indexes as $idx) {
            if ($idx->primary) {
                $primaryIndex = $idx;
                break;
            }
        }
        $this->assertNotNull($primaryIndex);
        $this->assertSame(['id'], $primaryIndex->columns);

        // Check UNIQUE index
        $uniqueIndex = null;
        foreach ($table->indexes as $idx) {
            if ($idx->name === 'idx_code') {
                $uniqueIndex = $idx;
                break;
            }
        }
        $this->assertNotNull($uniqueIndex);
        $this->assertTrue($uniqueIndex->unique);
        $this->assertSame(['code'], $uniqueIndex->columns);

        // Check defaults
        $activeCol = $table->getColumn('active');
        $this->assertNotNull($activeCol);
        $this->assertSame('TRUE', $activeCol->default);

        $createdCol = $table->getColumn('created_at');
        $this->assertNotNull($createdCol);
        $this->assertSame('CURRENT_TIMESTAMP', $createdCol->default);
    }

    public function testParsingForeignKeyReferences(): void
    {
        $sql = "CREATE TABLE orders (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
        ) ENGINE=InnoDB;";

        $tables = $this->parser->parse($sql);
        $table = $tables[0];

        $this->assertCount(1, $table->foreignKeys);
        $fk = $table->foreignKeys[0];
        $this->assertSame('fk_orders_user', $fk->name);
        $this->assertSame('user_id', $fk->column);
        $this->assertSame('users', $fk->referencedTable);
        $this->assertSame('id', $fk->referencedColumn);
        $this->assertSame('CASCADE', $fk->onDelete);
    }

    public function testParsingIndexDefinitions(): void
    {
        $sql = "CREATE TABLE logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            created_at DATETIME NOT NULL,
            INDEX idx_event_type (event_type),
            INDEX idx_created (created_at)
        ) ENGINE=InnoDB;";

        $tables = $this->parser->parse($sql);
        $table = $tables[0];

        $eventIdx = $table->getIndex('idx_event_type');
        $this->assertNotNull($eventIdx);
        $this->assertFalse($eventIdx->unique);
        $this->assertSame(['event_type'], $eventIdx->columns);

        $createdIdx = $table->getIndex('idx_created');
        $this->assertNotNull($createdIdx);
        $this->assertSame(['created_at'], $createdIdx->columns);
    }

    public function testParsingMultipleCreateTableStatements(): void
    {
        $sql = "
        CREATE TABLE table_a (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL
        ) ENGINE=InnoDB;

        CREATE TABLE table_b (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            value TEXT
        ) ENGINE=InnoDB;

        CREATE TABLE table_c (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY
        ) ENGINE=InnoDB;
        ";

        $tables = $this->parser->parse($sql);

        $this->assertCount(3, $tables);
        $this->assertSame('table_a', $tables[0]->name);
        $this->assertSame('table_b', $tables[1]->name);
        $this->assertSame('table_c', $tables[2]->name);
    }

    public function testParsingCommentsAreIgnored(): void
    {
        $sql = "
        -- This is a comment
        /* This is a multi-line
           comment */
        CREATE TABLE test_table (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            -- inline comment about name
            name VARCHAR(50) NOT NULL
        ) ENGINE=InnoDB;
        ";

        $tables = $this->parser->parse($sql);

        $this->assertCount(1, $tables);
        $this->assertSame('test_table', $tables[0]->name);
        $this->assertCount(2, $tables[0]->columns);
    }

    public function testParsingActualCoreSqlFileSucceeds(): void
    {
        $schemaPath = dirname(__DIR__, 3) . '/schema/core.sql';
        $tables = $this->parser->parseFile($schemaPath);

        $this->assertCount(25, $tables);

        $tableNames = array_map(fn($t) => $t->name, $tables);
        $this->assertContains('scout_years', $tableNames);
        $this->assertContains('members', $tableNames);
        $this->assertContains('user_accounts', $tableNames);
        $this->assertContains('magic_links', $tableNames);
        $this->assertContains('editable_contents', $tableNames);
        $this->assertContains('files', $tableNames);
        $this->assertContains('age_branches', $tableNames);
        $this->assertContains('sections', $tableNames);
        $this->assertContains('member_photos', $tableNames);
        $this->assertContains('badges', $tableNames);
        $this->assertContains('member_badges', $tableNames);

        // Verify scout_years structure
        $scoutYears = null;
        foreach ($tables as $table) {
            if ($table->name === 'scout_years') {
                $scoutYears = $table;
                break;
            }
        }
        $this->assertNotNull($scoutYears);
        $this->assertNotNull($scoutYears->getColumn('id'));
        $this->assertNotNull($scoutYears->getColumn('label'));
        $this->assertNotNull($scoutYears->getColumn('start_date'));
        $this->assertNotNull($scoutYears->getColumn('end_date'));
        $this->assertNotNull($scoutYears->getColumn('is_current'));
        $this->assertNotNull($scoutYears->getColumn('created_at'));

        // Verify members structure
        $members = null;
        foreach ($tables as $table) {
            if ($table->name === 'members') {
                $members = $table;
                break;
            }
        }
        $this->assertNotNull($members);
        $this->assertNotNull($members->getColumn('id'));
        $this->assertNotNull($members->getColumn('desk_id'));
    }

    public function testParsingFileThrowsForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->parser->parseFile('/nonexistent/path/schema.sql');
    }

    public function testParseDropsExtractsTableAndColumn(): void
    {
        $sql = 'ALTER TABLE badges DROP COLUMN icon;';

        $drops = $this->parser->parseDrops($sql);

        $this->assertSame([['table' => 'badges', 'column' => 'icon']], $drops);
    }

    public function testParseDropsHandlesMultipleStatementsAndBackticks(): void
    {
        $sql = "ALTER TABLE `badges` DROP COLUMN `icon`;\nALTER TABLE members DROP COLUMN legacy_flag;";

        $drops = $this->parser->parseDrops($sql);

        $this->assertSame([
            ['table' => 'badges', 'column' => 'icon'],
            ['table' => 'members', 'column' => 'legacy_flag'],
        ], $drops);
    }

    public function testParseDropsIgnoresCommentedOutStatements(): void
    {
        $sql = "-- ALTER TABLE badges DROP COLUMN icon;\nSELECT 1;";

        $this->assertSame([], $this->parser->parseDrops($sql));
    }

    public function testParseDropsIgnoresNonDropStatements(): void
    {
        $sql = 'CREATE TABLE badges (id INT, name VARCHAR(100));';

        $this->assertSame([], $this->parser->parseDrops($sql));
    }

    public function testParseDropsExtractsTableAndConstraintForForeignKeyDrop(): void
    {
        $sql = 'ALTER TABLE finance_transactions DROP FOREIGN KEY fk_ft_fiscal_year;';

        $drops = $this->parser->parseDrops($sql);

        $this->assertSame([['table' => 'finance_transactions', 'constraint' => 'fk_ft_fiscal_year']], $drops);
    }

    public function testParseDropsHandlesMixedColumnAndForeignKeyDropsInOrder(): void
    {
        $sql = "ALTER TABLE badges DROP COLUMN icon;\nALTER TABLE finance_transactions DROP FOREIGN KEY fk_ft_fiscal_year;\nALTER TABLE members DROP COLUMN legacy_flag;";

        $drops = $this->parser->parseDrops($sql);

        $this->assertSame([
            ['table' => 'badges', 'column' => 'icon'],
            ['table' => 'finance_transactions', 'constraint' => 'fk_ft_fiscal_year'],
            ['table' => 'members', 'column' => 'legacy_flag'],
        ], $drops);
    }

    public function testParseDropsFileReturnsEmptyArrayForMissingFile(): void
    {
        $this->assertSame([], $this->parser->parseDropsFile('/nonexistent/drops.sql'));
    }

    public function testParseDropsFileReadsRealDropsFile(): void
    {
        $drops = $this->parser->parseDropsFile(dirname(__DIR__, 3) . '/schema/drops.sql');

        $this->assertContains(['table' => 'badges', 'column' => 'icon'], $drops);
    }
}
