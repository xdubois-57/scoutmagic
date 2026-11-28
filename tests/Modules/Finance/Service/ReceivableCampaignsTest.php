<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Repository\ExpectedReceivable;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Service\CampaignService;
use Modules\Finance\Service\ReceivableCampaigns;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The one rule the three family surfaces share (issue #1436): a closed
 * campaign stops asking, and nothing else is filtered.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReceivableCampaignsTest extends TestCase
{
    private \PDO $pdo;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $rows;
    private ExpectedReceivableRepository $receivables;
    private ReceivableCampaigns $service;
    private int $accountId;
    private int $scoutYearId;
    private int $memberId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->service = new ReceivableCampaigns($this->rows, $this->campaigns);

        $this->accountId = (new AccountRepository($this->pdo, $encryption))
            ->create('Compte Unité', Account::TYPE_BANK, null, 'BE71096123456769', 'Unité', 'intendant');
        $this->scoutYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    public function testEachCampaignReceivableIsMappedToItsCampaignAndOtherSourcesToNothing(): void
    {
        $first = $this->campaignReceivable('Cotisations', '+++100/0000/00197+++');
        $second = $this->campaignReceivable('Camp', '+++200/0000/00097+++');
        $rental = $this->receivable('rental', 1, '+++300/0000/00094+++');

        $map = $this->service->campaignsOf([$first, $second, $rental]);

        $this->assertSame([$first->id, $second->id], array_keys($map));
        $this->assertSame('Cotisations', $map[$first->id]->label);
        $this->assertSame('Camp', $map[$second->id]->label);
    }

    public function testOnlyAClosedCampaignsReceivablesAreDroppedAndTheOrderIsKept(): void
    {
        $open = $this->campaignReceivable('Cotisations', '+++100/0000/00197+++');
        $closed = $this->campaignReceivable('Camp', '+++200/0000/00097+++', closed: true);
        $rental = $this->receivable('rental', 1, '+++300/0000/00094+++');
        // A finance receivable whose row is gone has no campaign to be
        // closed: it is kept rather than silently hidden.
        $orphan = $this->receivable(CampaignService::SOURCE_MODULE, 9999, '+++400/0000/00088+++');

        $kept = $this->service->withoutClosedCampaigns([$rental, $open, $closed, $orphan]);

        $this->assertSame(
            [$rental->id, $open->id, $orphan->id],
            array_map(static fn(ExpectedReceivable $r): int => $r->id, $kept)
        );
    }

    public function testNothingToLookUpIsAnEmptyAnswer(): void
    {
        $this->assertSame([], $this->service->campaignsOf([]));
        $this->assertSame([], $this->service->withoutClosedCampaigns([]));
    }

    private function campaignReceivable(string $label, string $communication, bool $closed = false): ExpectedReceivable
    {
        $campaignId = $this->campaigns->create($label, $this->scoutYearId, $this->accountId, null, 'f.xlsx', [], 7);
        if ($closed) {
            $this->campaigns->setStatus($campaignId, Campaign::STATUS_CLOSED, '2026-03-01 10:00:00');
        }
        $rowId = $this->rows->create($campaignId, $this->memberId, 4500, 1, []);

        return $this->receivable(CampaignService::SOURCE_MODULE, $rowId, $communication);
    }

    private function receivable(string $sourceModule, int $referenceId, string $communication): ExpectedReceivable
    {
        $id = $this->receivables->create($sourceModule, $referenceId, $this->accountId, 4500, $communication, null, $this->memberId);
        $receivable = $this->receivables->findById($id);
        self::assertNotNull($receivable);

        return $receivable;
    }
}
