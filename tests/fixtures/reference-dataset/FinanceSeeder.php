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
use Modules\Finance\Service\AccountImportOutcome;
use Modules\Finance\Service\ImportResult;
use Modules\Finance\Service\ImportService;
use Modules\Finance\Service\ReceivableAllocationService;
use Modules\Finance\Service\ReceiptMatchingService;
use Modules\Finance\Service\TreasurerScope;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

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
        // The CODA file covers a section account, which only a completed
        // section account (its IBAN) can receive. Idempotent: build.php has
        // usually done it already, and then this completes nothing.
        $this->completeSectionAccounts();

        $imported = 0;
        $duplicates = 0;
        $importService = $this->buildImportService();

        foreach (UnitBlueprint::YEARS as $index => $year) {
            foreach (array_keys(BankBlueprint::ACCOUNTS) as $handle) {
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
                        BankBlueprint::BANK_CODE,
                        $copy,
                        basename($path),
                        // Only the first file of an account carries the
                        // starting balance; passing one again would be a
                        // second checkpoint for the same account.
                        $index === 0 ? BankBlueprint::ACCOUNTS[$handle]['opening'] : null,
                        $this->importedBy,
                        static fn (): bool => true,
                    );
                } finally {
                    if (is_file($copy)) {
                        @unlink($copy);
                    }
                }

                $this->assertLandedIn($handle, $result, basename($path));
                $imported += $result->linesNew();
                $duplicates += $result->linesDuplicate();
            }
        }

        $coda = $this->importCodaStatement($importService);
        $imported += $coda->linesNew();
        $duplicates += $coda->linesDuplicate();

        return ['accounts' => count($this->accountIds), 'imported' => $imported, 'duplicates' => $duplicates];
    }

    /**
     * The unit's own accounts, with their IBANs — which is what lets
     * ImportService send each statement to its account and nowhere else.
     *
     * Created through FinanceService::createAccount(), never through the
     * repository: the service is what normalises the IBAN (IbanNormalizer —
     * uppercase, spaces stripped) before it is encrypted and blind-indexed,
     * and the blind index is exactly what ImportService looks up from the
     * IBAN BnpParser::extractAccountIbans() derives from the file. Writing the
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
     * Imports one further statement file into an account that already exists
     * — the campaign's payments (CampaignSeeder), which cannot be committed
     * with the other six because the communications they carry only exist
     * once the campaign has raised its receivables.
     *
     * Exposed rather than duplicated: rebuilding ImportService a second time
     * somewhere else is exactly how two callers start disagreeing about how
     * a line is categorised. No opening balance is passed — the account's
     * first import already set one, and a second checkpoint for the same
     * account would make every balance after it wrong.
     *
     * @return array{imported: int, duplicates: int}
     */
    public function importExtraStatement(string $handle, string $path, string $originalName): array
    {
        if (!isset($this->accountIds[$handle])) {
            throw new \RuntimeException("Le compte {$handle} est introuvable : les relevés ont-ils été importés ?");
        }

        $copy = (string) tempnam(sys_get_temp_dir(), 'refdataset-extra-bank');
        copy($path, $copy);

        try {
            $result = $this->buildImportService()->import(
                BankBlueprint::BANK_CODE,
                $copy,
                $originalName,
                null,
                $this->importedBy,
                static fn (): bool => true,
            );
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }

        $this->assertLandedIn($handle, $result, $originalName);

        return [
            'imported' => $result->linesNew(),
            'duplicates' => $result->linesDuplicate(),
        ];
    }

    /**
     * The CODA file (CodaBlueprint): one download split across the camps
     * account and the first section account by their IBANs. No format is
     * named — the import detects it, as it does for a treasurer — and no
     * balance is typed: the file states both, and the section account's
     * first import takes its opening balance from it.
     */
    private function importCodaStatement(ImportService $importService): ImportResult
    {
        $path = $this->datasetRoot . '/' . CodaBlueprint::FILE;
        if (!is_file($path)) {
            throw new \RuntimeException("Relevé introuvable : {$path}");
        }

        $copy = (string) tempnam(sys_get_temp_dir(), 'refdataset-coda');
        copy($path, $copy);

        try {
            $result = $importService->import(null, $copy, basename($path), null, $this->importedBy, static fn (): bool => true);
        } finally {
            if (is_file($copy)) {
                @unlink($copy);
            }
        }

        $section = (new AccountRepository($this->pdo, $this->encryption))->findByIbanBlindIndex(
            $this->encryption->blindIndex(
                BankBlueprint::compactIban(BankBlueprint::sectionIban(CodaBlueprint::SECTION_INDEX)),
                'finance_iban',
            ),
        );
        $landed = array_map(static fn (AccountImportOutcome $outcome): int => $outcome->account->id, $result->accounts);
        if ($section === null || $landed !== [$this->accountIds[CodaBlueprint::UNIT_ACCOUNT], $section->id] || $result->skipped !== []) {
            throw new \RuntimeException('Le fichier CODA n\'a pas rejoint le compte camps et le premier compte de section, et eux seuls.');
        }
        // CodaWriter took the camps opening from the ledger itself: a
        // discrepancy means the two have drifted apart, and the dataset
        // would show an alert it invented.
        foreach ($result->accounts as $outcome) {
            if ($outcome->balanceDiscrepancy !== null) {
                throw new \RuntimeException("Le solde CODA du compte {$outcome->account->name} ne correspond pas à son grand livre.");
            }
        }

        return $result;
    }

    /**
     * The file's own IBAN decides where its lines go (ImportService). A
     * statement written for one account and sent anywhere else — or set
     * aside — is a dataset that no longer means what BankBlueprint says, so
     * the build stops instead of carrying on with the wrong ledgers.
     */
    private function assertLandedIn(string $handle, ImportResult $result, string $file): void
    {
        $landed = array_map(static fn (AccountImportOutcome $outcome): int => $outcome->account->id, $result->accounts);
        if ($landed !== [$this->accountIds[$handle]] || $result->skipped !== []) {
            throw new \RuntimeException("Le relevé {$file} n'a pas rejoint le compte {$handle}, et lui seul.");
        }
    }

    /**
     * Gives every section account the IBAN and the holder it was created
     * without.
     *
     * ensureDefaultAccountsForSections() creates one account per section with
     * no IBAN at all, which is right — it cannot invent one — and leaves the
     * account inactive and without its own "Virement <compte>" category, since
     * an account with no IBAN has nothing a transfer could be recognised by.
     * A dataset that stopped there would show eight accounts no statement can
     * ever reach. The update goes through FinanceService::updateAccount(),
     * which normalises the IBAN before the blind index is computed and syncs
     * the transfer category — both of which writing the column by hand would
     * skip.
     *
     * @return int the number of section accounts completed
     */
    public function completeSectionAccounts(): int
    {
        $repository = new AccountRepository($this->pdo, $this->encryption);
        $service = $this->buildFinanceService();
        $completed = 0;

        foreach ($repository->findAllOrdered() as $account) {
            if ($account->sectionId === null || $account->iban !== null) {
                continue;
            }

            $iban = BankBlueprint::sectionIban($completed);
            $service->updateAccount(
                $account->id,
                $account->name,
                $account->accountType,
                $account->sectionId,
                $iban,
                'Unité ' . UnitBlueprint::UNIT_GROUP . ' — ' . $account->name,
                $account->roleMinView,
            );
            $completed++;
        }

        return $completed;
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
    new SectionRepository(\Core\Database\Connection::withPdo($this->pdo)),
    new MemberProfileRepository(\Core\Database\Connection::withPdo($this->pdo), $this->encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
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
        );

        return new ImportService(
            $this->pdo,
            $this->encryption,
            new BankStatementParserFactory(),
            $accountRepository,
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
