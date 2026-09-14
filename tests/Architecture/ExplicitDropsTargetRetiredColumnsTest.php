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
 *
 * **The declarations are gathered across every schema file, not just the
 * one beside the drop.** Migration applies the whole set as one: a module
 * whose `drops.sql` names a column of a table the CORE declares destroys
 * it just as thoroughly, and a per-file reading calls that « a table this
 * schema never owned » and waves it through. That is not a hypothetical
 * shape either — this chantier moved a table out of the gallery module
 * and into the core, which is exactly the move that leaves a stale drop
 * line pointing at somebody else's schema.
 */
class ExplicitDropsTargetRetiredColumnsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    public function testNoDropsFileRemovesSomethingTheSchemaSetStillDeclares(): void
    {
        $parser = new SqlParser();
        $checked = 0;

        $schemaFiles = SchemaFiles::all(self::ROOT);

        /** @var array<string, list<string>> $declared */
        $declared = [];
        /** @var array<string, string> $declaredBy */
        $declaredBy = [];
        foreach ($schemaFiles as $schemaFile) {
            foreach ($parser->parseFile($schemaFile) as $table) {
                $declared[$table->name] = array_map(
                    static fn ($column): string => $column->name,
                    $table->columns
                );
                $declaredBy[$table->name] = self::relative($schemaFile);
            }
        }

        foreach ($schemaFiles as $schemaFile) {
            $dropsFile = dirname($schemaFile) . '/drops.sql';
            $drops = $parser->parseDropsFile($dropsFile);
            if ($drops === []) {
                continue;
            }
            $checked++;

            foreach ($drops as $drop) {
                $table = $drop['table'];
                $where = self::relative($dropsFile);
                $owner = $declaredBy[$table] ?? '(nothing)';

                if (isset($drop['drop_table'])) {
                    $this->assertArrayNotHasKey(
                        $table,
                        $declared,
                        "{$where} drops the table `{$table}`, which {$owner} still declares."
                    );
                    continue;
                }

                if (!isset($drop['column']) || !isset($declared[$table])) {
                    // A foreign key, or a table nothing declares any more —
                    // neither is what this test is about.
                    continue;
                }

                $this->assertNotContains(
                    $drop['column'],
                    $declared[$table],
                    "{$where} drops `{$table}.{$drop['column']}`, which {$owner} still declares. "
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

    /** A path a failure message can be read against, rather than an absolute one. */
    private static function relative(string $path): string
    {
        $root = realpath(self::ROOT);
        $real = realpath($path);
        if ($root === false || $real === false || !str_starts_with($real, $root . '/')) {
            return $path;
        }

        return substr($real, strlen($root) + 1);
    }
}
