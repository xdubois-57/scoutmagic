<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Reminder\ReminderKind;
use Modules\Rental\Repository\RentalAssetReminderRepository;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * What one asset changed about its reminders — and, mostly, what it did
 * not (§6.29).
 *
 * The table stores differences only, so the assertions that matter here
 * are the ones about **absence**: a line saying nothing leaves no row, and
 * a row deleted is a reminder back on the unit's default rather than one
 * frozen at whatever the default happened to be that day.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalAssetReminderRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalAssetReminderRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        RentalTestHelper::createTables($this->pdo);

        $this->repository = new RentalAssetReminderRepository($this->pdo);
    }

    private function rowCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rental_asset_reminders')->fetchColumn();
    }

    public function testAnAssetThatChangedNothingHasNoOverridesAtAll(): void
    {
        $this->assertSame([], $this->repository->findForAsset(1));
    }

    public function testItRecordsADelayAndReadsItBack(): void
    {
        $this->repository->save(1, ReminderKind::UNANSWERED_REQUEST, 10, true);

        $this->assertSame(
            ['unanswered_request' => ['days' => 10, 'active' => true]],
            $this->repository->findForAsset(1)
        );
    }

    /**
     * Zero is a delay like any other — « le jour même » — and the one most
     * likely to be lost by code that treats it as empty.
     */
    public function testZeroDaysIsStoredRatherThanTreatedAsNothing(): void
    {
        $this->repository->save(1, ReminderKind::CONTRACT_MISSING, 0, true);

        $this->assertSame(
            ['contract_missing' => ['days' => 0, 'active' => true]],
            $this->repository->findForAsset(1)
        );
    }

    /**
     * A row that means "no change" is a row whose existence stops telling
     * anybody anything, so it is deleted rather than written.
     */
    public function testALineThatSaysNothingLeavesNoRow(): void
    {
        $this->repository->save(1, ReminderKind::UNANSWERED_REQUEST, null, true);

        $this->assertSame(0, $this->rowCount());
        $this->assertSame([], $this->repository->findForAsset(1));
    }

    public function testClearingADelayRemovesTheRowRatherThanFreezingTheDefault(): void
    {
        $this->repository->save(1, ReminderKind::UNANSWERED_REQUEST, 10, true);
        $this->repository->save(1, ReminderKind::UNANSWERED_REQUEST, null, true);

        $this->assertSame(0, $this->rowCount());
    }

    /**
     * Switched off with no delay of its own: the row has to survive, or the
     * reminder comes straight back on.
     */
    public function testAReminderSwitchedOffKeepsItsRowWithoutADelay(): void
    {
        $this->repository->save(1, ReminderKind::ARRIVAL_INVENTORY, null, false);

        $this->assertSame(
            ['arrival_inventory' => ['days' => null, 'active' => false]],
            $this->repository->findForAsset(1)
        );
        $this->assertSame(1, $this->rowCount());
    }

    public function testSavingTheSameReminderTwiceUpdatesTheOneRow(): void
    {
        $this->repository->save(1, ReminderKind::BALANCE_MISSING, 3, true);
        $this->repository->save(1, ReminderKind::BALANCE_MISSING, 5, false);

        $this->assertSame(1, $this->rowCount());
        $this->assertSame(
            ['balance_missing' => ['days' => 5, 'active' => false]],
            $this->repository->findForAsset(1)
        );
    }

    public function testANegativeDelayIsStoredAsZeroRatherThanRefused(): void
    {
        $this->repository->save(1, ReminderKind::BALANCE_MISSING, -4, true);

        $this->assertSame(0, $this->repository->findForAsset(1)['balance_missing']['days']);
    }

    public function testOneAssetsOverridesNeverLeakIntoAnothers(): void
    {
        $this->repository->save(1, ReminderKind::UNANSWERED_REQUEST, 10, true);
        $this->repository->save(2, ReminderKind::UNANSWERED_REQUEST, 2, true);

        $this->assertSame(10, $this->repository->findForAsset(1)['unanswered_request']['days']);
        $this->assertSame(2, $this->repository->findForAsset(2)['unanswered_request']['days']);
    }

    /**
     * The daily pass walks every booking of every asset. Reading this one
     * asset at a time would be a query per hall, every morning, for a table
     * most units never write to at all.
     */
    public function testItReadsEveryAssetsOverridesInOneQuery(): void
    {
        $this->repository->save(1, ReminderKind::UNANSWERED_REQUEST, 10, true);
        $this->repository->save(1, ReminderKind::ARRIVAL_INVENTORY, null, false);
        $this->repository->save(2, ReminderKind::BALANCE_MISSING, 1, true);

        $all = $this->repository->findAll();

        $this->assertSame([1, 2], array_keys($all));
        $this->assertCount(2, $all[1]);
        $this->assertSame(1, $all[2]['balance_missing']['days']);
    }

    public function testFindAllIsEmptyWhenNobodyChangedAnything(): void
    {
        $this->assertSame([], $this->repository->findAll());
    }

    public function testClearRemovesOnlyTheReminderItNames(): void
    {
        $this->repository->save(1, ReminderKind::UNANSWERED_REQUEST, 10, true);
        $this->repository->save(1, ReminderKind::BALANCE_MISSING, 1, true);

        $this->repository->clear(1, ReminderKind::UNANSWERED_REQUEST);

        $this->assertSame(['balance_missing'], array_keys($this->repository->findForAsset(1)));
    }
}
