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
use Modules\Rental\Repository\AssetLock;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAssetService;
use Modules\Rental\Service\RentalException;
use Modules\Rental\Service\RentalSlugGenerator;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * Deleting an asset on the engine production runs (#1307), with **two real
 * connections**: the check that nothing records a history and the deletion
 * that relies on it are one decision, and a booking validated between the
 * two would be missed by the check and then taken with the asset by
 * `ON DELETE CASCADE` on `rental_bookings.asset_id`.
 *
 * Every writer of a booking takes the asset's row first (`AssetLock`), so
 * the deletion takes the same lock: it waits for a booking being written,
 * and reads the history only once it has the row. SQLite has no
 * `FOR UPDATE` and cannot show the wait, which is why this runs on the real
 * engine.
 */
#[Group('database')]
final class RentalAssetDeleteOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->assetId = (new RentalAssetRepository($this->pdo, self::encryption()))
            ->create('local', 'Local Saint-Georges', 'local', 60, 1, null, null, null, true);
    }

    /**
     * What the cascade needs is a booking written **between** the check and
     * the `DELETE`. The `DELETE` waits for a held row on its own, so waiting
     * proves nothing; the question is whether a writer of a booking can take
     * the asset's lock at the moment between the two. It must not: the check
     * and the deletion hold the lock together. The probe runs on the writer's
     * connection right after the check has answered « no history ».
     */
    public function testNoBookingWriterCanTakeTheLockBetweenTheCheckAndTheDeletion(): void
    {
        $writer = $this->pdo;
        $writer->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $deleter = $this->secondConnection();

        $probe = null;
        $assets = new class ($deleter, self::encryption(), function () use ($writer, &$probe): void {
            try {
                AssetLock::run($writer, $this->assetId, static function (): void {
                });
                $probe = 'the writer got the lock';
            } catch (\Throwable $e) {
                $probe = self::isLockTimeout($e) ? 'the writer waited' : 'failed: ' . $e->getMessage();
            }
        }) extends RentalAssetRepository {
            public function __construct(\PDO $pdo, EncryptionService $encryption, private \Closure $afterTheCheck)
            {
                parent::__construct($pdo, $encryption);
            }

            public function hasBookings(int $id): bool
            {
                $has = parent::hasBookings($id);
                ($this->afterTheCheck)();

                return $has;
            }
        };
        $service = new RentalAssetService(
            $assets,
            new RentalSlugGenerator($assets),
            new JournalService(new JournalRepository($deleter))
        );

        $service->delete($this->assetId, null);

        $this->assertSame(
            'the writer waited',
            $probe,
            'a booking could be written between the history check and the deletion'
        );
        $this->assertNull((new RentalAssetRepository($this->pdo, self::encryption()))->findById($this->assetId));
    }

    public function testTheDeletionGoesThroughOnceTheLockIsReleased(): void
    {
        $other = $this->secondConnection();

        self::service($other)->delete($this->assetId, null);

        $this->assertNull((new RentalAssetRepository($this->pdo, self::encryption()))->findById($this->assetId));
        $this->assertFalse($other->inTransaction(), 'the lock transaction must be closed');
    }

    /**
     * `AssetLock` is re-entrant: called inside a transaction already open, it
     * joins it. Under REPEATABLE READ an ordinary `SELECT` then reads the
     * snapshot taken by the caller's **first read**, so a booking committed
     * after it — validated under the very lock this deletion has just taken —
     * would not be seen, and `ON DELETE CASCADE` would take it with the asset
     * (#1321). The history check is a current read, whatever the caller did
     * before.
     *
     * No caller does this today; the test is what keeps the next one safe.
     */
    public function testABookingCommittedAfterTheCallersSnapshotStillRefusesTheDeletion(): void
    {
        $callerPdo = $this->pdo;
        $writer = $this->secondConnection();

        $callerPdo->beginTransaction();
        // The caller's first consistent read: it fixes the snapshot.
        $callerPdo->query('SELECT COUNT(*) FROM rental_bookings')->fetchColumn();

        $writer->prepare(
            'INSERT INTO rental_bookings
                (asset_id, reference, arrival_date, departure_date,
                 renter_name_encrypted, renter_email_encrypted, renter_email_blind_index, tracking_token_encrypted)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$this->assetId, 'DEL-' . bin2hex(random_bytes(6)), '2026-10-01', '2026-10-03', 'x', 'x', str_repeat('a', 64), 'x']);

        try {
            self::service($callerPdo)->delete($this->assetId, null);
            $this->fail('An asset holding a booking must not be deleted.');
        } catch (RentalException) {
            $this->addToAssertionCount(1);
        } finally {
            $callerPdo->rollBack();
        }

        $this->assertNotNull(
            (new RentalAssetRepository($this->pdo, self::encryption()))->findById($this->assetId),
            'the asset must still be there'
        );
        $this->assertSame(
            1,
            (int) $writer->query('SELECT COUNT(*) FROM rental_bookings WHERE asset_id = ' . $this->assetId)->fetchColumn(),
            'the booking must not have been taken by the cascade'
        );
    }

    private static function service(\PDO $pdo): RentalAssetService
    {
        $assets = new RentalAssetRepository($pdo, self::encryption());

        return new RentalAssetService(
            $assets,
            new RentalSlugGenerator($assets),
            new JournalService(new JournalRepository($pdo))
        );
    }

    private static function isLockTimeout(\Throwable $e): bool
    {
        for ($cause = $e; $cause !== null; $cause = $cause->getPrevious()) {
            // 1205: ER_LOCK_WAIT_TIMEOUT. Any other failure — a deadlock, a
            // dropped connection — proves nothing about the asset's lock.
            if ($cause instanceof \PDOException && (int) ($cause->errorInfo[1] ?? 0) === 1205) {
                return true;
            }
        }

        return false;
    }

    private static function encryption(): EncryptionService
    {
        return new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    private function secondConnection(): \PDO
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();
        $other = self::productionEngineConnection($database)->getPdo();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        return $other;
    }
}
