<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Rental\Repository;

use Modules\Rental\Repository\RentalBlock;
use Modules\Rental\Repository\RentalBlockRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * A gesture on the calendar lands whole or not at all (`replace()`): a
 * period the database refuses halfway takes back the deletions and the
 * periods written before it.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalBlockRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private RentalBlockRepository $repository;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->repository = new RentalBlockRepository($this->pdo);

        $this->pdo->exec("INSERT INTO rental_assets (asset_type, name, slug) VALUES ('Local', 'Local', 'local')");
        $this->assetId = (int) $this->pdo->lastInsertId();
    }

    public function testAPeriodTheDatabaseRefusesLeavesTheCalendarAsItWas(): void
    {
        $before = $this->repository->create($this->assetId, '2027-07-01', '2027-07-10', 'Chantier toiture', 7);
        $this->pdo->exec(
            "CREATE TRIGGER refuse_august BEFORE INSERT ON rental_blocks
             WHEN NEW.start_date >= '2027-08-01' BEGIN SELECT RAISE(ABORT, 'période refusée'); END"
        );

        try {
            $this->repository->replace($this->assetId, [$before], [
                ['start' => '2027-07-01', 'end' => '2027-07-04', 'reason' => 'Chantier toiture'],
                ['start' => '2027-08-01', 'end' => '2027-08-04', 'reason' => null],
            ], 7);
            $this->fail('a refused period must reach the caller');
        } catch (\PDOException $e) {
            $this->assertStringContainsString('période refusée', $e->getMessage());
        }

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(
            [[$before, '2027-07-01', '2027-07-10']],
            array_map(
                static fn(RentalBlock $block): array => [$block->id, $block->startDate, $block->endDate],
                $this->repository->findAllForAsset($this->assetId)
            ),
            'the block removed and the period written before the refusal are both taken back'
        );
    }
}
