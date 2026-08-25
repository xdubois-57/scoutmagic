<?php

declare(strict_types=1);

namespace Tests\Core\Import;

use Core\Database\SqlParser;
use PHPUnit\Framework\TestCase;

/**
 * `schema/core.sql` really declares the columns this chantier's code
 * writes to.
 *
 * **Written because the opposite happened.** `diff_json` was added to
 * `Tests\DatabaseTestHelper`'s SQLite mirror and, through a lost edit,
 * never to `schema/core.sql`. Every PHPUnit test passed — they all run
 * against the mirror — and the gap only surfaced in the end-to-end suite,
 * as an `Unknown column 'diff_json'` on a real MySQL, on the one code
 * path a browser exercises.
 *
 * So this asserts against the schema file itself, parsed by the same
 * `SqlParser` the migration runner uses. It is the cheap half of a guard
 * the expensive half of which is `npm run e2e`.
 */
class ImportSchemaDeclarationTest extends TestCase
{
    /** @var array<string, string[]> table => columns the import pipeline writes */
    private const REQUIRED = [
        // The parent row of an import: the kept CSV and the frozen diff
        // both hang off it (ARCHITECTURE.md §8.1).
        'import_journal' => ['file_id', 'diff_json'],
        // The roster snapshot, moved out of modules/fees (§8.74), plus
        // the link back to the import that froze it.
        'fees_roster_snapshots' => ['import_journal_id'],
        // The main function's own id, which the diff reports alongside
        // the role it resolves to.
        'fees_roster_snapshot_members' => ['function_id'],
        // Merging two records of one person (§8.80).
        'members' => ['merged_into_member_id', 'merged_at'],
        'member_desk_id_aliases' => ['member_id', 'desk_id'],
        'member_duplicate_candidates' => ['kept_member_id', 'duplicate_member_id', 'same_address', 'status'],
    ];

    public function testEveryColumnTheImportPipelineWritesIsDeclared(): void
    {
        $tables = [];
        foreach ((new SqlParser())->parseFile(dirname(__DIR__, 3) . '/schema/core.sql') as $table) {
            $tables[$table->name] = array_map(static fn($column): string => $column->name, $table->columns);
        }

        foreach (self::REQUIRED as $tableName => $columns) {
            $this->assertArrayHasKey($tableName, $tables, "schema/core.sql must declare {$tableName}");

            foreach ($columns as $column) {
                $this->assertContains(
                    $column,
                    $tables[$tableName],
                    "schema/core.sql must declare {$tableName}.{$column} — the SQLite test mirror having it is not enough"
                );
            }
        }
    }

    /**
     * And the SQLite mirror carries them too, which is the other half of
     * the same mistake: a column in `core.sql` that the mirror lacks makes
     * every unit test fail for a reason that has nothing to do with the
     * change.
     */
    public function testTheSqliteMirrorCarriesTheSameColumns(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();

        foreach (self::REQUIRED as $tableName => $columns) {
            $stmt = $pdo->query("SELECT * FROM {$tableName} LIMIT 0");
            $this->assertNotFalse($stmt, "the SQLite mirror must create {$tableName}");

            $mirrored = [];
            for ($i = 0; $i < $stmt->columnCount(); $i++) {
                $meta = $stmt->getColumnMeta($i);
                if (is_array($meta)) {
                    $mirrored[] = (string) $meta['name'];
                }
            }

            foreach ($columns as $column) {
                $this->assertContains($column, $mirrored, "the SQLite mirror must carry {$tableName}.{$column}");
            }
        }
    }
}
