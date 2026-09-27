<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Registration\Repository;

use Modules\Registration\Repository\SlotCapacityRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\UsesProductionEngine;

/**
 * `SlotCapacityRepository`'s two insert-or-update paths, on the engine
 * production runs (issue #481 part D).
 *
 * - `upsert()` — SELECT, then UPDATE or INSERT — is what the
 *   configuration form calls for every slot of every branch on each save,
 *   so most calls write the capacity already stored: an UPDATE that
 *   changes no row on this engine.
 * - `insertIfMissing()` seeds the default on page load and lets the
 *   UNIQUE index on (branch, year in branch) settle a race between two
 *   chiefs, swallowing the `PDOException` the loser gets. That catch is
 *   only right if the engine really raises one, which the premise test
 *   checks rather than assumes.
 *
 * `capacity` is `INT UNSIGNED NULL` on the real table, where NULL (« pas
 * de limite ») and 0 (« branche fermée ») must stay two answers.
 */
#[Group('database')]
class SlotCapacityRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;
    private SlotCapacityRepository $capacities;
    private int $branchId;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
        $this->capacities = new SlotCapacityRepository($this->pdo);
        $this->branchId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
    }

    /**
     * The premise of `insertIfMissing()`'s catch: a second row for one
     * slot is refused by the engine with a duplicate-key error, so the
     * chief who loses the race gets an exception, not a second row.
     */
    public function testTheEngineRefusesASecondRowForTheSameSlot(): void
    {
        $insert = $this->pdo->prepare(
            'INSERT INTO registration_slot_capacities (age_branch_id, year_in_branch, capacity) VALUES (?, ?, ?)'
        );
        $insert->execute([$this->branchId, 1, 12]);

        try {
            $insert->execute([$this->branchId, 1, 12]);
            $this->fail('a second capacity row for the same slot was accepted.');
        } catch (\PDOException $e) {
            $this->assertSame('1062', (string) ($e->errorInfo[1] ?? ''), $e->getMessage());
        }
    }

    public function testUpsertCreatesThenUpdatesOneRow(): void
    {
        $this->capacities->upsert($this->branchId, 1, 20);
        $this->capacities->upsert($this->branchId, 1, 25);

        $this->assertSame(25, $this->capacities->capacityFor($this->branchId, 1));
        $this->assertSame(1, $this->countRows());
    }

    /** The form saved again without a change: no row changes, none is added. */
    public function testUpsertingTheStoredCapacityAgainKeepsOneRow(): void
    {
        $this->capacities->upsert($this->branchId, 2, 18);
        $this->capacities->upsert($this->branchId, 2, 18);
        $this->capacities->upsert($this->branchId, 3, null);
        $this->capacities->upsert($this->branchId, 3, null);

        $this->assertSame(2, $this->countRows());
        $this->assertSame([2 => 18, 3 => null], $this->capacities->findAllAsMap()[$this->branchId]);
    }

    public function testNullAndZeroStayTwoDifferentAnswers(): void
    {
        $this->capacities->upsert($this->branchId, 1, null);
        $this->capacities->upsert($this->branchId, 2, 0);

        $this->assertNull($this->capacities->capacityFor($this->branchId, 1), 'an emptied box is « pas de limite »');
        $this->assertSame(0, $this->capacities->capacityFor($this->branchId, 2), 'a typed 0 is « branche fermée »');
    }

    /**
     * Seeding creates a missing slot once, and never rewrites one that
     * exists — not even one a chief cleared to NULL on purpose.
     */
    public function testInsertIfMissingSeedsOnceAndNeverOverrulesAStoredNull(): void
    {
        $this->assertTrue($this->capacities->insertIfMissing($this->branchId, 1, 15));
        $this->assertFalse($this->capacities->insertIfMissing($this->branchId, 1, 30));
        $this->assertSame(15, $this->capacities->capacityFor($this->branchId, 1));

        $this->capacities->upsert($this->branchId, 2, null);
        $this->assertFalse($this->capacities->insertIfMissing($this->branchId, 2, 15));
        $this->assertNull($this->capacities->capacityFor($this->branchId, 2));

        $this->assertSame(2, $this->countRows());
    }

    private function countRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM registration_slot_capacities')->fetchColumn();
    }
}
