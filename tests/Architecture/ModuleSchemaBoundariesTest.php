<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Database\SchemaFiles;
use PHPUnit\Framework\TestCase;

/**
 * The whole declared schema — core plus every module — is migrated as one
 * ordered list (Core\Database\SchemaFiles), and the order is load-bearing
 * exactly once: core first, because module tables carry foreign keys into
 * core tables and MySQL will not accept a REFERENCES to a table that does
 * not exist yet.
 *
 * Among the modules themselves the order is alphabetical, which is only
 * defensible while no module's table references another module's table.
 * That is true today; it is not a property anyone would notice losing,
 * because a new cross-module foreign key would work fine as long as the
 * two modules happened to sort the right way round, and fail on a fresh
 * install the day they did not. So it is asserted rather than assumed.
 */
class ModuleSchemaBoundariesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../..';

    /**
     * @return array<string, string> table name => schema file that declares it
     */
    private function declaringFile(): array
    {
        $owner = [];
        foreach (SchemaFiles::all(self::ROOT) as $file) {
            $sql = (string) file_get_contents($file);
            preg_match_all('/CREATE\s+TABLE(?:\s+IF\s+NOT\s+EXISTS)?\s+`?(\w+)`?/i', $sql, $matches);
            foreach ($matches[1] as $table) {
                $owner[$table] = $file;
            }
        }

        return $owner;
    }

    /**
     * `REFERENCES x` occurrences, ignoring the ones inside comments — the
     * schema files are heavily commented and several comments contain the
     * English word "references".
     *
     * @return array<array{file: string, table: string, line: int}>
     */
    private function foreignKeyReferences(): array
    {
        $found = [];
        foreach (SchemaFiles::all(self::ROOT) as $file) {
            $lineNumber = 0;
            foreach (explode("\n", (string) file_get_contents($file)) as $line) {
                $lineNumber++;
                $code = preg_replace('/--.*$/', '', $line) ?? '';
                if (preg_match('/REFERENCES\s+`?(\w+)`?/i', $code, $m) === 1) {
                    $found[] = ['file' => $file, 'table' => $m[1], 'line' => $lineNumber];
                }
            }
        }

        return $found;
    }

    public function testTheSetIsCoreFirstThenEveryModuleSchema(): void
    {
        $files = SchemaFiles::all(self::ROOT);

        $this->assertNotEmpty($files);
        $this->assertSame(
            realpath(self::ROOT . '/schema/core.sql'),
            realpath($files[0]),
            'core.sql must come first: every module foreign key points into it'
        );

        $modules = array_slice($files, 1);
        $this->assertNotEmpty($modules, 'the installation ships modules with schemas');
        foreach ($modules as $file) {
            $this->assertMatchesRegularExpression('#/modules/[^/]+/schema\.sql$#', $file);
        }

        $sorted = $modules;
        sort($sorted);
        $this->assertSame($sorted, $modules, 'module schemas must be in a stable order');
    }

    public function testNoModuleTableReferencesAnotherModulesTable(): void
    {
        $owner = $this->declaringFile();
        $offenders = [];

        foreach ($this->foreignKeyReferences() as $reference) {
            $target = $owner[$reference['table']] ?? null;
            if ($target === null) {
                $this->fail(sprintf(
                    '%s:%d references `%s`, which no schema file declares',
                    $reference['file'],
                    $reference['line'],
                    $reference['table']
                ));
            }

            $fromModule = str_contains($reference['file'], '/modules/');
            $toModule = str_contains($target, '/modules/');

            if ($fromModule && $toModule && $target !== $reference['file']) {
                $offenders[] = sprintf(
                    '%s:%d -> `%s` (declared by %s)',
                    $reference['file'],
                    $reference['line'],
                    $reference['table'],
                    $target
                );
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "A module's schema must not reference another module's table. Modules are migrated in "
            . "alphabetical order within one pass, so such a foreign key works or fails depending on "
            . "how the two module names happen to sort — and fails on a fresh install, where neither "
            . "table exists yet. Put the shared table in schema/core.sql, or drop the constraint.\n"
            . implode("\n", $offenders)
        );
    }
}
