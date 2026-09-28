<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Database\AdvisoryLock;
use Core\Geo\GeocodingThrottle;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The one-per-second limiter every Nominatim call shares (the form lookup
 * and both background tasks). Exclusion is only worth anything when it is
 * real, so this uses two genuine connections to the real database — the
 * shape of Tests\Modules\SupportDashboard\RateLimitReservationLockTest.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class GeocodingThrottleTest extends TestCase
{
    use UsesProductionEngine;

    private ?\PDO $holder = null;
    private ?\PDO $caller = null;

    protected function setUp(): void
    {
        $this->caller = $this->productionEngine();
        $this->holder = self::productionEngineConnection(
            (string) $this->caller->query('SELECT DATABASE()')->fetchColumn()
        )->getPdo();
    }

    protected function tearDown(): void
    {
        if ($this->holder instanceof \PDO) {
            AdvisoryLock::release($this->holder, GeocodingThrottle::LOCK_NAME);
        }
        $this->holder = null;
        $this->caller = null;
    }

    public function testACallWhileAnotherHoldsTheSlotDoesNotRunAndDoesNotWait(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME));
        $pauses = [];
        $ran = false;

        $result = (new GeocodingThrottle($this->caller, function (int $us) use (&$pauses): void {
            $pauses[] = $us;
        }))->run(function () use (&$ran): string {
            $ran = true;

            return 'sent';
        });

        $this->assertSame([false, null], $result);
        $this->assertFalse($ran, 'nothing may reach Nominatim while another call holds the slot');
        $this->assertSame([], $pauses, 'a refused call does not wait either');
    }

    public function testTheSlotIsFreeAgainOnceTheCallAndItsSecondAreOver(): void
    {
        $throttle = new GeocodingThrottle($this->caller, static function (int $us): void {
        });

        $this->assertSame([true, 'first'], $throttle->run(static fn(): string => 'first'));
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME), 'released afterwards');
    }
}
