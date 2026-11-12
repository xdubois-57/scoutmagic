<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Modules\Finance\File\FinanceAccountOwnershipChecker;
use Modules\Finance\Repository\CampaignRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\UsesProductionEngine;

/**
 * CampaignRepository::backfillSourceFileOwnership() (issue #1111) is an
 * UPDATE of `files` whose value and filter are subqueries on
 * `finance_campaigns` — the shape AGENTS.md § Database asks to see on the
 * engine production runs, with its real foreign keys.
 */
#[Group('database')]
final class CampaignRepositoryOnMysqlTest extends TestCase
{
    use UsesProductionEngine;

    private \PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = $this->productionEngine();
    }

    public function testTheWorkbookBackfillRunsOnTheProductionEngine(): void
    {
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2026-2027', '2026-09-01', '2027-08-31')");
        $yearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte unité', 'bank')");
        $accountId = (int) $this->pdo->lastInsertId();
        $workbook = $this->file('a.xlsx.enc');
        $unrelated = $this->file('b.pdf.enc');
        $this->pdo->prepare(
            'INSERT INTO finance_campaigns (label, scout_year_id, account_id, source_file_id) VALUES (?, ?, ?, ?)'
        )->execute(['Cotisations', $yearId, $accountId, $workbook]);

        $repository = new CampaignRepository($this->pdo);
        $this->assertSame(1, $repository->backfillSourceFileOwnership(FinanceAccountOwnershipChecker::OWNER_TYPE));

        $owner = $this->pdo->query("SELECT owner_type, owner_id FROM files WHERE id = {$workbook}")->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame(FinanceAccountOwnershipChecker::OWNER_TYPE, $owner['owner_type']);
        $this->assertSame($accountId, (int) $owner['owner_id']);
        $this->assertFalse((bool) $this->pdo->query("SELECT owner_type FROM files WHERE id = {$unrelated}")->fetchColumn());
        $this->assertSame(0, $repository->backfillSourceFileOwnership(FinanceAccountOwnershipChecker::OWNER_TYPE));
    }

    private function file(string $name): int
    {
        $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, module_id)
             VALUES (?, ?, 'application/octet-stream', 100, 'intendant', 'finance')"
        )->execute(['finance/campaigns/' . $name, $name]);

        return (int) $this->pdo->lastInsertId();
    }
}
