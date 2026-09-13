<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Database\SchemaFiles;
use Core\Database\SqlParser;
use PHPUnit\Framework\TestCase;

/**
 * A `drops.sql` may only name things the schema beside it has STOPPED
 * declaring.
 *
 * `MigrationRunner::applyExplicitDrops()` is the one mechanism in the
 * whole migration path that destroys data, and it runs AFTER the
 * comparator has added every declared column — so a line naming a column
 * the schema still declares does not read as a contradiction anywhere. It
 * either fails with a foreign-key error that is recorded as a warning and
 * nothing else (leaving the genuinely retired column in place for ever),
 * or, where it succeeds, silently wipes a column the application is
 * actively using.
 *
 * This is not hypothetical: `modules/gallery/drops.sql` shipped naming
 * `location_id` and `migration_target_id` — the two columns the same
 * change had just ADDED — because a rename applied across the repository
 * rewrote the drop file along with the code. The two `DROP FOREIGN KEY`
 * lines above them were right, which is exactly what made it hard to see.
 *
 * A whole-table drop is deliberately checked the same way: `DROP TABLE`
 * on a table still declared is the same mistake, one order of magnitude
 * worse.
 */
class ExplicitDropsTargetRetiredColumnsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testNoDropsFileRemovesSomethingItsOwnSchemaStillDeclares(): void
    {
        $parser = new SqlParser();
        $checked = 0;

        foreach (SchemaFiles::all(self::ROOT) as $schemaFile) {
            $dropsFile = dirname($schemaFile) . '/drops.sql';
            $drops = $parser->parseDropsFile($dropsFile);
            if ($drops === []) {
                continue;
            }
            $checked++;

            $declared = [];
            foreach ($parser->parseFile($schemaFile) as $table) {
                $declared[$table->name] = array_map(
                    static fn ($column): string => $column->name,
                    $table->columns
                );
            }

            foreach ($drops as $drop) {
                $table = $drop['table'];
                $where = basename(dirname($dropsFile)) . '/drops.sql';

                if (isset($drop['drop_table'])) {
                    $this->assertArrayNotHasKey(
                        $table,
                        $declared,
                        "{$where} drops the table `{$table}`, which its own schema still declares."
                    );
                    continue;
                }

                if (!isset($drop['column']) || !isset($declared[$table])) {
                    // A foreign key, or a table this schema never owned —
                    // neither is what this test is about.
                    continue;
                }

                $this->assertNotContains(
                    $drop['column'],
                    $declared[$table],
                    "{$where} drops `{$table}.{$drop['column']}`, which its own schema still declares. "
                        . 'An explicit drop may only name a column the schema has stopped declaring: '
                        . 'this one runs after the column has just been (re)created, so it either fails '
                        . 'silently or destroys live data.'
                );
            }
        }

        // A green run that checked nothing would be a test quietly
        // measuring an empty set — the failure mode AGENTS.md § Tests
        // warns about.
        $this->assertGreaterThan(0, $checked, 'No drops.sql was examined at all.');
    }
}
