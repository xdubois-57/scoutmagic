<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Geo;

use Core\Database\AdvisoryLock;
use Core\Geo\AddressLocator;
use Core\Geo\AddressLookup;
use Core\Geo\GeocodingService;
use Core\Geo\GeocodingThrottle;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The form lookup while the site-wide slot is busy (issue #692): it waits
 * its turn, gives way to the same person's newer request, and says
 * « unavailable » past its bound. Busy is only real with two connections to
 * the engine production runs — GET_LOCK() does not exist on SQLite, where
 * AddressLocatorTest runs.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class AddressLocatorOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private ?\PDO $holder = null;
    private \PDO $pdo;

    /** @var list<string> */
    private array $sent = [];

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->holder = self::productionEngineConnection(
            (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn()
        )->getPdo();
    }

    protected function tearDown(): void
    {
        if ($this->holder instanceof \PDO) {
            AdvisoryLock::release($this->holder, GeocodingThrottle::LOCK_NAME);
        }
        $this->holder = null;
    }

    public function testTheLookupWaitsForTheSlotThenFindsTheAddress(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME));
        $holder = $this->holder;
        $pauses = 0;

        $lookup = $this->locator(function () use (&$pauses, $holder): void {
            if (++$pauses === 3) {
                AdvisoryLock::release($holder, GeocodingThrottle::LOCK_NAME);
            }
        })->locate('Rue des Grottes 12, Han', 7);

        $this->assertSame(AddressLookup::FOUND, $lookup->status);
        $this->assertSame(['Rue des Grottes 12, Han'], $this->sent);
    }

    /**
     * The same chief corrects the address while the first lookup waits:
     * the first gives way without calling Nominatim.
     */
    public function testANewerRequestOfTheSamePersonSupersedesTheWaitingOne(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME));
        $pdo = $this->pdo;
        // The clock moves with every pause, so a regression that ignored
        // the newer request would end « unavailable » rather than loop.
        $now = 100.0;

        $lookup = $this->locator(function (int $us) use ($pdo, &$now): void {
            $now += $us / 1_000_000;
            $pdo->prepare('INSERT INTO geocoding_lookups (user_account_id, created_at) VALUES (?, ?)')
                ->execute([7, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
        }, function () use (&$now): float {
            return $now;
        })->locate('Rue des Grottes 12, Han', 7);

        $this->assertSame(AddressLookup::SUPERSEDED, $lookup->status);
        $this->assertSame([], $this->sent);
    }

    public function testAnotherPersonsRequestDoesNotSupersedeIt(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME));
        $pdo = $this->pdo;
        $holder = $this->holder;
        $pauses = 0;

        $lookup = $this->locator(function () use ($pdo, $holder, &$pauses): void {
            $pdo->prepare('INSERT INTO geocoding_lookups (user_account_id, created_at) VALUES (?, ?)')
                ->execute([8, (new \DateTimeImmutable())->format('Y-m-d H:i:s')]);
            if (++$pauses === 2) {
                AdvisoryLock::release($holder, GeocodingThrottle::LOCK_NAME);
            }
        })->locate('Rue des Grottes 12, Han', 7);

        $this->assertSame(AddressLookup::FOUND, $lookup->status);
    }

    public function testASlotBusyPastTheBoundIsUnavailableAndSendsNothing(): void
    {
        $this->assertTrue(AdvisoryLock::acquire($this->holder, GeocodingThrottle::LOCK_NAME));
        $now = 100.0;

        $lookup = $this->locator(function (int $us) use (&$now): void {
            $now += $us / 1_000_000;
        }, function () use (&$now): float {
            return $now;
        })->locate('Rue des Grottes 12, Han', 7);

        $this->assertSame(AddressLookup::UNAVAILABLE, $lookup->status);
        $this->assertSame([], $this->sent);
        $this->assertGreaterThanOrEqual(100.0 + AddressLocator::MAX_WAIT_SECONDS, $now);
    }

    /**
     * @param \Closure(int): void $pause
     * @param (\Closure(): float)|null $clock
     */
    private function locator(\Closure $pause, ?\Closure $clock = null): AddressLocator
    {
        $sent = &$this->sent;
        $geocoder = new class ($sent) extends GeocodingService {
            /** @param list<string> $sent */
            public function __construct(private array &$sent)
            {
                parent::__construct('https://unit.test');
            }

            public function geocodeLine(?string $line): ?array
            {
                $this->sent[] = (string) $line;

                return ['latitude' => 50.1234, 'longitude' => 4.5678];
            }
        };

        return new AddressLocator(
            $this->pdo,
            $geocoder,
            static function (int $us) use ($pause): void {
                $pause($us);
            },
            $clock ?? static fn(): float => 100.0
        );
    }
}
