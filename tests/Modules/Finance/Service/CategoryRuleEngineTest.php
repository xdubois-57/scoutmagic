<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Repository\CategoryRule;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\CategoryRuleEngine;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
class CategoryRuleEngineTest extends TestCase
{
    private TransactionRepository $transactionRepository;
    private CategoryRuleEngine $engine;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactionRepository = new TransactionRepository($pdo, $encryption);
        $this->engine = new CategoryRuleEngine($this->transactionRepository);

        $stmt = $pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $pdo->lastInsertId();

        $stmt = $pdo->prepare("INSERT INTO finance_fiscal_years (label, start_date, end_date) VALUES ('2026-2027', '2026-09-01', '2027-08-31')");
        $stmt->execute();
        $this->fiscalYearId = (int) $pdo->lastInsertId();
    }

    private function rule(string $conditionType, string $conditionValue): CategoryRule
    {
        return new CategoryRule(0, 0, 0, $conditionType, $conditionValue, true);
    }

    public function testKeywordMatchesCaseInsensitively(): void
    {
        $this->createTransaction('VIR Delhaize Bruxelles', -20.0);
        $this->createTransaction('VIR Colruyt', -15.0);

        $count = $this->engine->countMatches($this->rule(CategoryRule::CONDITION_KEYWORD, 'delhaize'));

        $this->assertSame(1, $count);
    }

    public function testKeywordWithEmptyValueMatchesNothing(): void
    {
        $this->createTransaction('VIR Delhaize', -20.0);

        $this->assertSame(0, $this->engine->countMatches($this->rule(CategoryRule::CONDITION_KEYWORD, '')));
    }

    public function testAmountRangeGreaterThan(): void
    {
        $this->createTransaction('A', -150.0);
        $this->createTransaction('B', -50.0);

        $count = $this->engine->countMatches($this->rule(CategoryRule::CONDITION_AMOUNT_RANGE, '>100'));

        $this->assertSame(1, $count);
    }

    public function testAmountRangeInclusiveBounds(): void
    {
        $this->createTransaction('A', -10.0);
        $this->createTransaction('B', -50.0);
        $this->createTransaction('C', -100.0);
        $this->createTransaction('D', -150.0);

        $count = $this->engine->countMatches($this->rule(CategoryRule::CONDITION_AMOUNT_RANGE, '10-100'));

        $this->assertSame(3, $count);
    }

    public function testAmountRangeEvaluatesAbsoluteValue(): void
    {
        $this->createTransaction('Crédit', 200.0);

        $count = $this->engine->countMatches($this->rule(CategoryRule::CONDITION_AMOUNT_RANGE, '>100'));

        $this->assertSame(1, $count);
    }

    public function testCounterpartyAccountAlwaysReturnsZero(): void
    {
        $this->createTransaction('A', -20.0);

        $count = $this->engine->countMatches($this->rule(CategoryRule::CONDITION_COUNTERPARTY_ACCOUNT, 'BE92001511757023'));

        $this->assertSame(0, $count);
    }

    private function createTransaction(string $label, float $amount): void
    {
        $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01', $label, $amount, null, null, Transaction::SOURCE_MANUAL, null
        );
    }
}
