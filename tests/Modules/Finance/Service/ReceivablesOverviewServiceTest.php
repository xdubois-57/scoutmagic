<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivablesOverviewService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class ReceivablesOverviewServiceTest extends TestCase
{
    private \PDO $pdo;
    private ReceivablesOverviewService $service;
    private ExpectedReceivableService $receivableService;
    private int $accountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $repository = new ExpectedReceivableRepository($this->pdo, $encryption);
        $transactionRepository = new TransactionRepository($this->pdo, $encryption);
        $this->receivableService = new ExpectedReceivableService($repository, $transactionRepository);
        $this->service = new ReceivablesOverviewService($repository, $this->receivableService);

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    public function testBuildOverviewReturnsEmptyArrayWithNoReceivables(): void
    {
        $this->assertSame([], $this->service->buildOverview());
    }

    public function testBuildOverviewGroupsBySourceModuleThenBySourceReferenceId(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 1, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');
        $this->receivableService->createReceivable('news', 2, $this->accountId, 1000, '+++300/0000/00002+++', 'Carla');

        $overview = $this->service->buildOverview();

        $this->assertCount(1, $overview);
        $this->assertSame('news', $overview[0]['source_module']);
        $this->assertSame('Formulaires', $overview[0]['source_label']);
        $this->assertCount(2, $overview[0]['instances']);

        $instance1 = current(array_filter($overview[0]['instances'], fn($i) => $i['source_reference_id'] === 1));
        $this->assertCount(2, $instance1['receivables']);
        $this->assertSame(5500, $instance1['amount_due']);
    }

    public function testBuildOverviewComputesTotalsAtEveryLevel(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');

        $overview = $this->service->buildOverview();

        $this->assertSame(2500, $overview[0]['amount_due']);
        $this->assertSame(0, $overview[0]['amount_received']);
        $this->assertSame(2500, $overview[0]['instances'][0]['amount_due']);
    }
}
