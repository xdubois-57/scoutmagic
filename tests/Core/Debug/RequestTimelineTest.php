<?php

declare(strict_types=1);

namespace Tests\Core\Debug;

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
        $this->assertSame('checkpoint_two', $entries[1]['label']);
        $this->assertSame(3, $entries[1]['task_count']);
    }
}
