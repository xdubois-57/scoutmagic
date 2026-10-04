<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Repository\RentalBlockRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * The lock a calendar gesture plans under (#708, IT-07), on the engine
 * that actually takes it.
 *
 * `RentalBlockRepository::withAssetLocked()` reads an asset's blocks, plans
 * a gesture and writes it while holding the asset's row, so two gestures
 * sent before the first answer cannot both plan from the same snapshot.
 * SQLite, the ordinary test engine, takes the method's other branch — it
 * serialises writers on its own and has no `FOR UPDATE` — so every other
 * test of a gesture runs without the one statement that matters in
 * production. This class runs it on the production schema
 * (`Tests\UsesProductionEngine`) and watches a second session wait.
 */
#[Group('database')]
final class RentalBlockRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private RentalBlockRepository $repository;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->repository = new RentalBlockRepository($this->pdo);

        $this->pdo->exec(
            "INSERT INTO rental_assets (asset_type, name, slug) VALUES ('building', 'Local', 'local')"
        );
        $this->assetId = (int) $this->pdo->lastInsertId();
    }

    /**
     * The premise: this connection is not SQLite, so `withAssetLocked()`
     * takes the branch that issues the lock. Were it SQLite, the tests
     * below would pass without testing anything.
     */
    public function testThisEngineTakesTheLockingBranch(): void
    {
        $this->assertNotSame('sqlite', $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
    }

    /**
     * While a gesture holds the asset, a second session asking for the
     * same row waits — and gives up after its own one-second timeout,
     * since it runs inside the first session's work.
     */
    public function testASecondGestureWaitsWhileTheFirstHoldsTheAsset(): void
    {
        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');

        $blocked = null;
        $this->repository->withAssetLocked($this->assetId, function () use ($other, &$blocked): void {
            try {
                $other->beginTransaction();
                $probe = $other->prepare('SELECT id FROM rental_assets WHERE id = ? FOR UPDATE');
                $probe->execute([$this->assetId]);
                $probe->fetchAll();
                $blocked = false;
            } catch (\PDOException $e) {
                $blocked = str_contains(strtolower($e->getMessage()), 'lock');
            } finally {
                if ($other->inTransaction()) {
                    $other->rollBack();
                }
            }
        });

        $this->assertTrue(
            $blocked,
            'a second session took the asset while a gesture held it, so two gestures can still plan '
                . 'from the same blocks'
        );
    }

    /**
     * The lock is released with the work, and what the work wrote is
     * committed — a second session sees it and can take the row.
     */
    public function testTheWorkIsCommittedAndTheAssetReleased(): void
    {
        $this->repository->withAssetLocked($this->assetId, function (): void {
            $this->repository->replace($this->assetId, [], [
                ['start' => '2027-07-10', 'end' => '2027-07-12', 'reason' => 'Camp'],
            ], null);
        });

        $other = $this->secondConnection();
        $other->exec('SET SESSION innodb_lock_wait_timeout = 1');
        $other->beginTransaction();
        $probe = $other->prepare('SELECT id FROM rental_assets WHERE id = ? FOR UPDATE');
        $probe->execute([$this->assetId]);
        $this->assertSame([$this->assetId], array_map('intval', $probe->fetchAll(\PDO::FETCH_COLUMN)));
        $other->rollBack();

        $count = $other->prepare('SELECT COUNT(*) FROM rental_blocks WHERE asset_id = ?');
        $count->execute([$this->assetId]);
        $this->assertSame(1, (int) $count->fetchColumn());
    }

    private function secondConnection(): \PDO
    {
        $database = (string) $this->pdo->query('SELECT DATABASE()')->fetchColumn();

        return self::productionEngineConnection($database)->getPdo();
    }
}
