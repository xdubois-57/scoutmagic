<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\ReceivableAllocation;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\ReceivableSearchService;
use Modules\Finance\Service\TreasurerScope;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * « Quelle créance ? », answered by typing a name.
 *
 * The field this replaces asked for the receivable's **id**, and said as
 * much: « L'identifiant de la créance, repris dans l'export de la
 * campagne ». Which is a page telling its reader to leave, open a
 * spreadsheet, find a line and come back with an integer.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReceivableSearchServiceTest extends TestCase
{
    private \PDO $pdo;
    private ExpectedReceivableRepository $receivables;
    private ReceivableAllocationRepository $allocations;
    private TransactionRepository $transactions;
    private int $accountId;
    private int $otherAccountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $accounts = new AccountRepository($this->pdo, $encryption);
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->allocations = new ReceivableAllocationRepository($this->pdo);
        $this->transactions = new TransactionRepository($this->pdo, $encryption);

        $this->accountId = $accounts->create('Compte unité', Account::TYPE_BANK, null, null, null, 'intendant');
        $this->otherAccountId = $accounts->create('Caisse louveteaux', Account::TYPE_CASH, null, null, null, 'intendant');
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    /**
     * @param array<int, string> $names
     */
    private function service(array $names = []): ReceivableSearchService
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        return new ReceivableSearchService(
            $this->receivables,
            $this->allocations,
            new AccountRepository($this->pdo, $encryption),
            new AccountVisibility(TreasurerScope::systemCaller()),
            static fn (array $memberIds): array => array_intersect_key($names, array_flip($memberIds))
        );
    }

    private function receivable(
        int $accountId,
        int $amountCents,
        string $communication,
        ?string $label = null,
        ?int $memberId = null
    ): int {
        return $this->receivables->create(
            'fees',
            random_int(1, 100000),
            $accountId,
            $amountCents,
            $communication,
            $label,
            $memberId
        );
    }

    public function testAReceivableIsFoundByTheDebtorsName(): void
    {
        $this->receivable($this->accountId, 4500, '+++001/0001/00001+++', null, 7);
        $this->receivable($this->accountId, 3000, '+++001/0001/00002+++', null, 8);

        $rows = $this->service([7 => 'Léa Dupont', 8 => 'Marc Petit'])->search($this->accountId, Role::ADMIN, 'dupont');

        $this->assertCount(1, $rows);
        $this->assertSame('Léa Dupont', $rows[0]['label']);
        $this->assertSame(4500, $rows[0]['remaining_cents']);
    }

    /** Accents folded: « Léa » is found by typing « lea ». */
    public function testAccentsDoNotHaveToBeTyped(): void
    {
        $this->receivable($this->accountId, 4500, '+++001/0001/00001+++', null, 7);

        $this->assertCount(1, $this->service([7 => 'Léa Dupont'])->search($this->accountId, Role::ADMIN, 'lea'));
    }

    /**
     * A treasurer who DID look the id up in the export must not be worse
     * off than before for having done so.
     */
    public function testTheIdStillWorksAsASearchTerm(): void
    {
        $id = $this->receivable($this->accountId, 4500, '+++001/0001/00001+++', 'Location salle');

        $rows = $this->service()->search($this->accountId, Role::ADMIN, (string) $id);

        $this->assertCount(1, $rows);
        $this->assertSame($id, $rows[0]['id']);
    }

    public function testTheCommunicationIsSearchableToo(): void
    {
        $this->receivable($this->accountId, 4500, '+++001/0001/00042+++', 'Location salle');

        $this->assertCount(1, $this->service()->search($this->accountId, Role::ADMIN, '00042'));
    }

    /**
     * An imputation never joins two accounts — « rien ne s'impute à
     * distance » — so offering a receivable from elsewhere would be
     * offering a choice that can only be refused.
     */
    public function testAnotherAccountsReceivablesAreNeverOffered(): void
    {
        $this->receivable($this->otherAccountId, 4500, '+++001/0001/00001+++', 'Location salle');

        $this->assertSame([], $this->service()->search($this->accountId, Role::ADMIN, 'location'));
    }

    public function testAReceivableAlreadyPaidInFullIsNotOffered(): void
    {
        $id = $this->receivable($this->accountId, 4500, '+++001/0001/00001+++', 'Location salle');
        $transactionId = $this->transactions->create(
            $this->accountId, $this->fiscalYearId, 'ref-1', '2026-10-01', 'Virement', 45.00, null, null,
            Transaction::SOURCE_IMPORT, null
        );
        $this->allocations->create($transactionId, $id, 4500, ReceivableAllocation::SOURCE_MANUAL, null);

        $this->assertSame([], $this->service()->search($this->accountId, Role::ADMIN, 'location'));
    }

    public function testOnlyWhatIsLeftIsReported(): void
    {
        $id = $this->receivable($this->accountId, 4500, '+++001/0001/00001+++', 'Location salle');
        $transactionId = $this->transactions->create(
            $this->accountId, $this->fiscalYearId, 'ref-1', '2026-10-01', 'Acompte', 20.00, null, null,
            Transaction::SOURCE_IMPORT, null
        );
        $this->allocations->create($transactionId, $id, 2000, ReceivableAllocation::SOURCE_MANUAL, null);

        $rows = $this->service()->search($this->accountId, Role::ADMIN, 'location');

        $this->assertCount(1, $rows);
        $this->assertSame(2500, $rows[0]['remaining_cents']);
    }

    /**
     * With no search text and a credit to attach, the receivable owing
     * exactly that comes first — usually the whole answer, and the same
     * idea as the movement picker's `near_date`.
     */
    public function testWithNoSearchTextTheExactAmountComesFirst(): void
    {
        $this->receivable($this->accountId, 1000, '+++001/0001/00001+++', 'Une autre');
        $exact = $this->receivable($this->accountId, 4500, '+++001/0001/00002+++', 'Celle-ci');
        $this->receivable($this->accountId, 9000, '+++001/0001/00003+++', 'Une troisième');

        $rows = $this->service()->search($this->accountId, Role::ADMIN, '', 4500);

        $this->assertSame($exact, $rows[0]['id']);
    }

    /**
     * A receivable with neither a label nor a resolvable name still has
     * to be pickable: its communication is what a treasurer reads off a
     * bank statement anyway.
     */
    public function testAReceivableWithNoNameFallsBackToItsCommunication(): void
    {
        $this->receivable($this->accountId, 4500, '+++001/0001/00001+++');

        $rows = $this->service()->search($this->accountId, Role::ADMIN, '00001');

        $this->assertSame('+++001/0001/00001+++', $rows[0]['label']);
    }

    /**
     * Visibility is checked on the ACCOUNT, exactly as every other
     * finance route checks it — a picker that answered for an account
     * whose page refuses the reader would be a way around that page.
     */
    public function testAnAccountTheRoleMayNotSeeAnswersNothing(): void
    {
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $reserved = (new AccountRepository($this->pdo, $encryption))
            ->create('Compte réservé', Account::TYPE_BANK, null, null, null, 'admin');
        $this->receivable($reserved, 4500, '+++001/0001/00001+++', 'Location salle');

        $this->assertSame([], $this->service()->search($reserved, Role::INTENDANT, 'location'));
    }
}
