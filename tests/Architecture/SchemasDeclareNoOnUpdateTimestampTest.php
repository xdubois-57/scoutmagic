<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Architecture;

use PHPUnit\Framework\TestCase;

/**
 * No schema declares `ON UPDATE CURRENT_TIMESTAMP` (issue #590).
 *
 * `Core\Database\SqlParser` does not model the clause, so the migration
 * dropped it without a word: fourteen `updated_at` columns declared it and
 * no installed site had it. A reader of `schema.sql` believed the engine
 * kept those timestamps current; nothing did but the repositories that
 * wrote them — which, it turned out, every one that mattered already did.
 *
 * The clause was removed rather than taught to the migration: restoring it
 * would have changed production behaviour for no visible gain, required
 * reading it back through two engines that report it differently, and left
 * a trap in every future bookkeeping UPDATE (the rental carry-over had
 * already fallen into it). `updated_at` is written from PHP, as the
 * calendar, news and presences modules had done from the start.
 *
 * Checked here rather than refused by the parser: `SqlParser` runs during
 * deployments, automatic updates and backup restores, where a new
 * exception would stop an installation; CI is where a schema author is
 * meant to hear about it.
 */
final class SchemasDeclareNoOnUpdateTimestampTest extends TestCase
{
    public function testNoSchemaDeclaresTheClause(): void
    {
        $offenders = [];
        $files = self::schemaFiles();

        foreach ($files as $path => $sql) {
            foreach (explode("\n", self::withoutComments($sql)) as $index => $line) {
                if (self::declaresTheClause($line)) {
                    $offenders[] = $path . ':' . ($index + 1) . '  ' . trim($line);
                }
            }
        }

        $this->assertGreaterThanOrEqual(10, count($files), 'the scan found almost no schema files to read');
        $this->assertSame(
            [],
            $offenders,
            "ON UPDATE CURRENT_TIMESTAMP is not built by the migration (issue #590): it would be dropped\n"
            . "silently. Write updated_at from the repository instead:\n  " . implode("\n  ", $offenders) . "\n"
        );
    }

    /** A floor guards against a reader that finds nothing; a known answer guards against one that approves everything. */
    public function testTheReaderSeesTheClauseAndNotItsExplanation(): void
    {
        $this->assertTrue(self::declaresTheClause(
            'updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,'
        ));
        $this->assertTrue(self::declaresTheClause('updated_at TIMESTAMP on update current_timestamp()'));
        $this->assertFalse(self::declaresTheClause(
            'CONSTRAINT fk_x FOREIGN KEY (a) REFERENCES b(id) ON DELETE CASCADE ON UPDATE CASCADE'
        ));
        $this->assertFalse(self::declaresTheClause(
            self::withoutComments('    -- No "ON UPDATE CURRENT_TIMESTAMP" — the migration drops it.')
        ));
    }

    private static function declaresTheClause(string $line): bool
    {
        return preg_match('/\bON\s+UPDATE\s+CURRENT_TIMESTAMP\b/i', $line) === 1;
    }

    /** `--` comments blanked, line numbers kept: the four schemas that explain the clause's absence name it. */
    private static function withoutComments(string $sql): string
    {
        return (string) preg_replace('/--[^\n]*/', '', $sql);
    }

    /**
     * @return array<string, string> relative path => SQL
     */
    private static function schemaFiles(): array
    {
        $root = dirname(__DIR__, 2);
        $paths = array_merge([$root . '/schema/core.sql'], glob($root . '/modules/*/schema.sql') ?: []);
        $files = [];

        foreach ($paths as $path) {
            $sql = is_file($path) ? file_get_contents($path) : false;
            if ($sql !== false) {
                $files[str_replace($root . '/', '', $path)] = $sql;
            }
        }

        return $files;
    }
}
