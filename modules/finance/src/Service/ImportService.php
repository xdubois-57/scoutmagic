<?php

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Security\EncryptionService;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Bank statement import — the full flow described in the module spec's
 * "itération 3": IBAN verification via blind index (no forcing option —
 * a mismatch always aborts before any line is imported), per-line
 * auto-categorization, deduplication, balance checkpoint bookkeeping,
 * and statement import bookkeeping. The whole per-line loop runs inside
 * a single DB transaction (same precedent as
 * Core\Import\DeskImportService::import()) so a mid-loop failure (e.g. a
 * transaction date outside every configured fiscal year) never leaves a
 * partially-imported statement behind.
 */
class ImportService
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private BankStatementParserFactory $parserFactory,
        private TransactionRepository $transactionRepository,
        private BalanceCheckpointRepository $checkpointRepository,
        private StatementImportRepository $statementImportRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private CategoryRuleEngine $categoryRuleEngine,
        private BalanceService $balanceService,
        private ReceiptMatchingService $receiptMatchingService
    ) {
    }

    /**
     * @throws FinanceException on an IBAN mismatch, a missing mandatory
     *                           starting balance, an uncovered fiscal
     *                           year, or a malformed file
     */
    public function import(
        Account $account,
        string $bankCode,
        string $csvFilePath,
        string $originalFilename,
        ?float $balance,
        ?int $importedBy
    ): ImportResult {
        try {
            if ($account->iban === null || $account->iban === '') {
                throw new FinanceException("Le compte sélectionné n'a pas d'IBAN configuré.");
            }

            $parser = $this->parserFactory->create($bankCode);

            $sourceIban = $parser->extractSourceIban($csvFilePath);
            $this->verifyIban($sourceIban, $account->iban);

            $lines = $parser->parse($csvFilePath);

            $isFirstImport = !$this->checkpointRepository->hasAnyForAccount($account->id);
            if ($isFirstImport && $balance === null) {
                throw new FinanceException('Le solde de départ est obligatoire pour le premier import de ce compte.');
            }

            $this->pdo->beginTransaction();

            try {
                $linesNew = 0;
                $linesDuplicate = 0;
                $latestDate = null;

                foreach ($lines as $line) {
                    $dateStr = $line->transactionDate->format('Y-m-d');
                    if ($latestDate === null || $dateStr > $latestDate) {
                        $latestDate = $dateStr;
                    }

                    $fiscalYear = $this->fiscalYearRepository->findForDate($dateStr);
                    if ($fiscalYear === null) {
                        throw new FinanceException(
                            "Aucun exercice comptable ne couvre la date {$line->transactionDate->format('d/m/Y')}."
                            . " Créez l'exercice correspondant avant de réessayer."
                        );
                    }

                    $categoryId = $this->categoryRuleEngine->apply($line);

                    $inserted = $this->transactionRepository->insertOrSkip(
                        $account->id,
                        $fiscalYear->id,
                        $line->bankReference,
                        $dateStr,
                        $line->label,
                        $line->amount,
                        $categoryId
                    );

                    if ($inserted) {
                        $linesNew++;
                    } else {
                        $linesDuplicate++;
                    }
                }

                $checkpointDate = $latestDate ?? (new \DateTimeImmutable('today'))->format('Y-m-d');

                $balanceDiscrepancy = null;
                if ($balance !== null) {
                    if (!$isFirstImport) {
                        // Compared as-of the new checkpoint's own date, using
                        // only what was known before it — the ledger's own
                        // opinion of the balance on that day.
                        $calculatedBalance = $this->balanceService->getBalanceAt($account, new \DateTimeImmutable($checkpointDate));
                        if ($calculatedBalance !== null && abs($calculatedBalance - $balance) > 0.01) {
                            $balanceDiscrepancy = round($balance - $calculatedBalance, 2);
                        }
                    }

                    $this->checkpointRepository->create($account->id, $checkpointDate, $balance, BalanceCheckpoint::SOURCE_IMPORT);
                }

                $statementImportId = $this->statementImportRepository->create(
                    $account->id,
                    $bankCode,
                    $originalFilename,
                    count($lines),
                    $linesNew,
                    $linesDuplicate,
                    $importedBy
                );

                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }

            $statementImport = $this->statementImportRepository->findById($statementImportId);
            \assert($statementImport !== null);

            // Newly-imported movements may complete a match for a
            // receipt that was uploaded before this statement existed —
            // re-attempt matching for every still-pending receipt on
            // this account now that they do.
            $this->receiptMatchingService->matchPendingReceiptsForAccount($account->id);

            return new ImportResult($statementImport, $balanceDiscrepancy);
        } finally {
            // The uploaded CSV is a temporary file holding bank data —
            // never kept around beyond the request that processes it,
            // success or failure (IBAN mismatch, malformed file, etc.).
            if (is_file($csvFilePath)) {
                @unlink($csvFilePath);
            }
        }
    }

    /**
     * @throws FinanceException on a mismatch — there is no way to force
     *                           the import through regardless (module
     *                           spec: "Aucune option de forçage n'existe")
     */
    private function verifyIban(string $sourceIban, string $accountIban): void
    {
        if ($this->encryption->blindIndex($sourceIban) === $this->encryption->blindIndex($accountIban)) {
            return;
        }

        $fileTail = substr($sourceIban, -4);
        $accountTail = substr($accountIban, -4);

        throw new FinanceException(
            "L'IBAN du fichier importé (se terminant par {$fileTail}) ne correspond pas à l'IBAN du compte sélectionné"
            . " (se terminant par {$accountTail}). Aucun mouvement n'a été importé."
        );
    }
}
