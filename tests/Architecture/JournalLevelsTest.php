<?php

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * Every level this codebase writes to `event_log.level` must be one the
 * column accepts.
 *
 * This test exists because the suite could not catch the failure it
 * guards: `Tests\DatabaseTestHelper` builds `event_log.level` as a plain
 * SQLite `TEXT`, which stores anything, while a real installation's column
 * is an ENUM and MySQL under `STRICT_TRANS_TABLES` refuses a value outside
 * it. Fourteen call sites were passing `'warning'` to a three-member ENUM
 * — every one of them a `PDOException` in production and a green test in
 * CI, including one on a `role_min: public` endpoint (ARCHITECTURE.md
 * §8.6).
 *
 * Reading the sources rather than the runtime is the point: what fails is
 * a call site that is never exercised, on a host nobody is looking at.
 */
final class JournalLevelsTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The members of `event_log.level`, read from the schema itself so
     * this test can never drift from the column it is about.
     *
     * @return list<string>
     */
    private static function declaredLevels(): array
    {
        $schema = (string) file_get_contents(self::root() . '/schema/core.sql');

        self::assertSame(
            1,
            preg_match("/level ENUM\(([^)]+)\)/", $schema, $matches),
            'event_log.level must stay an ENUM declared in schema/core.sql'
        );

        preg_match_all("/'([a-z_]+)'/", $matches[1], $levels);

        return $levels[1];
    }

    /**
     * Every `->log(category, type, LEVEL, …)` whose level is a string
     * literal, with the file and line it sits on.
     *
     * A level built at runtime (`$ok ? 'info' : 'warning'`) is matched too
     * — both branches are literals — while a level held in a variable is
     * not, and cannot be: this is a source scan, and pretending otherwise
     * would give a false sense of coverage rather than an honest partial
     * one.
     *
     * @return array<string, list<string>> level => call sites
     */
    private static function loggedLevels(): array
    {
        $found = [];

        foreach (['core', 'modules'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::root() . '/' . $directory)
            );

            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                // The call's first three arguments, each a single-quoted
                // literal, across the line breaks the codebase formats
                // them with.
                $pattern = "/->log\(\s*'[^']*',\s*'[^']*',\s*(?:[^,()]*\?\s*)?'([a-z_]+)'(?:\s*:\s*'([a-z_]+)')?/";
                if (preg_match_all($pattern, $source, $matches, PREG_SET_ORDER) === 0) {
                    continue;
                }

                foreach ($matches as $match) {
                    foreach (array_slice($match, 1) as $level) {
                        if ($level === '') {
                            continue;
                        }
                        $found[$level][] = str_replace(self::root() . '/', '', $file->getPathname());
                    }
                }
            }
        }

        return $found;
    }

    public function testEveryLevelWrittenAnywhereIsOneTheColumnAccepts(): void
    {
        $declared = self::declaredLevels();
        $written = self::loggedLevels();

        $this->assertNotEmpty($written, 'the scan found no journal call at all — the pattern broke');

        foreach ($written as $level => $sites) {
            $this->assertContains(
                $level,
                $declared,
                "Journal level '{$level}' is written by " . $sites[0] . ' (and '
                . (count($sites) - 1) . ' other call site(s)) but is not a member of event_log.level — '
                . 'MySQL refuses it under STRICT_TRANS_TABLES while SQLite stores it happily.'
            );
        }
    }

    /**
     * The four members, pinned. Not to forbid a fifth — but adding one is
     * a schema change every installation has to migrate before a call site
     * may use it, which is the opposite of a detail (see
     * `Core\Module\ModuleManager`'s own note about the one path that runs
     * before the migration).
     */
    public function testTheDeclaredLevelsAreTheFourTheCodebaseKnows(): void
    {
        $this->assertSame(['info', 'warning', 'security', 'error'], self::declaredLevels());
    }
}
