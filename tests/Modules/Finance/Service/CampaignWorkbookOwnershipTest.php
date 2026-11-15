<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\FileRepository;
use Modules\Finance\File\FinanceAccountOwnershipChecker;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Service\CampaignWorkbookOwnership;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The one-time stamp of the campaign workbooks stored before issue #1111:
 * it runs once, and the flag is what makes every later request free.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CampaignWorkbookOwnershipTest extends TestCase
{
    private \PDO $pdo;
    private FileRepository $files;
    private SettingService $settings;
    private int $workbook;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->files = new FileRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Unité', 'bank', 'active')");
        $accountId = (int) $this->pdo->lastInsertId();
        $yearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);
        $this->workbook = $this->files->create(
            'finance/campaigns/a.enc', 'a.xlsx', 'application/octet-stream', 1, 'intendant', 'finance', null
        );
        $this->pdo->prepare(
            'INSERT INTO finance_campaigns (label, scout_year_id, account_id, source_file_id) VALUES (?, ?, ?, ?)'
        )->execute(['Cotisations', $yearId, $accountId, $this->workbook]);
    }

    public function testTheFirstRunStampsTheWorkbooksAndRaisesTheFlag(): void
    {
        $this->assertSame(1, $this->ownership()->ensure());

        $this->assertSame(FinanceAccountOwnershipChecker::OWNER_TYPE, $this->files->findById($this->workbook)?->ownerType);
        $this->assertSame('1', $this->settings->get(CampaignWorkbookOwnership::DONE_FLAG, 'finance', '0'));
    }

    /**
     * Once the flag is up, nothing is asked of the database: a workbook
     * that somehow lost its owner afterwards is not this backfill's to
     * find again.
     */
    public function testOnceTheFlagIsUpItDoesNothing(): void
    {
        $this->ownership()->ensure();
        $this->pdo->prepare('UPDATE files SET owner_type = NULL, owner_id = NULL WHERE id = ?')->execute([$this->workbook]);

        $this->assertSame(0, (new CampaignWorkbookOwnership(
            new CampaignRepository($this->pdo),
            new SettingService(new SettingRepository($this->pdo))
        ))->ensure());
        $this->assertNull($this->files->findById($this->workbook)?->ownerType);
    }

    private function ownership(): CampaignWorkbookOwnership
    {
        return new CampaignWorkbookOwnership(new CampaignRepository($this->pdo), $this->settings);
    }
}
