<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service;

use Modules\Gallery\Service\FfmpegAvailability;
use PHPUnit\Framework\TestCase;

class FfmpegAvailabilityTest extends TestCase
{
    public function testCheckReturnsAConsistentResultAcrossCalls(): void
    {
        $availability = new FfmpegAvailability();

        $first = $availability->check();
        $second = $availability->check();

        $this->assertSame($first, $second);
        $this->assertIsBool($first);
    }
}
