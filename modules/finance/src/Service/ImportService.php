<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Bank statement import — full flow (IBAN verification via blind index,
 * parsing, per-line categorization via CategoryRuleEngine, deduplication,
 * balance checkpoint creation, statement bookkeeping) is "itération 3" of
 * the module spec. Stubbed for now: the constructor wiring is real (so
 * the composition root and tests can already exercise it), but import()
 * itself is not implemented yet.
 */
class ImportService
{
    public function __construct(
        private BankStatementParserFactory $parserFactory,
        private AccountRepository $accountRepository,
        private TransactionRepository $transactionRepository,
        private BalanceCheckpointRepository $checkpointRepository,
        private StatementImportRepository $statementImportRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private CategoryRuleEngine $categoryRuleEngine
    ) {
    }

    /**
     * @throws FinanceException always — not implemented yet (itération 3)
     */
    public function import(Account $account, string $bankCode, string $csvFilePath, string $originalFilename, ?float $balance, ?int $importedBy): void
    {
        throw new FinanceException("L'import de relevés bancaires n'est pas encore disponible.");
    }
}
