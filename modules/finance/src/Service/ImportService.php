<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Finance\Service;

use Core\Config\ScoutYearService;
use Core\Security\EncryptionService;
use Core\Service\DateInput;
use Modules\Finance\Api\FinanceException;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Parser\ClosingBalance;
use Modules\Finance\Parser\StatementLine;
use Modules\Finance\Repository\Account;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\BalanceCheckpoint;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionRepository;

/**
 * Bank statement import — one uploaded file, split across the site's
 * accounts by the IBANs the file itself carries (issue #511, IT-01). There
 * is no account to choose: each line joins the account whose IBAN it was
 * booked on, looked up via blind index, so a wrong destination is not
 * caught any more — it cannot happen.
 *
 * An IBAN no site account carries is set aside and named in the result,
 * never the occasion to create an account; so is one whose account the
 * uploader may not use, or that is not active. Everything else is
 * all-or-nothing: the per-line loop over EVERY account runs inside a single
 * DB transaction (same precedent as Core\Import\DeskImportService::import()),
 * because a file half-spread across three accounts could not be untangled,
 * and every check that can refuse the file — a date no scout year covers, a
 * missing opening balance — runs before the first write.
 *
 * The format is detected from the file itself unless the uploader chose
 * one, which the screen only offers after detection failed.
 *
 * Per account: auto-categorization per line, deduplication, a balance
 * checkpoint when a balance is known, and one statement import bookkeeping
 * row, the rows of one file sharing its upload id. The balance comes from
 * the file whenever the format states it (CODA) — a file covering three
 * accounts could never ask a form for three — and is typed by hand only
 * for a format that does not (the BNP CSV), on an account's first import.
 */
class ImportService
{
    public function __construct(
        private \PDO $pdo,
        private EncryptionService $encryption,
        private BankStatementParserFactory $parserFactory,
        private AccountRepository $accountRepository,
        private TransactionRepository $transactionRepository,
        private BalanceCheckpointRepository $checkpointRepository,
        private StatementImportRepository $statementImportRepository,
        private FiscalYearRepository $fiscalYearRepository,
        private CategoryRuleEngine $categoryRuleEngine,
        private BalanceService $balanceService,
        private ReceiptMatchingService $receiptMatchingService,
        private BulkCategorizationService $bulkCategorizationService,
        private ReceivableAllocationService $allocationService
    ) {
    }

    /**
     * $mayImportInto answers "may this upload write into that account" —
     * the caller's own visibility rule (the controller passes the finance
     * account visibility of the signed-in role). An account it refuses is
     * skipped, like an unknown IBAN.
     *
     * $bankCode null detects the format from the file.
     *
     * $balance is a balance typed by hand. It is only ever needed, and only
     * accepted, for a format that states no balance, on the first import of
     * the single account the file covers.
     *
     * @param \Closure(Account): bool $mayImportInto
     * @throws StatementFormatNotRecognized when detection finds no format
     * @throws FinanceException on a malformed file, a date no scout year
     *                           covers, a missing opening balance, or a
     *                           typed balance the file does not need
     */
    public function import(
        ?string $bankCode,
        string $filePath,
        string $originalFilename,
        ?float $balance,
        ?int $importedBy,
        \Closure $mayImportInto
    ): ImportResult {
        try {
            $bankCode ??= $this->parserFactory->detect($filePath) ?? throw new StatementFormatNotRecognized();
            $parser = $this->parserFactory->create($bankCode);

            $fileIbans = $parser->extractAccountIbans($filePath);
            $lines = $parser->parse($filePath);
            $fileBalances = $parser->closingBalances($filePath);

            /** @var array<string, StatementLine[]> $linesByIban */
            $linesByIban = array_fill_keys($fileIbans, []);
            foreach ($lines as $line) {
                if (!array_key_exists($line->accountIban, $linesByIban)) {
                    // A parser contract violation, not a user error: the
                    // line claims an account the file never announced.
                    throw new FinanceException(
                        'Le fichier de relevé est incohérent : une ligne appartient à un compte non déclaré.'
                    );
                }
                $linesByIban[$line->accountIban][] = $line;
            }

            /** @var array<string, Account> $targets */
            $targets = [];
            $skipped = [];
            foreach ($linesByIban as $iban => $accountLines) {
                [$account, $reason] = $this->resolveAccount((string) $iban, $mayImportInto);
                if ($account === null) {
                    $skipped[] = new SkippedAccount((string) $iban, $reason, count($accountLines));
                    continue;
                }
                $targets[(string) $iban] = $account;
            }

            $this->assertEveryDateHasAScoutYear($targets, $linesByIban);

            /** @var array<string, bool> $firstImports */
            $firstImports = [];
            foreach ($targets as $iban => $account) {
                $firstImports[$iban] = !$this->checkpointRepository->hasAnyForAccount($account->id);
            }

            $balances = $this->balancesFor($targets, $firstImports, $fileBalances, $balance);

            $uploadId = bin2hex(random_bytes(16));
            $written = [];

            $this->pdo->beginTransaction();

            try {
                foreach ($targets as $iban => $account) {
                    $written[] = $this->importAccountLines(
                        $account,
                        $linesByIban[$iban],
                        $firstImports[$iban],
                        $balances[$iban] ?? null,
                        $bankCode,
                        $originalFilename,
                        $uploadId,
                        $importedBy
                    );
                }

                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }

            $outcomes = [];
            $linesNew = 0;
            foreach ($written as [$account, $statementImportId, $balanceDiscrepancy]) {
                $statementImport = $this->statementImportRepository->findById($statementImportId);
                \assert($statementImport !== null);
                $outcomes[] = new AccountImportOutcome($account, $statementImport, $balanceDiscrepancy);
                $linesNew += $statementImport->linesNew;

                // The freshly-imported credits are matched against the
                // receivables waiting for them, and the allocations written
                // down. Deliberately after the commit and outside it: a
                // failure here must not roll back a statement that imported
                // correctly, and the nightly Task\ReconcileReceivablesHandler
                // picks up whatever a failure left undone. Idempotent, so a
                // re-imported statement — whose lines were all skipped one
                // level down — writes nothing.
                $this->allocationService->reconcileAccount($account->id);

                // Newly-imported movements may complete a match for a
                // receipt that was uploaded before this statement existed —
                // re-attempt matching for every still-pending receipt on
                // this account now that they do.
                $this->receiptMatchingService->matchPendingReceiptsForAccount($account->id);
            }

            // Regular rules already ran inline per line; this catches
            // anything they didn't (in particular the AI rule, deliberately
            // never run inline — see Service\BulkCategorizationService's own
            // doc comment) the same way the config page's "Exécuter les
            // règles" button does, without making this request wait for it.
            // Skipped when nothing new was actually inserted (e.g. a
            // re-imported, fully-duplicate statement) — and silently
            // skipped, not surfaced as an error, when a run is already in
            // progress; whichever run happens next will still pick these up.
            if ($linesNew > 0) {
                $this->bulkCategorizationService->scheduleBackgroundRun();
            }

            return new ImportResult($outcomes, $skipped);
        } finally {
            // The uploaded file is a temporary file holding bank data —
            // never kept around beyond the request that processes it,
            // success or failure.
            if (is_file($filePath)) {
                @unlink($filePath);
            }
        }
    }

    /**
     * Writes one account's lines, its checkpoint and its bookkeeping row.
     * Runs inside import()'s transaction.
     *
     * @param StatementLine[] $lines
     * @return array{0: Account, 1: int, 2: ?float} the account, its statement
     *         import id and its balance discrepancy
     */
    private function importAccountLines(
        Account $account,
        array $lines,
        bool $isFirstImport,
        ?ImportBalance $balance,
        string $bankCode,
        string $originalFilename,
        string $uploadId,
        ?int $importedBy
    ): array {
        $linesNew = 0;
        $linesDuplicate = 0;
        $latestDate = null;

        foreach ($lines as $line) {
            $dateStr = $line->transactionDate->format('Y-m-d');
            if ($latestDate === null || $dateStr > $latestDate) {
                $latestDate = $dateStr;
            }

            // Checked for every line before the transaction opened.
            $fiscalYear = $this->fiscalYearRepository->findForDate($dateStr);
            \assert($fiscalYear !== null);

            $inserted = $this->transactionRepository->insertOrSkip(
                $account->id,
                $fiscalYear->id,
                $line->bankReference,
                $dateStr,
                $line->label,
                $line->amount,
                $this->categoryRuleEngine->apply($line),
                $line->counterpartyName,
                $line->counterpartyAccount,
                $line->extraDetails,
                $line->structuredCommunication
            );

            if ($inserted) {
                $linesNew++;
            } else {
                $linesDuplicate++;
            }
        }

        // A balance the file states holds on its own date; a typed one, on
        // the day of the statement's last line.
        $checkpointDate = $balance !== null && $balance->date !== null
            ? $balance->date->format('Y-m-d')
            : ($latestDate ?? (new \DateTimeImmutable('today'))->format('Y-m-d'));

        $balanceDiscrepancy = null;
        if ($balance !== null) {
            if (!$isFirstImport) {
                // Compared as-of the new checkpoint's own date, using only
                // what was known before it — the ledger's own opinion of the
                // balance on that day.
                $calculatedBalance = $this->balanceService->getBalanceAt(
                    $account,
                    DateInput::requireFromStorage($checkpointDate, 'import checkpoint date')
                );
                if ($calculatedBalance !== null && abs($calculatedBalance - $balance->amount) > 0.01) {
                    $balanceDiscrepancy = round($balance->amount - $calculatedBalance, 2);
                }
            }

            // The same file imported twice states the same balance on the
            // same date: one checkpoint says it, a second would only be
            // noise in the account's history.
            $existing = $this->checkpointRepository->findClosestBefore($account->id, $checkpointDate);
            $alreadyKnown = $existing !== null && $existing->checkpointDate === $checkpointDate
                && abs($existing->balance - $balance->amount) < 0.005;
            if (!$alreadyKnown) {
                $this->checkpointRepository->create(
                    $account->id,
                    $checkpointDate,
                    $balance->amount,
                    BalanceCheckpoint::SOURCE_IMPORT
                );
            }
        }

        $statementImportId = $this->statementImportRepository->create(
            $account->id,
            $bankCode,
            $originalFilename,
            count($lines),
            $linesNew,
            $linesDuplicate,
            $importedBy,
            uploadId: $uploadId
        );

        return [$account, $statementImportId, $balanceDiscrepancy];
    }

    /**
     * The balance each target account's checkpoint records, keyed like
     * $targets.
     *
     * A format stating balances gives every account its own — and a typed
     * one is refused rather than silently ignored. A format that does not
     * takes a typed balance on the first import of the single account the
     * file covers, and nowhere else: later imports recompute the balance
     * from the movements.
     *
     * @param array<string, Account> $targets
     * @param array<string, bool> $firstImports
     * @param array<string, ClosingBalance> $fileBalances
     * @return array<string, ImportBalance>
     * @throws FinanceException
     */
    private function balancesFor(array $targets, array $firstImports, array $fileBalances, ?float $typed): array
    {
        if ($fileBalances !== []) {
            if ($typed !== null) {
                throw new FinanceException(
                    'Ce fichier indique lui-même le solde de chaque compte : laissez le champ « Solde » vide.'
                );
            }

            $balances = [];
            foreach (array_keys($targets) as $iban) {
                $closing = $fileBalances[$iban] ?? null;
                if ($closing !== null) {
                    $balances[$iban] = new ImportBalance($closing->amount, $closing->date);
                }
            }

            return $balances;
        }

        if ($typed !== null && count($targets) > 1) {
            throw new FinanceException(
                'Ce fichier couvre plusieurs comptes : un solde saisi à la main ne peut pas être attribué.'
                . ' Laissez le champ vide.'
            );
        }

        $balances = [];
        foreach ($targets as $iban => $account) {
            if (!$firstImports[$iban]) {
                if ($typed !== null) {
                    throw new FinanceException(
                        "Le compte « {$account->name} » a déjà un solde de référence :"
                        . ' les suivants se recalculent depuis les mouvements. Laissez le champ « Solde » vide.'
                    );
                }
                continue;
            }
            if ($typed === null) {
                throw new FinanceException(
                    "Le solde de départ est obligatoire pour le premier import du compte « {$account->name} »."
                );
            }
            $balances[$iban] = new ImportBalance($typed, null);
        }

        return $balances;
    }

    /**
     * The site account a file's IBAN sends its lines to, or why there is
     * none.
     *
     * The blind index is computed on the IbanNormalizer form, which is the
     * form every parser answers in (BankStatementParserInterface). Nothing
     * makes an IBAN unique across accounts, so the ACTIVE one wins — an
     * archived account and its replacement may share one — and two active
     * ones are ambiguous rather than picked by chance. Visibility is judged
     * before status, so an account the uploader may not use is reported as
     * exactly that and nothing more.
     *
     * @param \Closure(Account): bool $mayImportInto
     * @return array{0: Account, 1: null}|array{0: null, 1: string}
     */
    private function resolveAccount(string $iban, \Closure $mayImportInto): array
    {
        $candidates = $this->accountRepository->findAllByIbanBlindIndex(
            $this->encryption->blindIndex($iban, 'finance_iban')
        );
        if ($candidates === []) {
            return [null, SkippedAccount::REASON_UNKNOWN];
        }

        $active = array_values(array_filter(
            $candidates,
            static fn (Account $account): bool => $account->status === Account::STATUS_ACTIVE
        ));
        if (count($active) > 1) {
            return [null, SkippedAccount::REASON_AMBIGUOUS];
        }
        if (count($active) === 1) {
            return $mayImportInto($active[0]) ? [$active[0], null] : [null, SkippedAccount::REASON_FORBIDDEN];
        }

        foreach ($candidates as $candidate) {
            if ($mayImportInto($candidate)) {
                return [null, SkippedAccount::REASON_INACTIVE];
            }
        }

        return [null, SkippedAccount::REASON_FORBIDDEN];
    }

    /**
     * The fiscal year IS the scout year, and it is never created from here:
     * scout years arrive through the four watched steps of the « Année
     * scoute » page, and a one-byte shift in a file produces plausible, wrong
     * dates that would invent a year for the whole site. So a single date
     * outside every scout year refuses the whole file, before any write,
     * naming the dates found and the year that is missing.
     *
     * @param array<string, Account> $targets
     * @param array<string, StatementLine[]> $linesByIban
     * @throws FinanceException
     */
    private function assertEveryDateHasAScoutYear(array $targets, array $linesByIban): void
    {
        /** @var array<string, array<string, true>> $uncoveredByYear label => Y-m-d set */
        $uncoveredByYear = [];
        foreach (array_keys($targets) as $iban) {
            foreach ($linesByIban[$iban] as $line) {
                $date = $line->transactionDate->format('Y-m-d');
                if ($this->fiscalYearRepository->findForDate($date) === null) {
                    $uncoveredByYear[ScoutYearService::labelForDate($line->transactionDate)][$date] = true;
                }
            }
        }

        if ($uncoveredByYear === []) {
            return;
        }

        $parts = [];
        foreach ($uncoveredByYear as $label => $dates) {
            $dates = array_keys($dates);
            sort($dates);
            $parts[] = self::describeDates($dates) . " (année scoute {$label})";
        }

        throw new FinanceException(
            'Aucune année scoute du site ne couvre ' . implode(', ', $parts) . '.'
            . ' Aucun mouvement n\'a été importé.'
            . ' L\'année manquante se prépare depuis la page « Année scoute » ;'
            . ' si ces dates vous semblent fausses, vérifiez le fichier.'
        );
    }

    /**
     * @param non-empty-list<string> $dates sorted Y-m-d
     */
    private static function describeDates(array $dates): string
    {
        $first = DateInput::requireFromStorage($dates[0], 'statement line date')->format('d/m/Y');
        if (count($dates) === 1) {
            return "la date du {$first}";
        }
        $last = DateInput::requireFromStorage($dates[count($dates) - 1], 'statement line date')->format('d/m/Y');

        return count($dates) . " dates, du {$first} au {$last}";
    }
}
