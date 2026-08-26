<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\EncryptionService;
use Modules\Finance\Repository\Account;
use Modules\Finance\Parser\BankStatementParserFactory;
use Modules\Finance\Repository\AccountRepository;
use Modules\Finance\Repository\AiCategorySuggestionRepository;
use Modules\Finance\Repository\AttachmentRepository;
use Modules\Finance\Repository\BalanceCheckpointRepository;
use Modules\Finance\Repository\CategoryRepository;
use Modules\Finance\Repository\CategoryRuleRepository;
use Modules\Finance\Repository\ExpectedReceivableRepository;
use Modules\Finance\Repository\FiscalYearRepository;
use Modules\Finance\Repository\ReceivableAllocationRepository;
use Modules\Finance\Repository\StatementImportRepository;
use Modules\Finance\Repository\TransactionAttachmentRepository;
use Modules\Finance\Repository\TransactionRepository;
use Modules\Finance\Service\AccountTransferCategoryService;
use Modules\Finance\Service\AccountVisibility;
use Modules\Finance\Service\AiCategorizationService;
use Modules\Finance\Service\BalanceService;
use Modules\Finance\Service\BulkCategorizationService;
use Modules\Finance\Service\CategoryRuleEngine;
use Modules\Finance\Service\FinanceService;
use Modules\Finance\Service\ImportService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\ReceiptMatchingService;
use Modules\Finance\Service\TreasurerScope;

/**
 * Creates the unit's two bank accounts and imports the six statements through
 * the real finance pipeline.
 *
 * Nothing here writes to `finance_transactions` directly. Every line goes
 * through Modules\Finance\Service\ImportService, which is what makes the
 * result trustworthy: the IBAN is verified against the account by blind index,
 * the exercise is resolved out of `scout_years`, each line is auto-categorised
 * by the real rule engine, duplicates are recognised by their bank reference,
 * and the balance checkpoints follow.
 *
 * The AI collaborators are wired with a null LlmConnector, the same
 * degradation the composition root applies when the `llm_connector` module is
 * disabled (ARCHITECTURE.md §7.5): categorisation falls back to rules only.
 */
final class FinanceSeeder
{
    /** @var array<string, int> handle => finance_accounts.id */
    private array $accountIds = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly EncryptionService $encryption,
        private readonly string $datasetRoot,
        private readonly ?int $importedBy = null,
    ) {
    }

    /**
     * Create the accounts declared in BankBlueprint, then import every
     * statement in chronological order.
     *
     * The order matters twice over: the first import of an account is the only
     * one allowed to set its starting balance (ImportService refuses a first
     * import without one), and the overlap lines of a later file are only
     * recognised as duplicates because the earlier file went in first.
     *
     * @return array{accounts: int, imported: int, duplicates: int}
     */
    public function seed(): array
    {
        $this->ensureAccounts();

        $imported = 0;
        $duplicates = 0;
        $importService = $this->buildImportService();
        $accountRepository = new AccountRepository($this->pdo, $this->encryption);

        foreach (UnitBlueprint::YEARS as $index => $year) {
            foreach (array_keys(BankBlueprint::ACCOUNTS) as $handle) {
                $account = $accountRepository->findById($this->accountIds[$handle]);
                if ($account === null) {
                    throw new \RuntimeException("Le compte {$handle} vient d'être créé et reste introuvable.");
                }

                $path = $this->datasetRoot . '/' . BankBlueprint::fileFor($year, $handle);
                if (!is_file($path)) {
                    throw new \RuntimeException("Relevé introuvable : {$path}");
                }

                // A copy, for the same reason the Desk import gets one: a
                // pipeline that consumes its input file must never be handed a
                // committed fixture.
                $copy = (string) tempnam(sys_get_temp_dir(), 'refdataset-bank');
                copy($path, $copy);

                try {
                    $result = $importService->import(
                        $account,
                        BankBlueprint::BANK_CODE,
                        $copy,
                        basename($path),
                        // Only the first file of an account carries the
                        // starting balance; passing one again would be a
                        // second checkpoint for the same account.
                        $index === 0 ? BankBlueprint::ACCOUNTS[$handle]['opening'] : null,
                        $this->importedBy,
                    );
                } finally {
                    if (is_file($copy)) {
                        @unlink($copy);
                    }
                }

                $imported += $result->statementImport->linesNew;
                $duplicates += $result->statementImport->linesDuplicate;
            }
        }

        return ['accounts' => count($this->accountIds), 'imported' => $imported, 'duplicates' => $duplicates];
    }

    /**
     * The unit's own accounts, with their IBANs — which is what lets
     * ImportService::verifyIban() accept the matching statement and refuse
     * every other one.
     *
     * Created through FinanceService::createAccount(), never through the
     * repository: the service is what normalises the IBAN (IbanNormalizer —
     * uppercase, spaces stripped) before it is encrypted and blind-indexed,
     * and the blind index is exactly what verifyIban() compares against the
     * one BnpParser::extractSourceIban() derives from the file. Writing the
     * spaced form straight to the repository produced two different blind
     * indexes for the same account and an import that failed with "IBAN
     * mismatch" naming two IBANs ending in the same four digits.
     *
     * It also syncs the account's own "Virement <compte>" system rule, which
     * is what lets the transfer between the unit's two accounts be recognised
     * as an internal move rather than as income.
     *
     * FinanceService::ensureDefaultAccountsForSections() has already created a
     * default account per section by the time this runs; these two are the
     * unit-level accounts the statements belong to, created on top.
     */
    private function ensureAccounts(): void
    {
        $repository = new AccountRepository($this->pdo, $this->encryption);
        $service = $this->buildFinanceService();

        foreach (BankBlueprint::ACCOUNTS as $handle => $account) {
            $existing = $repository->findByIbanBlindIndex(
                $this->encryption->blindIndex(BankBlueprint::compactIban($account['iban']), 'finance_iban'),
            );

            $this->accountIds[$handle] = $existing !== null ? $existing->id : $service->createAccount(
                $account['name'],
                Account::TYPE_BANK,
                null,
                $account['iban'],
                'Unité ' . UnitBlueprint::UNIT_GROUP,
                $account['roleMinView'],
            )->id;
        }
    }

    /**
     * Seeds the default categories and the per-section accounts, exactly as a
     * chief's first visits to the Finances configuration pages would.
     *
     * **The order is load-bearing.** ensureDefaultCategories() only seeds when
     * the category table is still completely empty — deliberately, so that an
     * admin who deleted every default category does not get them resurrected
     * on the next page load. Creating an account first would defeat it:
     * FinanceService::createAccount() syncs that account's own
     * "Virement <compte>" system category, and the table is no longer empty.
     * Run the other way round, the dataset ended up with two categories
     * instead of twelve and six categorised movements out of a hundred and
     * twenty-five.
     */
    public function ensureModuleDefaults(): void
    {
        $service = $this->buildFinanceService();
        $service->ensureDefaultCategories();
        $service->ensureDefaultAccountsForSections();
    }

    private function buildFinanceService(): FinanceService
    {
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);
        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $transactionRepository = new TransactionRepository($this->pdo, $this->encryption);
        $checkpointRepository = new BalanceCheckpointRepository($this->pdo);

        return new FinanceService(
            new AccountRepository($this->pdo, $this->encryption),
            $categoryRepository,
            new FiscalYearRepository($this->pdo, $scoutYearService),
            new SectionService(
                \Core\Database\Connection::withPdo($this->pdo),
                $this->encryption,
                new \Core\Badge\MemberBadgeRepository($this->pdo),
            ),
            $transactionRepository,
            new BalanceService($checkpointRepository, $transactionRepository),
            $settingService,
            $categoryRuleRepository,
            new AccountTransferCategoryService($categoryRepository, $categoryRuleRepository, $transactionRepository),
            // Seeding acts for the installation, not for a person: there
            // is no session to narrow the treasurer rule against, and the
            // two calls above (default categories, default accounts) ask
            // nothing about visibility anyway.
            new \Modules\Finance\Service\AccountVisibility(
                \Modules\Finance\Service\TreasurerScope::systemCaller()
            ),
        );
    }

    /**
     * The composition public/index.php performs for the finance module,
     * rebuilt for a context with no request — and with the two optional AI
     * collaborators handed a null connector, which is what the site itself
     * does when `llm_connector` is disabled.
     */
    private function buildImportService(): ImportService
    {
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));

        $transactionRepository = new TransactionRepository($this->pdo, $this->encryption);
        $categoryRepository = new CategoryRepository($this->pdo);
        $categoryRuleRepository = new CategoryRuleRepository($this->pdo);
        $checkpointRepository = new BalanceCheckpointRepository($this->pdo);
        $attachmentRepository = new AttachmentRepository($this->pdo, $this->encryption);
        $transactionAttachmentRepository = new TransactionAttachmentRepository($this->pdo);
        $accountRepository = new AccountRepository($this->pdo, $this->encryption);

        $ruleEngine = new CategoryRuleEngine($transactionRepository, $categoryRuleRepository);

        $aiCategorizationService = new AiCategorizationService(
            null,
            $categoryRepository,
            new AiCategorySuggestionRepository($this->pdo),
            $journalService,
            $accountRepository,
            $transactionAttachmentRepository,
            $attachmentRepository,
            null,
            null,
        );

        return new ImportService(
            $this->pdo,
            $this->encryption,
            new BankStatementParserFactory(),
            $transactionRepository,
            $checkpointRepository,
            new StatementImportRepository($this->pdo),
            new FiscalYearRepository($this->pdo, new ScoutYearService($this->pdo)),
            $ruleEngine,
            new BalanceService($checkpointRepository, $transactionRepository),
            new ReceiptMatchingService(
                $attachmentRepository,
                $transactionRepository,
                $transactionAttachmentRepository,
                $journalService,
                null,
            ),
            new BulkCategorizationService(
                $transactionRepository,
                $ruleEngine,
                $aiCategorizationService,
                $settingService,
                $schedulerService,
            ),
            new ReceivableAllocationService(
                new ExpectedReceivableRepository($this->pdo, $this->encryption),
                new ReceivableAllocationRepository($this->pdo),
                $transactionRepository,
                new AccountRepository($this->pdo, $this->encryption),
                // A seeder acts for the installation, not for a person.
                new AccountVisibility(TreasurerScope::systemCaller()),
            ),
        );
    }

    /** @return array<string, int> handle => finance_accounts.id */
    public function accountIds(): array
    {
        return $this->accountIds;
    }
}
