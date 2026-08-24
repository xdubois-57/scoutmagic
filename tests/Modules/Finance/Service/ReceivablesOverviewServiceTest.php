<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Repository\AccountRepository;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
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
        $this->service = new ReceivablesOverviewService(
            $repository,
            $this->receivableService,
            new AccountRepository($this->pdo, $encryption),
            new \Modules\Finance\Service\AccountVisibility(
                // No badge assigned in these fixtures, so the treasurer
                // rule is off and the module behaves exactly as it did
                // before it existed — which is what these tests assert.
                \Modules\Finance\Service\TreasurerScope::systemCaller()
            )
        );

        $stmt = $this->pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $this->pdo->lastInsertId();
    }

    public function testBuildOverviewReturnsEmptyArrayWithNoReceivables(): void
    {
        $this->assertSame([], $this->service->buildOverview(Role::INTENDANT));
    }

    public function testBuildOverviewGroupsBySourceModuleThenBySourceReferenceId(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 1, $this->accountId, 3000, '+++200/0000/00068+++', 'Bob');
        $this->receivableService->createReceivable('news', 2, $this->accountId, 1000, '+++300/0000/00002+++', 'Carla');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertCount(1, $overview);
        $this->assertSame('news', $overview[0]['source_module']);
        $this->assertSame('Formulaires', $overview[0]['source_label']);
        $this->assertCount(2, $overview[0]['instances']);

        $instance1 = current(array_filter($overview[0]['instances'], fn($i) => $i['source_reference_id'] === 1));
        $this->assertCount(2, $instance1['receivables']);
        $this->assertSame(5500, $instance1['amount_due']);
    }

    /**
     * The page is role_min: intendant, but WHICH accounts' receivables it
     * shows is a per-account decision (role_min_view) — the same boundary
     * every other finance page applies through
     * FinanceService::getAccountsForUser(). This page used to skip it
     * entirely and render every row in the table, handing an intendant the
     * label, payer communication and reconciled amounts of accounts they
     * cannot otherwise see anywhere in the module.
     */
    public function testReceivablesOnAnAccountAboveTheViewersRoleAreNotShown(): void
    {
        $adminOnlyAccountId = $this->createAccount('Compte direction', 'admin');

        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');
        $this->receivableService->createReceivable('news', 2, $adminOnlyAccountId, 9900, '+++900/0000/00090+++', 'Secret Donor');

        $asIntendant = $this->service->buildOverview(Role::INTENDANT);

        $this->assertCount(1, $asIntendant);
        $this->assertCount(1, $asIntendant[0]['instances'], 'the admin-only account\'s instance must not appear');
        $this->assertSame(2500, $asIntendant[0]['amount_due'], 'its amount must not be folded into the totals either');

        $communications = array_column($asIntendant[0]['instances'][0]['receivables'], 'communication');
        $this->assertSame(['+++100/0000/00034+++'], $communications);

        $serialized = json_encode($asIntendant);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('Secret Donor', $serialized);
        $this->assertStringNotContainsString('+++900/0000/00090+++', $serialized);
    }

    public function testAViewerWhoClearsTheAccountsFloorSeesItsReceivables(): void
    {
        $adminOnlyAccountId = $this->createAccount('Compte direction', 'admin');
        $this->receivableService->createReceivable('news', 2, $adminOnlyAccountId, 9900, '+++900/0000/00090+++', 'Donor');

        $asAdmin = $this->service->buildOverview(Role::ADMIN);

        $this->assertCount(1, $asAdmin);
        $this->assertSame(9900, $asAdmin[0]['amount_due']);
    }

    /**
     * A source module whose every receivable is invisible must drop out
     * altogether rather than render as an empty accordion — an empty
     * "Formulaires" section still tells an intendant that receivables they
     * may not see exist.
     */
    public function testASourceModuleWithNoVisibleReceivablesDisappearsEntirely(): void
    {
        $adminOnlyAccountId = $this->createAccount('Compte direction', 'admin');
        $this->receivableService->createReceivable('news', 1, $adminOnlyAccountId, 500, '+++111/0000/00011+++', 'Donor');

        $this->assertSame([], $this->service->buildOverview(Role::INTENDANT));
    }

    /**
     * Visibility keys on role_min_view alone, never on the account's status:
     * a receivable booked against an account that has since been archived
     * must still reconcile for someone allowed to see that account, or money
     * silently vanishes from the totals.
     */
    public function testAnArchivedAccountsReceivablesStillReconcile(): void
    {
        $archivedId = $this->createAccount('Ancien compte', 'intendant', 'archived');
        $this->receivableService->createReceivable('news', 1, $archivedId, 750, '+++222/0000/00022+++', 'Alice');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertCount(1, $overview);
        $this->assertSame(750, $overview[0]['amount_due']);
    }

    private function createAccount(string $name, string $roleMinView, string $status = 'active'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO finance_accounts (name, account_type, role_min_view, status) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$name, 'bank', $roleMinView, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    public function testBuildOverviewComputesTotalsAtEveryLevel(): void
    {
        $this->receivableService->createReceivable('news', 1, $this->accountId, 2500, '+++100/0000/00034+++', 'Alice');

        $overview = $this->service->buildOverview(Role::INTENDANT);

        $this->assertSame(2500, $overview[0]['amount_due']);
        $this->assertSame(0, $overview[0]['amount_received']);
        $this->assertSame(2500, $overview[0]['instances'][0]['amount_due']);
    }
}
