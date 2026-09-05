<?php

declare(strict_types=1);

namespace Tests\Core\Debug;

use Core\Database\InstrumentedPdo;
use Core\Database\QueryCounter;
use Core\Debug\RequestTimeline;
use PHPUnit\Framework\TestCase;

/**
 * RequestTimeline's activation state is memoized in static, process-wide
 * properties (by design — it must be readable from anywhere in the request
 * without threading an instance through every layer), which makes it
 * order-sensitive across tests in the same process. Reset via reflection
 * between tests rather than @runTestsInSeparateProcesses, which conflicts
 * with this environment's own PHP CLI startup warnings being (correctly)
 * treated as unexpected output by PHPUnit's process isolation.
 */
class RequestTimelineTest extends TestCase
{
    protected function setUp(): void
    {
        $reflection = new \ReflectionClass(RequestTimeline::class);
        $reflection->getProperty('active')->setValue(null, null);
        $reflection->getProperty('entries')->setValue(null, []);
    }

    protected function tearDown(): void
    {
        unset($_GET['debug']);
    }

    public function testIsActiveIsFalseWithoutTheDebugQueryParam(): void
    {
        $this->assertFalse(RequestTimeline::isActive());
    }

    public function testIsActiveIsTrueWithTheDebugQueryParam(): void
    {
        $_GET['debug'] = '1';

        $this->assertTrue(RequestTimeline::isActive());
    }

    public function testMarkIsANoOpWhenNotActive(): void
    {
        RequestTimeline::mark('should_not_be_recorded');

        $this->assertSame([], RequestTimeline::getEntries());
    }

    public function testMarkRecordsLabelTimingAndExtraContextWhenActive(): void
    {
        $_GET['debug'] = '1';

        RequestTimeline::mark('checkpoint_one');
        RequestTimeline::mark('checkpoint_two', ['task_count' => 3]);

        $entries = RequestTimeline::getEntries();

        $this->assertCount(2, $entries);
        $this->assertSame('checkpoint_one', $entries[0]['label']);
        $this->assertArrayHasKey('t_ms', $entries[0]);
        $this->assertArrayHasKey('mem_mb', $entries[0]);
        $this->assertArrayHasKey('sql', $entries[0]);
        $this->assertArrayHasKey('sql_ms', $entries[0]);
        $this->assertSame('checkpoint_two', $entries[1]['label']);
        $this->assertSame(3, $entries[1]['task_count']);
    }
    public function testEveryCheckpointCarriesTheRunningStatementCount(): void
    {
        $_GET['debug'] = '1';
        QueryCounter::reset();
        $pdo = new InstrumentedPdo('sqlite::memory:');

        RequestTimeline::mark('before');
        $pdo->exec('CREATE TABLE t (a INTEGER)');
        $pdo->query('SELECT 1');
        RequestTimeline::mark('after');

        [$before, $after] = RequestTimeline::getEntries();

        $this->assertSame(2, $after['sql'] - $before['sql'], 'the delta between two checkpoints is the segment\'s own statement count');
        $this->assertGreaterThanOrEqual($before['sql_ms'], $after['sql_ms']);
    }

    public function testActivateRecordsWithoutTheDebugQueryParam(): void
    {
        $this->assertFalse(RequestTimeline::wasRequested());

        RequestTimeline::activate();
        RequestTimeline::mark('inside_a_measurement_window');

        $this->assertTrue(RequestTimeline::isActive());
        $this->assertFalse(RequestTimeline::wasRequested(), 'a window is not an explicit request');
        $this->assertSame('inside_a_measurement_window', RequestTimeline::getEntries()[0]['label']);
    }

    public function testAnExplicitRequestIsToldApartFromAWindow(): void
    {
        $_GET['debug'] = '1';

        $this->assertTrue(RequestTimeline::wasRequested());
        $this->assertTrue(RequestTimeline::isActive());
    }
}
