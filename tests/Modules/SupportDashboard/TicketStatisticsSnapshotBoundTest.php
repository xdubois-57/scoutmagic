<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Modules\SupportDashboard\Repository\SupportTicketRepository;
use PHPUnit\Framework\TestCase;

/**
 * `SupportTicketRepository::encodeStatisticsSnapshot()` (issue #1486), with
 * no database: the JSON it hands create() never exceeds what the column
 * can take once encrypted, and every cut is named.
 * TicketStatisticsSnapshotOnMysqlTest replays it on the engine.
 */
final class TicketStatisticsSnapshotBoundTest extends TestCase
{
    private const MAX = SupportTicketRepository::MAX_STATISTICS_SNAPSHOT_BYTES;
    private const KEY = SupportTicketRepository::STATISTICS_TRUNCATION_KEY;

    /** @return array<string, mixed> */
    private static function report(): array
    {
        return [
            'statistics_schema_version' => 1,
            'scoutmagic' => ['version' => '1.0.40'],
            'usage' => ['active_members' => 118],
            'runtime' => ['php_version' => '8.4.1'],
        ];
    }

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
    }

    public function testAReportThatFitsIsWrittenWholeWithAccentsAndSlashesAsTheyAre(): void
    {
        $report = self::report() + ['hosting' => 'Hébergeur / mutualisé'];

        $snapshot = SupportTicketRepository::encodeStatisticsSnapshot($report);

        $this->assertNull($snapshot['original_bytes']);
        $this->assertStringContainsString('"Hébergeur / mutualisé"', $snapshot['json']);
        $this->assertSame($report, json_decode($snapshot['json'], true));
    }

    /**
     * What used to overflow: accents re-encoded as `é` tripled a
     * report that fitted in the body. Now it is cut, and says so.
     */
    public function testAnOversizedReportIsCutToTheBoundAndNamesWhatWent(): void
    {
        $report = self::report() + ['notes' => str_repeat('é/', 20000), 'modules' => ['news' => true]];

        $snapshot = SupportTicketRepository::encodeStatisticsSnapshot($report);
        $decoded = json_decode($snapshot['json'], true);

        $this->assertLessThanOrEqual(self::MAX, strlen($snapshot['json']));
        $this->assertSame(
            strlen((string) json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            $snapshot['original_bytes']
        );
        $this->assertSame(['news' => true], $decoded['modules'], 'only what had to go went');
        $this->assertArrayNotHasKey('notes', $decoded);
        $this->assertSame(
            ['original_bytes' => $snapshot['original_bytes'], 'dropped' => ['notes'], 'dropped_count' => 1],
            $decoded[self::KEY]
        );
        $this->assertSame('1.0.40', $decoded['scoutmagic']['version']);
    }

    /**
     * The largest section goes first, and the sections the ticket page
     * compares only once nothing else is left to drop: here a small extra
     * section is sacrificed before the oversized `usage`, which still has to
     * go, while `scoutmagic`, small and compared, stays.
     */
    public function testTheComparedSectionsAreDroppedLastAndTheLargestFirst(): void
    {
        $report = [
            'usage' => ['note' => str_repeat('a', 40000)],
            'extra' => str_repeat('b', 100),
            'scoutmagic' => ['version' => '1.0.40'],
            'modules' => str_repeat('c', 30000),
        ];

        $decoded = json_decode(SupportTicketRepository::encodeStatisticsSnapshot($report)['json'], true);

        $this->assertSame(['modules', 'extra', 'usage'], $decoded[self::KEY]['dropped']);
        $this->assertSame('1.0.40', $decoded['scoutmagic']['version']);
    }

    /**
     * Ten thousand tiny sections: the marker lists ten names and counts the
     * rest, so it cannot become what overflows, and the cut is measured
     * once rather than re-encoding the report per section dropped.
     */
    public function testAReportOfManySmallSectionsIsCutWithABoundedMarker(): void
    {
        $report = [];
        for ($i = 0; $i < 10000; $i++) {
            $report['section_' . $i . str_repeat('x', 80)] = $i;
        }

        $snapshot = SupportTicketRepository::encodeStatisticsSnapshot($report);
        $decoded = json_decode($snapshot['json'], true);

        $this->assertLessThanOrEqual(self::MAX, strlen($snapshot['json']));
        $this->assertCount(10, $decoded[self::KEY]['dropped']);
        $this->assertSame(10000 - (count($decoded) - 1), $decoded[self::KEY]['dropped_count']);
        foreach ($decoded[self::KEY]['dropped'] as $name) {
            $this->assertLessThanOrEqual(64, strlen($name));
        }
    }

    /** A report sent as a JSON list is still bounded, and marked as an object. */
    public function testAListIsBoundedToo(): void
    {
        $snapshot = SupportTicketRepository::encodeStatisticsSnapshot(array_fill(0, 5000, 'éééééééé'));

        $this->assertLessThanOrEqual(self::MAX, strlen($snapshot['json']));
        $this->assertArrayHasKey(self::KEY, json_decode($snapshot['json'], true));
    }

    /** A marker the sender forged is replaced by the real one, never kept beside it. */
    public function testASenderCannotWriteTheMarkerOfACut(): void
    {
        $report = self::report() + [self::KEY => str_repeat('z', 40000), 'notes' => str_repeat('a', 40000)];

        $decoded = json_decode(SupportTicketRepository::encodeStatisticsSnapshot($report)['json'], true);

        $this->assertSame(['notes'], $decoded[self::KEY]['dropped']);
    }
}
