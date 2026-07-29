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
