<?php

declare(strict_types=1);

namespace Tests\Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Parser\StatementLine;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRule;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\Transaction;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\CategoryRuleEngine;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class CategoryRuleEngineTest extends TestCase
{
    private TransactionRepository $transactionRepository;
    private CategoryRuleRepository $categoryRuleRepository;
    private CategoryRepository $categoryRepository;
    private CategoryRuleEngine $engine;
    private int $accountId;
    private int $fiscalYearId;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($pdo);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->transactionRepository = new TransactionRepository($pdo, $encryption);
        $this->categoryRuleRepository = new CategoryRuleRepository($pdo);
        $this->categoryRepository = new CategoryRepository($pdo);
        $this->engine = new CategoryRuleEngine($this->transactionRepository, $this->categoryRuleRepository);

        $stmt = $pdo->prepare("INSERT INTO finance_accounts (name, account_type) VALUES ('Compte', 'bank')");
        $stmt->execute();
        $this->accountId = (int) $pdo->lastInsertId();

        $this->fiscalYearId = FinanceTestHelper::createScoutYear($pdo, '2026-2027', '2026-09-01', '2027-08-31');
    }

    private function rule(?string $keywordPattern = null, ?string $counterpartyAccountPattern = null, ?string $amountRange = null): CategoryRule
    {
        return new CategoryRule(0, 0, 0, $keywordPattern, $counterpartyAccountPattern, $amountRange, true);
    }

    // --- countMatches() / keyword ---

    public function testKeywordMatchesCaseInsensitively(): void
    {
        $this->createTransaction('VIR Delhaize Bruxelles', -20.0);
        $this->createTransaction('VIR Colruyt', -15.0);

        $count = $this->engine->countMatches($this->rule(keywordPattern: 'delhaize'));

        $this->assertSame(1, $count);
    }

    public function testKeywordSupportsRealRegexSyntax(): void
    {
        $this->createTransaction('REF123456 paiement', -20.0);
        $this->createTransaction('REF12 paiement', -15.0);

        $count = $this->engine->countMatches($this->rule(keywordPattern: 'REF[0-9]{6}'));

        $this->assertSame(1, $count);
    }

    public function testKeywordAlternationMatchesEitherWord(): void
    {
        $this->createTransaction('Achat cafe', -5.0);
        $this->createTransaction('Achat boulangerie', -8.0);
        $this->createTransaction('Achat autre chose', -3.0);

        $count = $this->engine->countMatches($this->rule(keywordPattern: 'cafe|boulangerie'));

        $this->assertSame(2, $count);
    }

    public function testInvalidRegexNeverMatchesInsteadOfCrashing(): void
    {
        $this->createTransaction('Achat quelconque', -5.0);

        $count = $this->engine->countMatches($this->rule(keywordPattern: '(unclosed['));

        $this->assertSame(0, $count);
    }

    public function testRuleWithNoConditionsSetNeverMatchesAnything(): void
    {
        $this->createTransaction('Achat quelconque', -5.0);

        $this->assertSame(0, $this->engine->countMatches($this->rule()));
    }

    public function testKeywordMatchingIgnoresAccentsOnBothSides(): void
    {
        $this->createTransaction('Cotisation Fete d\'unite', -20.0);

        // Pattern written with accents, label without — and the reverse.
        $this->assertSame(1, $this->engine->countMatches($this->rule(keywordPattern: "f\u{00EA}te")));
    }

    public function testKeywordMatchingIgnoresAccentsInTheLabel(): void
    {
        $this->createTransaction("Cotisation F\u{00EA}te d'unit\u{00E9}", -20.0);

        $this->assertSame(1, $this->engine->countMatches($this->rule(keywordPattern: 'fete')));
    }

    public function testKeywordMatchingTrimsSurroundingWhitespaceInThePattern(): void
    {
        $this->createTransaction('VIR Delhaize', -20.0);

        $this->assertSame(1, $this->engine->countMatches($this->rule(keywordPattern: '  delhaize  ')));
    }

    public function testKeywordMatchingIsCaseInsensitive(): void
    {
        $this->createTransaction('VIR DELHAIZE BRUXELLES', -20.0);

        $this->assertSame(1, $this->engine->countMatches($this->rule(keywordPattern: 'delhaize')));
    }

    // --- applyToTransaction() ---

    public function testApplyToTransactionMatchesKeywordRule(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        $this->createTransaction('VIR Delhaize Bruxelles', -20.0);
        $transaction = $this->transactionRepository->findByAccountId($this->accountId)[0];

        $this->assertSame($categoryId, $this->engine->applyToTransaction($transaction));
    }

    public function testApplyToTransactionReturnsNullWhenNoRuleMatches(): void
    {
        $this->createTransaction('Achat divers', -20.0);
        $transaction = $this->transactionRepository->findByAccountId($this->accountId)[0];

        $this->assertNull($this->engine->applyToTransaction($transaction));
    }

    // --- amount range ---

    public function testAmountRangeGreaterThan(): void
    {
        $this->createTransaction('A', -150.0);
        $this->createTransaction('B', -50.0);

        $count = $this->engine->countMatches($this->rule(amountRange: '>100'));

        $this->assertSame(1, $count);
    }

    public function testAmountRangeInclusiveBounds(): void
    {
        $this->createTransaction('A', -10.0);
        $this->createTransaction('B', -50.0);
        $this->createTransaction('C', -100.0);
        $this->createTransaction('D', -150.0);

        $count = $this->engine->countMatches($this->rule(amountRange: '10-100'));

        $this->assertSame(3, $count);
    }

    public function testAmountRangeEvaluatesAbsoluteValue(): void
    {
        $this->createTransaction('Crédit', 200.0);

        $count = $this->engine->countMatches($this->rule(amountRange: '>100'));

        $this->assertSame(1, $count);
    }

    // --- counterparty account ---

    public function testCounterpartyAccountMatchesPersistedTransaction(): void
    {
        $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01', 'A', -20.0, null, null,
            Transaction::SOURCE_MANUAL, null, null, 'BE92001511757023'
        );
        $this->createTransaction('B', -10.0);

        $count = $this->engine->countMatches($this->rule(counterpartyAccountPattern: 'BE92001511757023'));

        $this->assertSame(1, $count);
    }

    // --- combined (AND) conditions ---

    public function testAllSetConditionsMustMatchTogether(): void
    {
        $this->createTransaction('VIR Delhaize', -20.0);
        $this->createTransaction('VIR Delhaize', -200.0);
        $this->createTransaction('VIR Colruyt', -20.0);

        $count = $this->engine->countMatches($this->rule(keywordPattern: 'delhaize', amountRange: '10-50'));

        $this->assertSame(1, $count);
    }

    private function createTransaction(string $label, float $amount): void
    {
        $this->transactionRepository->create(
            $this->accountId, $this->fiscalYearId, null, '2026-10-01', $label, $amount, null, null, Transaction::SOURCE_MANUAL, null
        );
    }

    private function line(string $label, float $amount, ?string $counterpartyAccount = null): StatementLine
    {
        return new StatementLine('ref-' . spl_object_id(new \stdClass()), new \DateTimeImmutable('2026-10-01'), $amount, $label, $counterpartyAccount);
    }

    public function testApplyReturnsNullWhenNoRuleMatches(): void
    {
        $this->assertNull($this->engine->apply($this->line('Achat divers', -20.0)));
    }

    public function testApplyMatchesKeywordRule(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);

        $matched = $this->engine->apply($this->line('VIR Delhaize Bruxelles', -20.0));

        $this->assertSame($categoryId, $matched);
    }

    public function testApplyMatchesCounterpartyAccountRule(): void
    {
        $categoryId = $this->categoryRepository->create('Loyer');
        $this->categoryRuleRepository->create($categoryId, 0, null, 'BE92001511757023', null);

        $matched = $this->engine->apply($this->line('Virement', -500.0, 'BE92001511757023'));

        $this->assertSame($categoryId, $matched);
    }

    public function testApplyCounterpartyAccountRulePartialMatch(): void
    {
        $categoryId = $this->categoryRepository->create('Loyer');
        $this->categoryRuleRepository->create($categoryId, 0, null, '1757023', null);

        $matched = $this->engine->apply($this->line('Virement', -500.0, 'BE92001511757023'));

        $this->assertSame($categoryId, $matched);
    }

    public function testApplyRequiresEveryConditionOnTheRuleToMatch(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, '10-50');

        $this->assertNull($this->engine->apply($this->line('VIR Delhaize', -200.0)));
        $this->assertSame($categoryId, $this->engine->apply($this->line('VIR Delhaize', -20.0)));
    }

    public function testApplyRespectsAscendingPriorityFirstMatchWins(): void
    {
        $lowPriorityCategory = $this->categoryRepository->create('Général');
        $highPriorityCategory = $this->categoryRepository->create('Alimentation');
        $this->categoryRuleRepository->create($lowPriorityCategory, 10, 'delhaize', null, null);
        $this->categoryRuleRepository->create($highPriorityCategory, 0, 'delhaize', null, null);

        $matched = $this->engine->apply($this->line('VIR Delhaize Bruxelles', -20.0));

        $this->assertSame($highPriorityCategory, $matched);
    }

    public function testApplyIgnoresInactiveRules(): void
    {
        $categoryId = $this->categoryRepository->create('Alimentation');
        $ruleId = $this->categoryRuleRepository->create($categoryId, 0, 'delhaize', null, null);
        $this->categoryRuleRepository->setActive($ruleId, false);

        $this->assertNull($this->engine->apply($this->line('VIR Delhaize Bruxelles', -20.0)));
    }

    // --- isValidKeywordPattern() ---

    public function testIsValidKeywordPatternAcceptsPlainWord(): void
    {
        $this->assertTrue(CategoryRuleEngine::isValidKeywordPattern('delhaize'));
    }

    public function testIsValidKeywordPatternAcceptsRealRegex(): void
    {
        $this->assertTrue(CategoryRuleEngine::isValidKeywordPattern('^VIR.*DUPONT$'));
        $this->assertTrue(CategoryRuleEngine::isValidKeywordPattern('REF[0-9]{6}'));
    }

    public function testIsValidKeywordPatternRejectsMalformedRegex(): void
    {
        $this->assertFalse(CategoryRuleEngine::isValidKeywordPattern('(unclosed['));
    }

    public function testIsValidKeywordPatternHandlesLiteralDelimiterCharacter(): void
    {
        $this->assertTrue(CategoryRuleEngine::isValidKeywordPattern('a~b'));
    }
    /**
     * @dataProvider validAmountRanges
     */
    #[DataProvider('validAmountRanges')]
    public function testIsValidAmountRangeAcceptsTheTwoDocumentedShapes(string $range): void
    {
        $this->assertTrue(CategoryRuleEngine::isValidAmountRange($range), $range);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function validAmountRanges(): array
    {
        return [
            'greater than' => ['>100'],
            'greater than spaced' => ['>  100'],
            'greater than decimal' => ['>10.5'],
            'greater than comma decimal' => ['>10,5'],
            'range' => ['10-50'],
            'range spaced' => ['10 - 50'],
            'range decimal' => ['10.5-50.25'],
            'equal bounds' => ['50-50'],
            'surrounding whitespace' => ['  10-50  '],
        ];
    }

    /**
     * @dataProvider invalidAmountRanges
     */
    #[DataProvider('invalidAmountRanges')]
    public function testIsValidAmountRangeRejectsEverythingElse(string $range): void
    {
        $this->assertFalse(CategoryRuleEngine::isValidAmountRange($range), $range);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidAmountRanges(): array
    {
        return [
            'bare number' => ['100'],
            'prose' => ['100 a 200'],
            'en dash' => ['100–200'],
            'gte' => ['>=100'],
            'lt' => ['<100'],
            'non numeric threshold' => ['>abc'],
            'inverted bounds' => ['200-100'],
            'open ended' => ['100-'],
            'open started' => ['-100'],
            'empty threshold' => ['>'],
            'empty' => [''],
            'negative threshold' => ['>-10'],
        ];
    }

    /**
     * Regression: matchesAmountRange() used a bare (float) cast, so a
     * threshold of "abc" became 0.0 and the rule matched every movement
     * with a non-zero amount. Such a rule can no longer be saved, but a row
     * predating the validation must match nothing rather than everything.
     */
    public function testAMalformedAmountRangeMatchesNothingRatherThanEverything(): void
    {
        $categoryId = $this->categoryRepository->create('Divers');
        $this->categoryRuleRepository->create($categoryId, 0, null, null, '>abc');

        $line = new StatementLine(
            bankReference: 'ref-1',
            transactionDate: new \DateTimeImmutable('2026-10-01'),
            amount: -250.0,
            label: 'Achat quelconque'
        );

        $this->assertNull($this->engine->apply($line));
    }

    public function testAnAmountRangeWithCommaDecimalsIsHonouredAtMatchTime(): void
    {
        $categoryId = $this->categoryRepository->create('Divers');
        $this->categoryRuleRepository->create($categoryId, 0, null, null, '>99,99');

        $above = new StatementLine(
            bankReference: 'ref-1',
            transactionDate: new \DateTimeImmutable('2026-10-01'),
            amount: -100.0,
            label: 'Achat'
        );
        $below = new StatementLine(
            bankReference: 'ref-2',
            transactionDate: new \DateTimeImmutable('2026-10-01'),
            amount: -99.0,
            label: 'Achat'
        );

        $this->assertSame($categoryId, $this->engine->apply($above));
        $this->assertNull($this->engine->apply($below));
    }

}
