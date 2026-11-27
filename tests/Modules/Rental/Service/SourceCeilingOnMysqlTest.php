<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Service;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The anonymous source's ceiling is exact under simultaneous requests
 * (#1399), with **two real connections** on the engine production runs.
 *
 * An anonymous request is counted on its source — an IPv4 address or an
 * IPv6 /64 — whichever asset it asks for: at most
 * `MAX_RUNNING_AUTOMATIC_HOLDS_PER_SOURCE` automatic holds running. The
 * count is made under the asset's lock, which is the asset's own: two
 * requests of one source for two DIFFERENT assets did not see each other,
 * and could both be given the last hold. A named lock per source, taken
 * before the asset's, serialises them; the one that does not get it is
 * recorded without a hold, as a request over a ceiling always is, and is
 * never refused.
 */
#[Group('database')]
final class SourceCeilingOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private const SOURCE = '198.51.100.7';

    private \PDO $pdo;
    /** @var int[] */
    private array $assetIds = [];

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $assets = new RentalAssetRepository($this->pdo, self::encryption());
        foreach (['a', 'b', 'c', 'd', 'e'] as $slug) {
            $this->assetIds[] = $assets->create(
                'Local ' . $slug,
                'Local ' . $slug,
                'local-' . $slug,
                60,
                1,
                null,
                null,
                null,
                true
            );
        }
    }

    /** The premise: the locking branch runs here. On SQLite nothing below would test anything. */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /**
     * Two holds already running for the source, then two requests of that
     * same source for two other assets at once: the second is made while
     * the first is between its count and its write. Only one of them may
     * be given the last hold — three in all, never four.
     */
    public function testTwoSimultaneousRequestsOfOneSourceCannotBothTakeTheLastHold(): void
    {
        $other = $this->secondConnection();
        $hooked = new HookedBookingRepository($this->pdo, self::encryption());
        $mine = $this->stack($this->pdo, $hooked);
        $theirs = $this->stack($other);
        $now = new \DateTimeImmutable('2027-02-01 10:00:00');
        $this->request($mine, $this->assetIds[0], 'un@example.be', $now);
        $this->request($mine, $this->assetIds[1], 'deux@example.be', $now);
        $this->assertSame(2, $mine['bookings']->countRunningAutomaticHoldsFromSource(self::SOURCE, $now));

        // Between the count of the source's holds and the write of the third:
        // exactly where two requests of one source did not see each other.
        $inner = null;
        $hooked->beforeCreate = function () use ($theirs, $now, &$inner, $hooked): void {
            $hooked->beforeCreate = null;
            $inner = $this->request($theirs, $this->assetIds[3], 'quatre@example.be', $now);
        };
        $outer = $this->request($mine, $this->assetIds[2], 'trois@example.be', $now);

        $this->assertNotNull($outer->holdUntil, 'the first request holds the last place');
        $this->assertNull($inner?->holdUntil, 'the second one, made at the same time, was given a place too');
        $this->assertSame(3, $mine['bookings']->countRunningAutomaticHoldsFromSource(self::SOURCE, $now));
    }

    /** The request that stands down is recorded all the same: never refused. */
    public function testTheRequestThatStandsDownIsRecordedNotRefused(): void
    {
        $other = $this->secondConnection();
        $hooked = new HookedBookingRepository($this->pdo, self::encryption());
        $mine = $this->stack($this->pdo, $hooked);
        $theirs = $this->stack($other);
        $now = new \DateTimeImmutable('2027-02-01 10:00:00');

        $inner = null;
        $hooked->beforeCreate = function () use ($theirs, $now, &$inner, $hooked): void {
            $hooked->beforeCreate = null;
            $inner = $this->request($theirs, $this->assetIds[1], 'deux@example.be', $now);
        };
        $this->request($mine, $this->assetIds[0], 'un@example.be', $now);

        $this->assertNotNull($inner, 'the second request was not recorded');
        $this->assertNull($inner->holdUntil);
        $this->assertSame(2, $this->bookingCount());
    }

    /** Another source has its own lock: it is not made to wait, nor stripped of its hold. */
    public function testAnotherSourceIsNotAffectedByTheLockOfThisOne(): void
    {
        $other = $this->secondConnection();
        $hooked = new HookedBookingRepository($this->pdo, self::encryption());
        $mine = $this->stack($this->pdo, $hooked);
        $theirs = $this->stack($other);
        $now = new \DateTimeImmutable('2027-02-01 10:00:00');

        $inner = null;
        $hooked->beforeCreate = function () use ($theirs, $now, &$inner, $hooked): void {
            $hooked->beforeCreate = null;
            $inner = $this->request($theirs, $this->assetIds[1], 'deux@example.be', $now, null, '203.0.113.20');
        };
        $this->request($mine, $this->assetIds[0], 'un@example.be', $now);

        $this->assertNotNull($inner?->holdUntil);
    }

    /**
     * The source's lock goes with the request, whatever became of it:
     * after a request refused for its dates, the next one of that source
     * is given its place.
     */
    public function testTheSourceLockIsReleasedWhenTheRequestIsRefused(): void
    {
        $mine = $this->stack($this->pdo);
        $now = new \DateTimeImmutable('2027-02-01 10:00:00');

        try {
            $this->request($mine, $this->assetIds[0], 'un@example.be', $now, function (): array {
                throw new RentalException('Ces dates ne sont plus disponibles.');
            });
            $this->fail('the request should have been refused');
        } catch (RentalException) {
        }

        $third = $this->secondConnection();
        $this->assertTrue($this->stack($third)['bookings']->lockSource(self::SOURCE), 'the lock was kept');
    }

    public function testASourceIsLockedByOneConnectionAtATime(): void
    {
        $mine = $this->stack($this->pdo)['bookings'];
        $theirs = $this->stack($this->secondConnection())['bookings'];

        $this->assertTrue($mine->lockSource(self::SOURCE));
        $this->assertFalse($theirs->lockSource(self::SOURCE));
        $this->assertTrue($theirs->lockSource('203.0.113.20'), 'another source has its own lock');

        $mine->unlockSource(self::SOURCE);
        $this->assertTrue($theirs->lockSource(self::SOURCE));
    }

    /** One IPv6 /64 is one source, whichever address of it is typed. */
    public function testTwoAddressesOfOneIpv6SixtyFourShareALock(): void
    {
        $mine = $this->stack($this->pdo)['bookings'];
        $theirs = $this->stack($this->secondConnection())['bookings'];

        $this->assertTrue($mine->lockSource('2001:db8:1:2:ffff::9'));
        $this->assertFalse($theirs->lockSource('2001:db8:1:2::1'));
    }

    /** No source known: nothing to count, so nothing to serialise — never one lock for all of them. */
    public function testAnUnknownSourceTakesNoLock(): void
    {
        $mine = $this->stack($this->pdo)['bookings'];
        $theirs = $this->stack($this->secondConnection())['bookings'];

        $this->assertTrue($mine->lockSource(''));
        $this->assertTrue($theirs->lockSource(''));
    }

    private static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    /**
     * The services one request builds, on one connection.
     *
     * @return array{bookings: RentalBookingRepository, booking: RentalBookingService}
     */
    private function stack(\PDO $pdo, ?HookedBookingRepository $bookings = null): array
    {
        $bookings ??= new RentalBookingRepository($pdo, self::encryption());

        return [
            'bookings' => $bookings,
            'booking' => new RentalBookingService($bookings, new JournalService(new JournalRepository($pdo))),
        ];
    }

    /**
     * One anonymous public request for $assetId from $source, with its own
     * address and its own stay — dates never overlap, so only the ceilings
     * can answer for the hold.
     *
     * @param array{bookings: RentalBookingRepository, booking: RentalBookingService} $stack
     * @param (\Closure(): mixed)|null $alsoUnderLock run in the availability check, under the asset's lock
     */
    private function request(
        array $stack,
        int $assetId,
        string $email,
        \DateTimeImmutable $now,
        ?\Closure $alsoUnderLock = null,
        string $source = self::SOURCE
    ): \Modules\Rental\Booking\RentalBooking {
        $check = function () use ($alsoUnderLock): array {
            if ($alsoUnderLock !== null) {
                $alsoUnderLock();
            }

            return [];
        };

        return $stack['booking']->createFromPublicRequest(
            $assetId,
            '2027-07-01',
            '2027-07-04',
            1,
            10,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => $email,
                'phone' => '+32 495 11 22 33',
                'organisation' => null,
                'purpose' => 'Week-end',
                'comment' => null,
            ],
            null,
            ['conditions_version' => 'v1', 'conditions_text' => 'x', 'privacy_version' => 'v1', 'privacy_text' => 'y'],
            $now,
            30,
            $check,
            null,
            $source
        )['booking'];
    }

    private function bookingCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rental_bookings')->fetchColumn();
    }

    private function secondConnection(): \PDO
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $other;
    }
}

/**
 * The booking repository, with a gesture of the test run just before the
 * write: between a request's count of its source's holds and its insert.
 */
final class HookedBookingRepository extends RentalBookingRepository
{
    public ?\Closure $beforeCreate = null;

    public function create(mixed ...$arguments): array
    {
        if ($this->beforeCreate !== null) {
            ($this->beforeCreate)();
        }

        return parent::create(...$arguments);
    }
}
