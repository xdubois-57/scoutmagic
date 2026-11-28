<?php

declare(strict_types=1);

namespace Tests\Architecture;

use Core\Journal\JournalService;
use PHPUnit\Framework\TestCase;

/**
 * The bounds JournalService::log() cuts to are the widths of the
 * `event_log` columns they fill (issue #1438).
 *
 * The suite cannot see a line that is too long: `Tests\DatabaseTestHelper`
 * builds these columns as SQLite `TEXT`, which stores anything, while
 * MySQL/MariaDB in strict mode refuse the character past a VARCHAR's
 * width — the trap JournalLevelsTest guards for `level`. Reading the
 * schema keeps the constants from drifting from the columns.
 */
final class JournalColumnWidthsTest extends TestCase
{
    /** @return iterable<string, array{string, int}> */
    public static function columns(): iterable
    {
        yield 'description' => ['description', JournalService::DESCRIPTION_MAX];
        yield 'category' => ['category', JournalService::CATEGORY_MAX];
        yield 'event_type' => ['event_type', JournalService::TYPE_MAX];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('columns')]
    public function testTheJournalCutsToTheWidthOfTheColumn(string $column, int $bound): void
    {
        $schema = (string) file_get_contents(dirname(__DIR__, 2) . '/schema/core.sql');
        $this->assertSame(
            1,
            preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?event_log`?\s*\((.*?)\n\)/s', $schema, $table),
            'event_log must stay declared in schema/core.sql'
        );
        $this->assertSame(
            1,
            preg_match('/^\s*`?' . $column . '`?\s+VARCHAR\((\d+)\)/m', $table[1], $width),
            "event_log.{$column} must stay a VARCHAR"
        );

        $this->assertSame((int) $width[1], $bound);
    }
}
