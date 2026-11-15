<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Pricing\PricingSettings;
use Modules\Rental\Repository\RentalPricingRepository;
use PHPUnit\Framework\TestCase;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * The price grid is replaced in one transaction (`replaceGrid()`): a save
 * that fails midway keeps the old grid whole, never half of each.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalPricingRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalPricingRepository $repository;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = new \PDO('sqlite::memory:');
        $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        // As on MySQL: a cell for a season that no longer exists is refused.
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        RentalTestHelper::createTables($this->pdo);

        $this->pdo->exec("INSERT INTO rental_assets (asset_type, name, slug) VALUES ('Local', 'Local', 'local')");
        $this->assetId = (int) $this->pdo->lastInsertId();
        $this->repository = new RentalPricingRepository($this->pdo);
    }

    /**
     * A season deleted in another tab between the form's read and this
     * save: its cell is refused by the foreign key, and the grid stays the
     * one that was there — the cell for every season included.
     */
    public function testACellForASeasonDeletedMeanwhileKeepsTheOldGridWhole(): void
    {
        $this->repository->replaceGrid($this->assetId, [PricingSettings::gridKey(null, null) => 12000]);
        $season = $this->repository->createPeriod($this->assetId, 'Été', '2027-07-01', '2027-08-31', true);
        $this->repository->deletePeriod($this->assetId, $season);

        try {
            $this->repository->replaceGrid($this->assetId, [
                PricingSettings::gridKey(null, null) => 15000,
                PricingSettings::gridKey($season, null) => 18000,
            ]);
            $this->fail('a cell for a deleted season must be refused');
        } catch (\PDOException $e) {
            $this->assertSame('23000', (string) $e->getCode());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(
            [PricingSettings::gridKey(null, null) => 12000],
            $this->repository->findGrid($this->assetId),
            'the old price stays: neither deleted nor half replaced'
        );
    }
}
