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

    /**
     * A form lookup waits for the slot instead of giving up (issue #692):
     * it tries again every tenth of a second and runs the moment the
     * holder lets go — here, after its second try.
     */
    public function testAWaitingCallRunsOnceTheSlotIsFree(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME));
        $holder = $this->holder;
        $pauses = [];
        $throttle = new GeocodingThrottle($this->caller, function (int $us) use (&$pauses, $holder): void {
            $pauses[] = $us;
            if (count($pauses) === 2) {
                AdvisoryLock::release($holder, GeocodingThrottle::LOCK_NAME);
            }
        }, static fn(): float => 100.0);

        $result = $throttle->runWaiting(static fn(): string => 'sent', 3.0, static fn(): bool => true);

        $this->assertSame([GeocodingThrottle::RAN, 'sent'], $result);
        $this->assertSame([100_000, 100_000], array_slice($pauses, 0, 2), 'two short retries, then its turn');
    }

    public function testAWaitStopsAtItsDeadlineAndSendsNothing(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME));
        $now = 100.0;
        $ran = false;
        $throttle = new GeocodingThrottle($this->caller, function (int $us) use (&$now): void {
            $now += $us / 1_000_000;
        }, function () use (&$now): float {
            return $now;
        });

        $result = $throttle->runWaiting(function () use (&$ran): string {
            $ran = true;

            return 'sent';
        }, 3.0, static fn(): bool => true);

        $this->assertSame([GeocodingThrottle::TIMED_OUT, null], $result);
        $this->assertFalse($ran);
        $this->assertGreaterThanOrEqual(103.0, $now, 'it did wait the whole bound');
        $this->assertLessThan(103.2, $now, 'and not longer');
    }

    public function testACallNoLongerWantedStopsWaitingAndSendsNothing(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME));
        $asked = 0;
        $ran = false;
        $throttle = new GeocodingThrottle($this->caller, static function (int $us): void {
        }, static fn(): float => 100.0);

        $result = $throttle->runWaiting(function () use (&$ran): string {
            $ran = true;

            return 'sent';
        }, 3.0, function () use (&$asked): bool {
            return ++$asked < 3;
        });

        $this->assertSame([GeocodingThrottle::NOT_WANTED, null], $result);
        $this->assertFalse($ran);
        $this->assertSame(3, $asked, 'asked before every try');
    }

    /**
     * Asked once more when the slot is taken: a newer request that arrived
     * during the try itself still wins, and the slot is given back at once.
     */
    public function testACallNoLongerWantedOnceTheSlotIsTakenGivesItBack(): void
    {
        $asked = 0;
        $ran = false;
        $throttle = new GeocodingThrottle($this->caller, static function (int $us): void {
        }, static fn(): float => 100.0);

        $result = $throttle->runWaiting(function () use (&$ran): string {
            $ran = true;

            return 'sent';
        }, 3.0, function () use (&$asked): bool {
            return ++$asked < 2;
        });

        $this->assertSame([GeocodingThrottle::NOT_WANTED, null], $result);
        $this->assertFalse($ran);
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME), 'the slot was given back');
    }
}
