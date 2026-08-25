<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Repository;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\Campaign;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CampaignRepositoryTest extends TestCase
{
    private \PDO $pdo;
    private CampaignRepository $repository;
    private CampaignRowRepository $rows;
    private int $accountId;
    private int $currentYearId;
    private int $previousYearId;
    private int $memberId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->repository = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)));

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $this->accountId = (int) $this->pdo->lastInsertId();

        $this->previousYearId = FinanceTestHelper::createScoutYear($this->pdo, '2024-2025', '2024-09-01', '2025-08-31');
        $this->currentYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31', true);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    public function testACreatedCampaignIsOpenAndNotYetNotified(): void
    {
        $id = $this->create('Cotisations');

        $campaign = $this->repository->findById($id);
        $this->assertNotNull($campaign);
        $this->assertSame('Cotisations', $campaign->label);
        $this->assertTrue($campaign->isOpen());
        $this->assertFalse($campaign->isNotified());
        $this->assertSame(['Nom', 'Section'], $campaign->mergeColumns);
        $this->assertSame('cotisations.xlsx', $campaign->sourceFilename);
    }

    public function testFindByIdReturnsNullForAnUnknownCampaign(): void
    {
        $this->assertNull($this->repository->findById(9999));
    }

    public function testCampaignsAreListedNewestFirstWithinAYear(): void
    {
        $first = $this->create('Première');
        $second = $this->create('Seconde');

        $found = array_map(static fn(Campaign $c): int => $c->id, $this->repository->findByScoutYear($this->currentYearId));

        $this->assertSame([$second, $first], $found);
    }

    public function testAYearSeesOnlyItsOwnCampaigns(): void
    {
        $this->create('Cette année');
        $this->create('Année passée', $this->previousYearId);

        $this->assertCount(1, $this->repository->findByScoutYear($this->currentYearId));
        $this->assertCount(2, $this->repository->findAll());
    }

    public function testTheYearPickerOffersOnlyYearsThatCarryACampaign(): void
    {
        $this->create('Cette année');

        $this->assertSame([$this->currentYearId], $this->repository->findDistinctScoutYearIds());
    }

    public function testClosingAndReopeningMoveTheStatusAndTheDate(): void
    {
        $id = $this->create('Cotisations');

        $this->repository->setStatus($id, Campaign::STATUS_CLOSED, '2026-06-30 12:00:00');
        $this->assertFalse($this->repository->findById($id)?->isOpen());
        $this->assertSame('2026-06-30 12:00:00', $this->repository->findById($id)?->closedAt);

        $this->repository->setStatus($id, Campaign::STATUS_OPEN, null);
        $this->assertTrue($this->repository->findById($id)?->isOpen());
        $this->assertNull($this->repository->findById($id)?->closedAt);
    }

    public function testMarkingNotifiedRecordsWhoAndWhen(): void
    {
        $id = $this->create('Cotisations');

        $this->repository->markNotified($id, '2026-02-20 09:30:00', 7);

        $campaign = $this->repository->findById($id);
        $this->assertNotNull($campaign);
        $this->assertTrue($campaign->isNotified());
        $this->assertSame('2026-02-20 09:30:00', $campaign->notifiedAt);
        $this->assertSame(7, $campaign->notifiedBy);
    }

    public function testForgettingTheSourceFileDropsItAndItsColumns(): void
    {
        $id = $this->create('Cotisations');

        $this->repository->forgetSourceFile($id);

        $campaign = $this->repository->findById($id);
        $this->assertNotNull($campaign);
        $this->assertNull($campaign->sourceFileId);
        $this->assertSame([], $campaign->mergeColumns);
    }

    // ── the rows ────────────────────────────────────────────────────────

    public function testARowCarriesItsMemberAmountAndSpreadsheetColumns(): void
    {
        $campaignId = $this->create('Cotisations');
        $rowId = $this->rows->create($campaignId, $this->memberId, 4500, 12, ['Nom' => 'Vandenbrande', 'Section' => 'Baladins 1']);

        $row = $this->rows->findById($rowId);
        $this->assertNotNull($row);
        $this->assertSame($this->memberId, $row->memberId);
        $this->assertSame(4500, $row->amountCents);
        $this->assertSame(12, $row->sourceLine);
        $this->assertSame('Vandenbrande', $row->mergeData['Nom']);
        $this->assertFalse($row->hasNote());
    }

    public function testARowWithoutSpreadsheetColumnsStoresNothingRatherThanAnEmptyBlob(): void
    {
        $campaignId = $this->create('Cotisations');
        $this->rows->create($campaignId, $this->memberId, 4500, 2, []);

        $this->assertNull($this->pdo->query('SELECT merge_data FROM finance_campaign_rows')->fetchColumn());
    }

    public function testRowsAreFoundByCampaignAndCounted(): void
    {
        $campaignId = $this->create('Cotisations');
        $this->rows->create($campaignId, $this->memberId, 4500, 2, []);

        $this->assertCount(1, $this->rows->findByCampaignId($campaignId));
        $this->assertSame(1, $this->rows->countByCampaignId($campaignId));
        $this->assertSame(0, $this->rows->countByCampaignId(9999));
    }

    public function testRowsAreFoundByMemberAcrossCampaigns(): void
    {
        $first = $this->create('Cotisations');
        $second = $this->create('Camp');
        $this->rows->create($first, $this->memberId, 4500, 2, []);
        $this->rows->create($second, $this->memberId, 12000, 2, []);

        $this->assertCount(2, $this->rows->findByMemberIds([$this->memberId]));
        $this->assertSame([], $this->rows->findByMemberIds([]));
    }

    public function testForgettingTheMergeDataLeavesTheAmountAlone(): void
    {
        $campaignId = $this->create('Cotisations');
        $rowId = $this->rows->create($campaignId, $this->memberId, 4500, 2, ['Nom' => 'Vandenbrande']);

        $this->rows->forgetMergeDataForCampaign($campaignId);

        $row = $this->rows->findById($rowId);
        $this->assertNotNull($row);
        $this->assertSame([], $row->mergeData);
        $this->assertSame(4500, $row->amountCents);
    }

    private function create(string $label, ?int $scoutYearId = null): int
    {
        return $this->repository->create(
            $label,
            $scoutYearId ?? $this->currentYearId,
            $this->accountId,
            null,
            'cotisations.xlsx',
            ['Nom', 'Section'],
            7
        );
    }
}
