<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Repository\AccountRepository;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Fixtures\ReferenceDataset\BankBlueprint;
use Tests\Fixtures\ReferenceDataset\DatasetGenerator;
use Tests\Fixtures\ReferenceDataset\DemoAccounts;
use Tests\Fixtures\ReferenceDataset\DeskImportReplay;
use Tests\Fixtures\ReferenceDataset\FinanceSeeder;
use Tests\Fixtures\ReferenceDataset\UnitBlueprint;
use Tests\Modules\Finance\FinanceTestHelper;

/**
 * The pieces the CLI builder is made of, driven the way it drives them.
 *
 * `build.php` itself is a script: argument parsing, a refusal, and French
 * output. What it orchestrates is here — the finance seeding and the demo
 * accounts — and it is tested because these are the parts that write.
 *
 * The Desk half is already covered next door by ReferenceDatasetImportTest,
 * through the same DeskImportReplay the builder uses; this file picks up where
 * that one stops.
 *
 * @see tests/fixtures/reference-dataset/README.md §8
 */
#[Group('database')]
final class ReferenceDatasetBuilderTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
    }

    private static function datasetRoot(): string
    {
        return dirname(__DIR__) . '/fixtures/reference-dataset';
    }

    public function testTheStatementsImportThroughTheRealFinancePipeline(): void
    {
        $this->replayDesk();

        $seeder = new FinanceSeeder($this->pdo, $this->encryption, self::datasetRoot(), null);
        $seeder->ensureModuleDefaults();
        $counts = $seeder->seed();

        self::assertSame(count(BankBlueprint::ACCOUNTS), $counts['accounts']);
        self::assertGreaterThan(100, $counts['imported'], 'Le jeu de données a perdu des mouvements.');

        // Two accounts, two later years, three repeated lines each: the
        // overlap between successive files, recognised rather than re-imported.
        self::assertSame(
            count(BankBlueprint::ACCOUNTS) * (count(UnitBlueprint::YEARS) - 1) * BankBlueprint::OVERLAP_LINES,
            $counts['duplicates'],
            'La déduplication entre deux relevés successifs ne se déclenche plus.',
        );
    }

    public function testTheAccountsCarryAnIbanTheImportCanVerify(): void
    {
        // The whole reason the dataset's own IBANs are checksum-valid: they go
        // through FinanceService::createAccount(), which normalises them
        // before the blind index is computed. A spaced IBAN written straight
        // to the repository produces a different index from the one
        // BnpParser::extractSourceIban() derives, and every import fails with
        // an "IBAN mismatch" naming two IBANs that end in the same digits.
        $this->replayDesk();
        (new FinanceSeeder($this->pdo, $this->encryption, self::datasetRoot(), null))->ensureModuleDefaults();
        (new FinanceSeeder($this->pdo, $this->encryption, self::datasetRoot(), null))->seed();

        $repository = new AccountRepository($this->pdo, $this->encryption);

        foreach (BankBlueprint::ACCOUNTS as $handle => $account) {
            $found = $repository->findByIbanBlindIndex(
                $this->encryption->blindIndex(BankBlueprint::compactIban($account['iban']), 'finance_iban'),
            );

            self::assertNotNull($found, "Le compte {$handle} n'est pas retrouvable par l'index aveugle de son IBAN.");
            self::assertSame($account['name'], $found->name);
        }
    }

    public function testTheDefaultCategoriesAreSeededBeforeAnyAccountExists(): void
    {
        // ensureDefaultCategories() only seeds while the category table is
        // completely empty, and creating an account adds that account's own
        // "Virement <compte>" category. Run in the wrong order the dataset
        // ends up with two categories instead of a dozen, and almost nothing
        // categorised — which is exactly what happened the first time.
        $this->replayDesk();
        $seeder = new FinanceSeeder($this->pdo, $this->encryption, self::datasetRoot(), null);
        $seeder->ensureModuleDefaults();
        $seeder->seed();

        $categories = (int) ($this->pdo->query('SELECT COUNT(*) AS n FROM finance_categories')?->fetch()['n'] ?? 0);
        self::assertGreaterThanOrEqual(10, $categories, 'Les catégories par défaut n\'ont pas été semées.');

        $total = (int) ($this->pdo->query('SELECT COUNT(*) AS n FROM finance_transactions')?->fetch()['n'] ?? 0);
        $categorised = (int) ($this->pdo->query(
            'SELECT COUNT(*) AS n FROM finance_transactions WHERE category_id IS NOT NULL'
        )?->fetch()['n'] ?? 0);

        self::assertGreaterThan(0, $total);
        self::assertGreaterThan(
            $total * 0.6,
            $categorised,
            'Les règles de catégorisation par défaut ne mordent plus sur les libellés du jeu de données.',
        );
    }

    public function testTheSuperadminExistsBeforeTheImportsCreditIt(): void
    {
        // import_journal.user_account_id carries a foreign key, so the account
        // credited with an import has to exist first. A hard-coded id 1 works
        // on a freshly installed instance by coincidence and fails on any
        // instance whose accounts were renumbered — this one is created and
        // its real id used.
        $accounts = new DemoAccounts($this->pdo, $this->encryption, $this->people());
        $superadminId = $accounts->ensureSuperadmin();

        self::assertGreaterThan(0, $superadminId);

        $repository = new UserAccountRepository($this->pdo, $this->encryption);
        $found = $repository->findByEmail(DemoAccounts::SUPERADMIN_EMAIL);

        self::assertNotNull($found);
        self::assertSame($superadminId, $found->id);
        self::assertTrue($repository->hasPassword($superadminId));
    }

    public function testEveryDemoAccountLandsOnTheMemberItNames(): void
    {
        $this->replayDesk();

        $used = (new DemoAccounts($this->pdo, $this->encryption, $this->people()))->seedMemberAccounts();

        $repository = new UserAccountRepository($this->pdo, $this->encryption);

        foreach (DemoAccounts::MEMBER_ACCOUNTS as $handle => $account) {
            self::assertArrayHasKey(
                $handle,
                $used,
                "Le compte de démonstration « {$handle} » n'a pas trouvé son membre ({$account['tiers']}) : "
                . 'ce Tiers a-t-il encore une adresse email dans le jeu de données ?',
            );

            $user = $repository->findByEmail($used[$handle]);
            self::assertNotNull($user);
            self::assertTrue($repository->hasPassword($user->id), "Le compte {$handle} n'a pas de mot de passe.");
        }
    }

    /**
     * @return array<string, \Tests\Fixtures\ReferenceDataset\Person>
     */
    private function people(): array
    {
        return (new DatasetGenerator(self::datasetRoot()))->people();
    }

    /**
     * The builder's own order: the superadministrator first, then the imports
     * credited to it. DeskImportService::import() takes a non-nullable
     * importer, and `import_journal.user_account_id` carries a foreign key to
     * it on a real MySQL instance.
     */
    private function replayDesk(): int
    {
        $superadminId = (new DemoAccounts($this->pdo, $this->encryption, $this->people()))->ensureSuperadmin();

        $replay = new DeskImportReplay($this->pdo, $this->encryption, self::datasetRoot());
        $yearIds = $replay->ensureYears();
        $replay->importAll($yearIds, $superadminId);
        $replay->confirmFunctionRoles($yearIds);

        return $superadminId;
    }
}
