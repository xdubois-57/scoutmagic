<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Repository\CampaignRepository;
use Modules\Finance\Repository\CampaignRowRepository;
use Modules\Finance\Task\PurgeCampaignFilesHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class PurgeCampaignFilesHandlerTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CampaignRepository $campaigns;
    private CampaignRowRepository $rows;
    private string $storagePath;
    private int $accountId;
    private int $memberId;
    /** @var array<string, int> 'expired'|'previous'|'current' => scout_years.id */
    private array $years = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storagePath = sys_get_temp_dir() . '/campaign_purge_test_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath, 0777, true);

        $this->campaigns = new CampaignRepository($this->pdo);
        $this->rows = new CampaignRowRepository($this->pdo, $this->encryption);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();

        // Three seasons, built around whichever one is current TODAY:
        // Core\Config\ScoutYearService decides that from the real date,
        // so hard-coded labels would make this test start failing on a
        // 1 September. With the default retention of two scout years the
        // current one and the previous one are kept and the oldest falls
        // out — the very same window a Desk import gets.
        $currentStart = (int) substr(\Core\Config\ScoutYearService::labelForDate(new \DateTimeImmutable()), 0, 4);
        foreach ([-2 => 'expired', -1 => 'previous', 0 => 'current'] as $offset => $key) {
            $start = $currentStart + $offset;
            $this->years[$key] = FinanceTestHelper::createScoutYear(
                $this->pdo,
                $start . '-' . ($start + 1),
                $start . '-09-01',
                ($start + 1) . '-08-31',
                $offset === 0
            );
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/*/*') ?: [] as $file) {
            @unlink($file);
        }
    }

    public function testACampaignOfAnExpiredSeasonLosesItsFileAndItsColumns(): void
    {
        [$campaignId, $fileId, $rowId] = $this->campaignWithFile('expired');

        (new PurgeCampaignFilesHandler())->handle([], $this->taskContext());

        $campaign = $this->campaigns->findById($campaignId);
        $this->assertNotNull($campaign);
        $this->assertNull($campaign->sourceFileId);
        $this->assertSame([], $campaign->mergeColumns);
        $this->assertNull((new FileRepository($this->pdo))->findById($fileId));
        $this->assertSame([], $this->rows->findById($rowId)?->mergeData);
    }

    /**
     * The campaign, its amounts and its receivables are the unit's
     * financial history — they were never temporary, and once the file is
     * gone they name nobody but by an internal identifier.
     */
    public function testTheFinancialHistoryItselfSurvivesThePurge(): void
    {
        [$campaignId, , $rowId] = $this->campaignWithFile('expired');

        (new PurgeCampaignFilesHandler())->handle([], $this->taskContext());

        $this->assertNotNull($this->campaigns->findById($campaignId));
        $this->assertSame(4500, $this->rows->findById($rowId)?->amountCents);
        $this->assertSame($this->memberId, $this->rows->findById($rowId)?->memberId);
    }

    public function testACampaignInsideTheWindowIsLeftAlone(): void
    {
        [$campaignId, $fileId] = $this->campaignWithFile('current');

        (new PurgeCampaignFilesHandler())->handle([], $this->taskContext());

        $this->assertSame($fileId, $this->campaigns->findById($campaignId)?->sourceFileId);
    }

    public function testASecondRunOverAnAlreadyForgottenCampaignDoesNothing(): void
    {
        $this->campaignWithFile('expired');

        (new PurgeCampaignFilesHandler())->handle([], $this->taskContext());
        (new PurgeCampaignFilesHandler())->handle([], $this->taskContext());

        // One journal entry, not two: nothing was left to forget.
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM event_log WHERE event_type = 'campaign_files_purged'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    public function testItReschedulesItselfEvenWhenThereIsNothingToPurge(): void
    {
        (new PurgeCampaignFilesHandler())->handle([], $this->taskContext());

        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'purge_campaign_files' AND status = 'pending'"
        )->fetchColumn();
        $this->assertSame(1, $count);
    }

    /**
     * @return array{int, int, int} campaign id, file id, row id
     */
    private function campaignWithFile(string $yearKey): array
    {
        $storage = new EncryptedFileStorageService(new FileRepository($this->pdo), $this->encryption, $this->storagePath);
        $fileId = $storage->store(
            'des octets de tableur',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'cotisations.xlsx',
            'finance/campaigns',
            'intendant',
            'finance',
            null
        );

        $campaignId = $this->campaigns->create(
            'Cotisations ' . $yearKey,
            $this->years[$yearKey],
            $this->accountId,
            $fileId,
            'cotisations.xlsx',
            ['Nom', 'Section'],
            7
        );
        $rowId = $this->rows->create($campaignId, $this->memberId, 4500, 2, ['Nom' => 'Vandenbrande']);

        return [$campaignId, $fileId, $rowId];
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createMock(UserAccountRepository::class),
            $this->storagePath
        );
    }
}
