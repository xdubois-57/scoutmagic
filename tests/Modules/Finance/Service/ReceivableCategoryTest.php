<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Core\Security\Role;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\ExpectedReceivableService;
use Modules\Finance\Service\ReceivableAllocationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * Every receivable names a category, and a movement that pays one takes
 * it — ahead of the rules and the AI (issue #838).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ReceivableCategoryTest extends TestCase
{
    private \PDO $pdo;
    private ExpectedReceivableRepository $receivables;
    private TransactionRepository $transactions;
    private ReceivableAllocationService $allocations;
    private ExpectedReceivableService $service;
    private int $accountId;
    private int $fiscalYearId;
    private int $fees;
    private int $camps;
    private int $rentals;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->receivables = new ExpectedReceivableRepository($this->pdo, $encryption);
        $this->transactions = new TransactionRepository($this->pdo, $encryption);
        $this->allocations = FinanceTestHelper::allocationService($this->pdo, $encryption, $this->receivables);
        $this->service = FinanceTestHelper::receivableService($this->pdo, $encryption, $this->receivables);

        $this->pdo->exec("INSERT INTO finance_accounts (name, account_type, status) VALUES ('Compte Unité', 'bank', 'active')");
        $this->accountId = (int) $this->pdo->lastInsertId();
        $this->fiscalYearId = FinanceTestHelper::createScoutYear($this->pdo, '2025-2026', '2025-09-01', '2026-08-31');

        $this->fees = FinanceTestHelper::createCategory($this->pdo, 'Cotisations');
        $this->camps = FinanceTestHelper::createCategory($this->pdo, 'Camps');
        $this->rentals = FinanceTestHelper::createCategory($this->pdo, 'Locations');
    }

    // ── a receivable names its category ─────────────────────────────────

    public function testAReceivableKeepsTheCategoryItWasRaisedWith(): void
    {
        $id = $this->service->createReceivable('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, $this->camps);

        $this->assertSame($this->camps, $this->receivables->findById($id)?->categoryId);
    }

    public function testAnUnknownCategoryIsRefused(): void
    {
        $this->expectException(FinanceException::class);
        $this->expectExceptionMessageMatches('/catégorie/');

        $this->service->createReceivable('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, 999999);
    }

    public function testAnArchivedCategoryIsRefused(): void
    {
        $this->pdo->exec('UPDATE finance_categories SET is_active = 0 WHERE id = ' . $this->camps);

        $this->expectException(FinanceException::class);
        $this->service->createReceivable('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, $this->camps);
    }

    // ── a movement takes it ─────────────────────────────────────────────

    public function testTheAutomaticPassGivesAPaymentItsReceivablesCategory(): void
    {
        $this->service->createReceivable('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, $this->camps);
        $credit = $this->credit('Camp +++123/4567/89002+++', 45.00);

        $this->allocations->reconcileAccount($this->accountId);

        $this->assertCategory($this->camps, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);
    }

    /**
     * A payment that arrives before its receivable: the receivable's own
     * creation runs the matching, and the category follows.
     */
    public function testALateReceivableStillCategorisesThePaymentThatPrecededIt(): void
    {
        $credit = $this->credit('Caution +++123/4567/89002+++', 45.00);

        $this->service->createReceivable('rental', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, $this->rentals);

        $this->assertCategory($this->rentals, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);
    }

    public function testAttachingByHandGivesTheCategoryToo(): void
    {
        $id = $this->service->createReceivable('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, $this->camps);
        $credit = $this->credit('DUPONT camp', 45.00);

        $this->allocations->allocate($credit, $id, 4500, Role::INTENDANT, 7);

        $this->assertCategory($this->camps, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);
    }

    /**
     * A rule had placed it first — the receivable outranks a rule.
     */
    public function testAReceivableOutranksARule(): void
    {
        $id = $this->service->createReceivable('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, $this->camps);
        $credit = $this->credit('DUPONT cotisation', 45.00);
        $this->transactions->setCategoryId($credit, $this->fees);

        $this->allocations->allocate($credit, $id, 4500, Role::INTENDANT, 7);

        $this->assertCategory($this->camps, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);
    }

    public function testATreasurersOwnChoiceIsNeverOverwritten(): void
    {
        $id = $this->service->createReceivable('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, $this->camps);
        $credit = $this->credit('DUPONT', 45.00);
        $this->transactions->updateEditableFields($credit, $this->fees, null, $this->fiscalYearId);

        $this->allocations->allocate($credit, $id, 4500, Role::INTENDANT, 7);

        $this->assertCategory($this->fees, Transaction::CATEGORY_SOURCE_MANUAL, $credit);
    }

    public function testSeveralReceivablesGiveTheCategoryOfTheLargestShare(): void
    {
        $fee = $this->service->createReceivable('news', 1, $this->accountId, 3000, '+++123/4567/89002+++', null, $this->fees);
        $camp = $this->service->createReceivable('news', 2, $this->accountId, 9000, '+++123/4567/89103+++', null, $this->camps);
        $credit = $this->credit('Famille DUPONT', 120.00);

        $this->allocations->split($credit, [$fee => 3000, $camp => 9000], Role::INTENDANT, 7);

        $this->assertCategory($this->camps, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);
    }

    public function testChangingWhichReceivableTakesMostChangesTheCategory(): void
    {
        $fee = $this->service->createReceivable('news', 1, $this->accountId, 9000, '+++123/4567/89002+++', null, $this->fees);
        $camp = $this->service->createReceivable('news', 2, $this->accountId, 9000, '+++123/4567/89103+++', null, $this->camps);
        $credit = $this->credit('Famille DUPONT', 120.00);
        $this->allocations->split($credit, [$fee => 3000, $camp => 9000], Role::INTENDANT, 7);

        // Lowered first, so the movement never carries more than it has.
        $this->allocations->split($credit, [$camp => 3000, $fee => 9000], Role::INTENDANT, 7);

        $this->assertCategory($this->fees, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);
    }

    /**
     * A tie keeps the category the movement already has when it is one
     * of the tied ones…
     */
    public function testATieKeepsTheCurrentCategoryWhenItIsOneOfTheTiedOnes(): void
    {
        $fee = $this->service->createReceivable('news', 1, $this->accountId, 5000, '+++123/4567/89002+++', null, $this->fees);
        $camp = $this->service->createReceivable('news', 2, $this->accountId, 5000, '+++123/4567/89103+++', null, $this->camps);
        $credit = $this->credit('Famille DUPONT', 100.00);
        $this->allocations->allocate($credit, $camp, 5000, Role::INTENDANT, 7);
        $this->assertCategory($this->camps, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);

        $this->allocations->allocate($credit, $fee, 5000, Role::INTENDANT, 7);

        $this->assertCategory($this->camps, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);
    }

    /**
     * …and otherwise takes the tied receivable with the lowest id, so a
     * re-run never flips it.
     */
    public function testATieOtherwiseTakesTheLowestReceivableId(): void
    {
        $this->service->createReceivable('news', 1, $this->accountId, 5000, '+++123/4567/89002+++', null, $this->fees);
        $this->service->createReceivable('news', 2, $this->accountId, 5000, '+++123/4567/89103+++', null, $this->camps);
        // Both halves in one pass, on a movement a rule had placed
        // elsewhere: neither tied category is the current one.
        $credit = $this->credit('DUPONT +++123/4567/89103+++ +++123/4567/89002+++', 100.00);
        $this->transactions->setCategoryId($credit, $this->rentals);

        $this->allocations->reconcileAccount($this->accountId);

        $this->assertCategory($this->fees, Transaction::CATEGORY_SOURCE_RECEIVABLE, $credit);
    }

    /**
     * Detached from everything, a movement does not keep a category it
     * only had because of a receivable: the rules and the AI may look at
     * it again.
     */
    public function testAMovementNoLongerPayingAnythingLosesTheCategoryItTookFromIt(): void
    {
        $id = $this->service->createReceivable('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null, $this->camps);
        $credit = $this->credit('DUPONT', 45.00);
        $this->allocations->allocate($credit, $id, 4500, Role::INTENDANT, 7);

        $this->allocations->allocate($credit, $id, 0, Role::INTENDANT, 7);

        $this->assertCategory(null, null, $credit);
    }

    /**
     * The debit that pays an overpayment back is booked where the money
     * came from: it takes the receivable's category too.
     */
    public function testARefundDebitTakesTheReceivablesCategory(): void
    {
        $id = $this->service->createReceivable('news', 1, $this->accountId, 3825, '+++123/4567/89002+++', null, $this->camps);
        $this->credit('Virement arrondi +++123/4567/89002+++', 45.00);
        $this->allocations->reconcileAccount($this->accountId);
        $this->allocations->requestRefund($id, Role::INTENDANT, 7);
        $debit = $this->credit('Remboursement trop-perçu', -6.75);

        $this->allocations->allocateRefund($debit, $id, 675, Role::INTENDANT, 7);

        $this->assertCategory($this->camps, Transaction::CATEGORY_SOURCE_RECEIVABLE, $debit);
    }

    /**
     * A receivable raised before categories were mandatory has none to
     * give — the movement is left to the rules and the AI, as before.
     */
    public function testAReceivableWithoutACategoryLeavesTheMovementAlone(): void
    {
        $id = $this->receivables->create('news', 1, $this->accountId, 4500, '+++123/4567/89002+++', null);
        $credit = $this->credit('DUPONT', 45.00);
        $this->transactions->setCategoryId($credit, $this->fees);

        $this->allocations->allocate($credit, $id, 4500, Role::INTENDANT, 7);

        $this->assertCategory($this->fees, Transaction::CATEGORY_SOURCE_AUTO, $credit);
    }

    private function credit(string $label, float $amount): int
    {
        return $this->transactions->create(
            $this->accountId, $this->fiscalYearId, 'ref-' . uniqid(), '2026-02-18', $label, $amount,
            null, null, Transaction::SOURCE_IMPORT, null
        );
    }

    private function assertCategory(?int $categoryId, ?string $source, int $transactionId): void
    {
        $transaction = $this->transactions->findById($transactionId);
        $this->assertNotNull($transaction);
        $this->assertSame($categoryId, $transaction->categoryId, 'category');
        $this->assertSame($source, $transaction->categorySource, 'source');
    }
}
