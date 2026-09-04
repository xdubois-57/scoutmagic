<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Debug;

use Core\Debug\MeasurementWindow;
use PHPUnit\Framework\TestCase;

/**
 * The window is a flag file whose modification time is its expiry: these
 * tests drive it with an explicit clock so that "open", "expired" and
 * "closed early" are three states and not three waits.
 */
class MeasurementWindowTest extends TestCase
{
    private string $directory;
    private string $flag;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/scoutmagic-measure-' . bin2hex(random_bytes(6));
        $this->flag = $this->directory . '/temp/measure_until';
    }

    protected function tearDown(): void
    {
        @unlink($this->flag);
        @unlink($this->flag . '.count');
        @rmdir($this->directory . '/temp');
        @rmdir($this->directory);
    }

    public function testNothingIsOpenWhileNoFlagExists(): void
    {
        $window = new MeasurementWindow($this->flag);

        $this->assertFalse($window->isOpen());
        $this->assertNull($window->expiresAt());
        $this->assertNull($window->openedAt());
        $this->assertSame(0, $window->recordedRequests());
    }

    public function testOpeningCreatesTheDirectoryAndSetsTheExpiryOnTheFile(): void
    {
        $window = new MeasurementWindow($this->flag);
        $now = new \DateTimeImmutable('2026-09-04 10:00:00');

        $expiresAt = $window->open(5, $now);

        $this->assertSame('2026-09-04 10:05:00', $expiresAt->format('Y-m-d H:i:s'));
        $this->assertTrue($window->isOpen($now->getTimestamp()));
        $this->assertTrue($window->isOpen($now->getTimestamp() + 299));
        $this->assertFalse($window->isOpen($now->getTimestamp() + 300), 'closed at the expiry, without anybody closing it');
        $this->assertSame($now->getTimestamp(), $window->openedAt()?->getTimestamp());
    }

    public function testTheDurationIsClampedToTheMaximum(): void
    {
        $window = new MeasurementWindow($this->flag);
        $now = new \DateTimeImmutable('2026-09-04 10:00:00');

        $this->assertSame('10:15', $window->open(120, $now)->format('H:i'));
        $this->assertSame('10:01', $window->open(0, $now)->format('H:i'));
    }

    public function testClosingEarlyKeepsTheCountOfWhatWasRecorded(): void
    {
        $window = new MeasurementWindow($this->flag);
        $window->open(5);
        $this->assertTrue($window->recordRequest());
        $this->assertTrue($window->recordRequest());

        $window->close();

        $this->assertFalse($window->isOpen());
        $this->assertSame(2, $window->recordedRequests(), 'the Support page still says what the next archive carries');
    }

    public function testReopeningRestartsTheCount(): void
    {
        $window = new MeasurementWindow($this->flag);
        $window->open(5);
        $window->recordRequest();

        $window->open(5);

        $this->assertSame(0, $window->recordedRequests());
    }

    public function testTheCapStopsRecordingAndSaysSo(): void
    {
        $window = new MeasurementWindow($this->flag, 3);
        $window->open(5);

        $this->assertTrue($window->recordRequest());
        $this->assertTrue($window->recordRequest());
        $this->assertTrue($window->recordRequest());
        $this->assertFalse($window->recordRequest(), 'the fourth request is not journaled');
        $this->assertSame(3, $window->recordedRequests());
        $this->assertTrue($window->isOpen(), 'the cap does not close the window; its time does');
    }

    public function testTheFlagLivesWithTheOtherRequestTimeCaches(): void
    {
        $this->assertSame('/srv/site/storage/temp/measure_until', MeasurementWindow::flagPathIn('/srv/site/storage'));
    }
}
