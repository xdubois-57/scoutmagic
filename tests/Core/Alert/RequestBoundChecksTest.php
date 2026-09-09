<?php

declare(strict_types=1);

namespace Tests\Core\Alert;

use Core\Alert\RequestBoundChecks;
use PHPUnit\Framework\TestCase;

/**
 * The throttle for the two checks that run on a web request.
 *
 * Its whole job is that an ordinary request costs one `stat()` and no
 * database write — the difference between a check the site can afford on
 * every page view and one it cannot.
 */
class RequestBoundChecksTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/request_bound_checks_test_' . uniqid();
        mkdir($this->storagePath . '/temp', 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->storagePath . '/temp/operational-request-checks');
        @rmdir($this->storagePath . '/temp');
        @rmdir($this->storagePath);
    }

    /**
     * A fresh installation, or one whose storage/temp was just swept,
     * evaluates rather than waiting out an interval it has no record of.
     */
    public function testWithNoMarkerItIsDue(): void
    {
        $this->assertTrue((new RequestBoundChecks($this->storagePath))->due());
    }

    public function testItIsNotDueAgainStraightAfterARun(): void
    {
        $checks = new RequestBoundChecks($this->storagePath);
        $checks->markRun();

        $this->assertFalse($checks->due());
    }

    public function testItIsDueAgainOnceTheIntervalHasPassed(): void
    {
        $checks = new RequestBoundChecks($this->storagePath);
        $checks->markRun();

        $later = time() + RequestBoundChecks::INTERVAL_SECONDS + 1;

        $this->assertTrue($checks->due($later));
    }

    public function testTheBoundaryItselfCounts(): void
    {
        $checks = new RequestBoundChecks($this->storagePath);
        $checks->markRun();

        $this->assertFalse($checks->due(time() + RequestBoundChecks::INTERVAL_SECONDS - 1));
        $this->assertTrue($checks->due(time() + RequestBoundChecks::INTERVAL_SECONDS));
    }

    /**
     * A read-only storage directory must degrade to "evaluate every
     * request" — worse for performance, perfectly correct, and never a
     * 500 on a page that was only being visited.
     */
    public function testAnUnwritableMarkerDegradesToAlwaysDueRatherThanFailing(): void
    {
        $checks = new RequestBoundChecks('/proc/nonexistent-and-unwritable');

        $checks->markRun();

        $this->assertTrue($checks->due());
    }
}
