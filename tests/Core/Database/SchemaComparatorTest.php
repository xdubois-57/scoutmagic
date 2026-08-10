<?php

declare(strict_types=1);

namespace Tests\Core\Database;

use Core\Database\ColumnDefinition;
use Core\Database\ForeignKeyDefinition;
use Core\Database\IndexDefinition;
use Core\Database\SchemaComparator;
use Core\Database\TableDefinition;
use PHPUnit\Framework\TestCase;

class SchemaComparatorTest extends TestCase
{
    private SchemaComparator $comparator;

    protected function setUp(): void
    {
        $this->comparator = new SchemaComparator();
    }

    public function testNewTableGeneratesCreateTable(): void
    {
        $declared = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('name', 'varchar(255)', false, null, false, null),
                ],
                indexes: [
                    new IndexDefinition('PRIMARY', ['id'], true, true),
                ],
                foreignKeys: []
            ),
        ];

        $actual = [];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('CREATE TABLE `users`', $statements[0]);
        $this->assertStringContainsString('`id`', $statements[0]);
        $this->assertStringContainsString('`name`', $statements[0]);
    }

    public function testNewColumnGeneratesAlterTableAddColumn(): void
    {
        $declared = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('name', 'varchar(255)', false, null, false, null),
                    new ColumnDefinition('email', 'varchar(255)', false, null, false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('name', 'varchar(255)', false, null, false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('ALTER TABLE `users` ADD COLUMN', $statements[0]);
        $this->assertStringContainsString('`email`', $statements[0]);
    }

    public function testModifiedColumnTypeGeneratesAlterTableModifyColumn(): void
    {
        $declared = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('name', 'text', true, null, false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('name', 'varchar(255)', false, null, false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('ALTER TABLE `users` MODIFY COLUMN', $statements[0]);
        $this->assertStringContainsString('`name`', $statements[0]);
        $this->assertStringContainsString('text', $statements[0]);
    }

    public function testColumnInActualButNotInDeclaredGeneratesNoStatement(): void
    {
        $declared = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('legacy_field', 'varchar(100)', true, null, false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertEmpty($statements);

        $warnings = $this->comparator->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('legacy_field', $warnings[0]);
    }

    public function testTableInActualButNotInDeclaredGeneratesNoStatement(): void
    {
        $declared = [];

        $actual = [
            new TableDefinition(
                name: 'old_table',
                columns: [
                    new ColumnDefinition('id', 'int', false, null, true, 'auto_increment'),
                ],
                indexes: [],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertEmpty($statements);

        $warnings = $this->comparator->getWarnings();
        $this->assertNotEmpty($warnings);
        $this->assertStringContainsString('old_table', $warnings[0]);
    }

    public function testDeclaredBooleanMatchesIntrospectedTinyint1WithNoStatement(): void
    {
        // schema.sql declares "boolean" / TRUE, but MySQL always resolves
        // and reports it back as tinyint(1) / 1 via INFORMATION_SCHEMA —
        // without normalization this compared as different on every single
        // migration run forever (the bug this test locks in).
        $declared = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('active', 'boolean', false, 'TRUE', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('active', 'tinyint(1)', false, '1', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertEmpty($statements);
    }

    public function testDeclaredEnumMatchesIntrospectedCommaSpacingWithNoStatement(): void
    {
        // MySQL introspection always strips the space after each comma in
        // an ENUM/SET definition, even when the original DDL had one.
        $declared = [
            new TableDefinition(
                name: 'boards',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('status', "enum('open', 'closed', 'archived')", false, "'open'", false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'boards',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('status', "enum('open','closed','archived')", false, "'open'", false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertEmpty($statements);
    }

    public function testDeclaredBareIntMatchesMariaDbIntrospectedDisplayWidthWithNoStatement(): void
    {
        // schema.sql declares bare "INT"/"INT UNSIGNED" (no display width),
        // which MySQL 8.0.19+ introspects back the same way — but MariaDB
        // always reports a width ("int(11)", "int(10) unsigned") even
        // though none was declared. Without normalizing this away, every
        // bare integer column (the vast majority of every schema.sql in
        // this codebase) compares as different on every single migration
        // run, forever, on any MariaDB-backed host.
        $declared = [
            new TableDefinition(
                name: 'members',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('scout_year_offset', 'tinyint', false, '0', false, null),
                    new ColumnDefinition('sort_order', 'int', false, '0', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'members',
                columns: [
                    new ColumnDefinition('id', 'int(10) unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('scout_year_offset', 'tinyint(4)', false, '0', false, null),
                    new ColumnDefinition('sort_order', 'int(11)', false, '0', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertEmpty($statements);
    }

    public function testMariaDbTinyint1DisplayWidthStillDistinguishesBooleanFromPlainTinyint(): void
    {
        // The display-width stripping above must not swallow tinyint(1) —
        // that's specifically how a BOOLEAN column round-trips on both
        // engines, and a genuinely different tinyint(1)-vs-tinyint(4)
        // situation shouldn't be silently normalized away.
        $declared = [
            new TableDefinition(
                name: 'boards',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('flag', 'tinyint', false, '0', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'boards',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('flag', 'tinyint(1)', false, '0', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('MODIFY COLUMN `flag`', $statements[0]);
    }

    public function testDeclaredCurrentTimestampMatchesMariaDbIntrospectedFunctionSyntaxWithNoStatement(): void
    {
        // schema.sql declares bare "CURRENT_TIMESTAMP", which MySQL 8
        // introspects back the same way — but MariaDB reports it as
        // "current_timestamp()" (function-call syntax, with parentheses).
        // Without normalizing this away, every DATETIME ... DEFAULT
        // CURRENT_TIMESTAMP column (created_at columns exist on nearly
        // every table in this codebase) compares as different on every
        // single migration run, forever, on any MariaDB-backed host.
        $declared = [
            new TableDefinition(
                name: 'members',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('created_at', 'datetime', false, 'CURRENT_TIMESTAMP', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'members',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('created_at', 'datetime', false, 'current_timestamp()', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertEmpty($statements);
    }

    public function testGenuinelyDifferentBooleanDefaultStillGeneratesAStatement(): void
    {
        // The boolean-default normalization must not swallow real
        // differences — TRUE vs FALSE is still a real drift to fix.
        $declared = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('active', 'boolean', false, 'TRUE', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('active', 'tinyint(1)', false, '0', false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('ALTER TABLE `users` MODIFY COLUMN', $statements[0]);
    }

    public function testIdenticalSchemasGenerateNoStatements(): void
    {
        $table = new TableDefinition(
            name: 'users',
            columns: [
                new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                new ColumnDefinition('name', 'varchar(255)', false, null, false, null),
            ],
            indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
            foreignKeys: []
        );

        $statements = $this->comparator->compare([$table], [$table]);

        $this->assertEmpty($statements);
        $this->assertEmpty($this->comparator->getWarnings());
    }

    public function testNewIndexGeneratesAddIndex(): void
    {
        $declared = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('email', 'varchar(255)', false, null, false, null),
                ],
                indexes: [
                    new IndexDefinition('PRIMARY', ['id'], true, true),
                    new IndexDefinition('idx_email', ['email'], false, false),
                ],
                foreignKeys: []
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'users',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('email', 'varchar(255)', false, null, false, null),
                ],
                indexes: [
                    new IndexDefinition('PRIMARY', ['id'], true, true),
                ],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('ADD INDEX `idx_email`', $statements[0]);
    }

    public function testCompareOneDeclaredTableGeneratesCreateTableWhenActualIsNull(): void
    {
        $declared = new TableDefinition(
            name: 'users',
            columns: [
                new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
            ],
            indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
            foreignKeys: []
        );

        $statements = $this->comparator->compareOneDeclaredTable($declared, null);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('CREATE TABLE `users`', $statements[0]);
    }

    public function testCompareOneDeclaredTableGeneratesAlterWhenActualDiffers(): void
    {
        $declared = new TableDefinition(
            name: 'users',
            columns: [
                new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                new ColumnDefinition('email', 'varchar(255)', false, null, false, null),
            ],
            indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
            foreignKeys: []
        );
        $actual = new TableDefinition(
            name: 'users',
            columns: [
                new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
            ],
            indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
            foreignKeys: []
        );

        $statements = $this->comparator->compareOneDeclaredTable($declared, $actual);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('ALTER TABLE `users` ADD COLUMN', $statements[0]);
        $this->assertStringContainsString('`email`', $statements[0]);
    }

    public function testCompareOneDeclaredTableGeneratesNoStatementWhenIdentical(): void
    {
        $table = new TableDefinition(
            name: 'users',
            columns: [
                new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
            ],
            indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
            foreignKeys: []
        );

        $statements = $this->comparator->compareOneDeclaredTable($table, $table);

        $this->assertEmpty($statements);
    }

    public function testNewForeignKeyGeneratesAddConstraint(): void
    {
        $declared = [
            new TableDefinition(
                name: 'orders',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('user_id', 'int unsigned', false, null, false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: [
                    new ForeignKeyDefinition('fk_orders_user', 'user_id', 'users', 'id', 'CASCADE', null),
                ]
            ),
        ];

        $actual = [
            new TableDefinition(
                name: 'orders',
                columns: [
                    new ColumnDefinition('id', 'int unsigned', false, null, true, 'auto_increment'),
                    new ColumnDefinition('user_id', 'int unsigned', false, null, false, null),
                ],
                indexes: [new IndexDefinition('PRIMARY', ['id'], true, true)],
                foreignKeys: []
            ),
        ];

        $statements = $this->comparator->compare($declared, $actual);

        $this->assertCount(1, $statements);
        $this->assertStringContainsString('ADD CONSTRAINT `fk_orders_user`', $statements[0]);
        $this->assertStringContainsString('REFERENCES `users`', $statements[0]);
        $this->assertStringContainsString('ON DELETE CASCADE', $statements[0]);
    }
}
