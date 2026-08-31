<?php

declare(strict_types=1);

namespace Tests\Integration;

use Core\Badge\MemberBadgeRepository;
use Core\Database\Connection;
use Core\Maintenance\BackupService;
use Core\Security\EncryptionService;
use Core\Member\SectionService;
use Core\Security\UserAccountRepository;
use Modules\Finance\Repository\AccountRepository;
use Modules\Trombinoscope\Repository\TrombinoscopeRepository;
use Modules\Trombinoscope\Service\TrombinoscopeService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Fixtures\ReferenceDataset\BankBlueprint;
use Tests\Fixtures\ReferenceDataset\DatasetGenerator;
use Tests\Fixtures\ReferenceDataset\DemoAccounts;
use Tests\Fixtures\ReferenceDataset\DeskImportReplay;
use Tests\Fixtures\ReferenceDataset\ExtrasApplier;
use Tests\Fixtures\ReferenceDataset\ExtrasBlueprint;
use Tests\Fixtures\ReferenceDataset\PhotoLot;
use Tests\Fixtures\ReferenceDataset\FinanceSeeder;
use Tests\Fixtures\ReferenceDataset\InstanceReset;
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
    private string $storagePath;

    /** @var array<string, int> */
    private array $yearIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FinanceTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_refdataset_' . uniqid();
        mkdir($this->storagePath, 0755, true);
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->storagePath);
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
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

    // ---------------------------------------------------------------- extras

    public function testTheExtrasApplyThroughTheRealServices(): void
    {
        $counts = $this->applyExtras()['counts'];

        self::assertSame(2, $counts['décalages d\'année']);
        self::assertSame(count(ExtrasBlueprint::DEPARTURES), $counts['départs marqués']);
        self::assertSame(count(ExtrasBlueprint::BADGES), $counts['badges attribués']);
        self::assertGreaterThan(30, $counts['photos de membres'], 'Les portraits ne sont plus attribués.');
        self::assertSame(count(PhotoLot::GROUP_PHOTOS, COUNT_RECURSIVE) - count(PhotoLot::GROUP_PHOTOS), $counts['photos de groupe']);
    }

    public function testEveryPhotoGoesThroughTheRealUploadPipeline(): void
    {
        // Not a row written by hand: a `files` entry per photo, a derivative
        // per photo, and the group photos cropped to 4:3 before storage. Those
        // three are exactly what writing into member_photos directly would
        // have skipped — and the only reason IT-05bis extracted the pipeline.
        $this->applyExtras();

        $memberPhotos = (int) ($this->pdo->query('SELECT COUNT(*) AS n FROM member_photos')?->fetch()['n'] ?? 0);
        $sectionPhotos = (int) ($this->pdo->query('SELECT COUNT(*) AS n FROM section_staff_photos')?->fetch()['n'] ?? 0);
        $memberFiles = (int) ($this->pdo->query(
            "SELECT COUNT(*) AS n FROM files WHERE relative_path LIKE 'core/member_photos/%'"
        )?->fetch()['n'] ?? 0);

        self::assertGreaterThan(30, $memberPhotos);
        self::assertSame($memberPhotos, $memberFiles, 'Chaque photo de membre doit avoir sa ligne files.');
        self::assertGreaterThan(0, $sectionPhotos);

        $derivatives = glob($this->storagePath . '/core/member_photos/*.thumb.webp') ?: [];
        self::assertCount($memberPhotos, $derivatives, 'Chaque portrait doit avoir sa vignette.');

        $stored = glob($this->storagePath . '/core/section_photos/*') ?: [];
        $stored = array_values(array_filter($stored, static fn (string $p): bool => !str_contains($p, '.md.webp')));
        self::assertNotEmpty($stored);
        $size = getimagesize($stored[0]);
        self::assertNotFalse($size);
        self::assertEqualsWithDelta(4 / 3, $size[0] / $size[1], 0.01, 'Une photo de groupe n\'a pas été recadrée en 4:3.');
    }

    public function testAReceivableExistsForEveryStructuredCommunication(): void
    {
        // What turns the membership payments — which no categorisation rule
        // matches, and none should — into a reconciliation instead of a
        // mystery.
        $this->applyExtras();

        $expected = 0;
        foreach (UnitBlueprint::YEARS as $year) {
            $expected += count(BankBlueprint::communicationsFor($year));
        }

        $found = (int) ($this->pdo->query('SELECT COUNT(*) AS n FROM finance_expected_receivables')?->fetch()['n'] ?? 0);
        self::assertSame($expected, $found);
    }

    public function testTheExtrasOfADisabledModuleAreSkippedRatherThanFatal(): void
    {
        // The calendar tables are absent from this test database, exactly as
        // they are on an instance where the module is disabled. A build must
        // skip those extras, not die halfway and leave the dataset
        // half-applied.
        $result = $this->applyExtras();

        self::assertSame(0, $result['counts']['évènements de calendrier']);
        self::assertSame(
            'calendar',
            $result['skipped']['évènements de calendrier'] ?? null,
            'Un extra ignoré doit être signalé, pas se contenter d\'un compteur à zéro.',
        );
        self::assertGreaterThan(0, $result['counts']['créances attendues'], 'Les modules présents doivent, eux, être traités.');
        self::assertArrayNotHasKey('créances attendues', $result['skipped']);
    }

    /**
     * The section responsable, end to end: the vocabulary carries
     * « Animateur responsable », the builder flags that function as the
     * trombinoscope's lead, and Core\Module\SectionResponsableProvider then
     * answers with a real person for every section.
     *
     * None of that existed before: no generated function carried the label,
     * so the provider answered null for every section and three surfaces
     * built on it — the public Sections page's responsable line, the member
     * page's responsable-and-postal-address block, the trombinoscope's
     * highlighted card — were exercised by nothing at all.
     */
    public function testTheSectionResponsableIsFlaggedAndResolves(): void
    {
        // The module's own table, absent from the shared test schema for the
        // same reason the calendar's is: it belongs to a module, and
        // ExtrasApplier reads its presence as "this module is enabled here".
        $this->pdo->exec('CREATE TABLE trombinoscope_function_flags (
            function_id INTEGER PRIMARY KEY,
            is_lead INTEGER NOT NULL DEFAULT 0
        )');

        $result = $this->applyExtras();

        self::assertSame(1, $result['counts']['drapeau responsable']);
        self::assertArrayNotHasKey('drapeau responsable', $result['skipped']);

        $flagged = $this->pdo->query(
            "SELECT f.desk_code FROM trombinoscope_function_flags tff
             JOIN functions f ON f.id = tff.function_id WHERE tff.is_lead = 1"
        )?->fetchAll(\PDO::FETCH_COLUMN) ?: [];
        self::assertSame(['Animateur responsable'], $flagged);

        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
            $connection,
            $this->encryption,
            new MemberBadgeRepository($this->pdo),
        );
        $trombinoscope = new TrombinoscopeService(
            new TrombinoscopeRepository($connection),
            $sectionService,
        );

        $yearId = $this->yearIds['2025-2026'];
        $resolved = 0;
        foreach ($sectionService->getAllWithBranches() as $section) {
            if ($trombinoscope->getResponsable((int) $section['id'], $yearId) !== null) {
                $resolved++;
            }
        }

        self::assertGreaterThan(
            0,
            $resolved,
            'Aucune section n\'a de responsable résolu : le drapeau ou la fonction ne se rejoignent pas.',
        );
    }

    /**
     * @return array{counts: array<string, int>, skipped: array<string, string>}
     */
    private function applyExtras(): array
    {
        $superadminId = $this->replayDesk();
        $seeder = new FinanceSeeder($this->pdo, $this->encryption, self::datasetRoot(), $superadminId);
        $seeder->ensureModuleDefaults();
        $seeder->seed();

        return (new ExtrasApplier(
            $this->pdo,
            $this->encryption,
            $this->storagePath,
            self::datasetRoot(),
            $superadminId,
        ))->apply($this->yearIds, $seeder->accountIds()['unite'] ?? 0);
    }

    public function testTheResetEmptiesTheDataAndLeavesTheConfigurationStanding(): void
    {
        $this->replayDesk();
        $this->seedConfigurationRows();

        self::assertGreaterThan(0, $this->rowsIn('members'), 'Le test part déjà d\'une base vide.');

        $result = (new InstanceReset(
            Connection::withPdo($this->pdo),
            $this->storagePath,
            dirname($this->storagePath),
        ))->run(false);

        self::assertSame(0, $this->rowsIn('members'), 'Le vidage a laissé des membres derrière lui.');
        self::assertSame(0, $this->rowsIn('member_years'));
        self::assertSame(0, $this->rowsIn('sections'));
        self::assertSame(0, $this->rowsIn('user_accounts'));

        // Ce qui doit survivre : le site reste installé et ses modules activés.
        // Un reset qui désactive la moitié des modules n'est pas un reset,
        // c'est une réinstallation — et le builder a besoin de finance et de
        // calendrier pour construire quoi que ce soit.
        self::assertTrue(
            $this->configurationProbesSurvive(),
            'Les réglages du site ou la liste des modules activés ont été effacés.',
        );

        self::assertNull($result['backupPath']);
        self::assertNotNull($result['backupError'], 'Une sauvegarde sautée doit être dite, pas passée sous silence.');
        self::assertGreaterThan(0, $result['tables']);
    }

    public function testTheResetPreservesExactlyWhatTheApplicationCallsConfiguration(): void
    {
        // La liste n'est pas une invention de InstanceReset : c'est celle de
        // BackupService, seule réponse relue du projet à « quelles tables sont
        // de la configuration et non des données ». Si elle gagne une
        // troisième table, ce test échoue tant que le reset ne l'a pas suivie.
        self::assertSame(
            self::privateConstant(BackupService::class, 'CONFIG_ONLY_TABLES'),
            self::privateConstant(InstanceReset::class, 'PRESERVED_TABLES'),
            'InstanceReset et BackupService ne s\'accordent plus sur ce qui est de la configuration.',
        );
    }

    public function testTheResetDeletesTheUploadedFilesButNeverTheKeys(): void
    {
        $this->writeStorageFile('keys/master.key', 'clé maîtresse');
        $this->writeStorageFile('config/secrets.enc', 'secrets');
        $this->writeStorageFile('maintenance/database_2026-08-24.sql', '-- dump');
        $this->writeStorageFile('core/photos/2026/08/portrait.jpg', 'JPEG');
        $this->writeStorageFile('modules/finance/releve.csv', 'CSV');
        $this->seedConfigurationRows();

        $result = (new InstanceReset(
            Connection::withPdo($this->pdo),
            $this->storagePath,
            dirname($this->storagePath),
        ))->run(false);

        // Sans ces deux-là l'instance n'est plus installée : SecretManager
        // ne trouve plus rien, et le prochain appel tombe sur l'assistant
        // d'installation au lieu de construire.
        self::assertFileExists($this->storagePath . '/keys/master.key');
        self::assertFileExists($this->storagePath . '/config/secrets.enc');
        // Et la sauvegarde de sécurité est écrite là : la supprimer juste
        // après l'avoir prise serait une farce.
        self::assertFileExists($this->storagePath . '/maintenance/database_2026-08-24.sql');

        self::assertFileDoesNotExist($this->storagePath . '/core/photos/2026/08/portrait.jpg');
        self::assertFileDoesNotExist($this->storagePath . '/modules/finance/releve.csv');
        self::assertSame(2, $result['files']);

        // Le pipeline de téléversement que le builder enchaîne juste après
        // écrit dans storage/temp ; le vidage doit le rendre, pas le laisser
        // manquant.
        self::assertDirectoryExists($this->storagePath . '/temp');
    }

    public function testAFailedSafetyDumpAbortsTheResetWithoutEmptyingAnything(): void
    {
        $this->replayDesk();
        $before = $this->rowsIn('members');

        // Connection::withPdo() ne porte aucun identifiant : DatabaseDumper
        // ouvre sa propre connexion MySQL depuis un DSN et échoue. C'est le
        // scénario réel d'un storage/ non inscriptible ou d'une base
        // injoignable, et il ne doit surtout pas se solder par un vidage.
        $reset = new InstanceReset(
            Connection::withPdo($this->pdo),
            $this->storagePath,
            dirname($this->storagePath),
        );

        try {
            $reset->run(true);
            self::fail('Un dump de sécurité en échec doit interrompre la réinitialisation.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('--no-backup', $exception->getMessage());
        }

        self::assertSame($before, $this->rowsIn('members'), 'Des lignes ont été perdues malgré l\'échec du dump.');
    }

    private function rowsIn(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) AS n FROM "' . $table . '"');

        return $statement === false ? 0 : (int) ($statement->fetch(\PDO::FETCH_ASSOC)['n'] ?? 0);
    }

    /**
     * One recognisable row in each of the two tables the reset must not touch,
     * so "they survived" can be asserted on a value rather than on a count the
     * rest of the build also writes to.
     */
    private function seedConfigurationRows(): void
    {
        $this->pdo->exec(
            "INSERT INTO settings (module_id, setting_key, setting_value, setting_type, label, description)"
            . " VALUES (NULL, 'reset_probe_unit_name', 'Unité de test', 'text', 'Nom', 'Sonde du test')"
        );
        $this->pdo->exec(
            "INSERT INTO module_registry (module_id, enabled, installed_version)"
            . " VALUES ('reset_probe_finance', 1, '1.0.0')"
        );
    }

    private function configurationProbesSurvive(): bool
    {
        $settings = $this->pdo->query(
            "SELECT COUNT(*) AS n FROM settings WHERE setting_key = 'reset_probe_unit_name'"
        );
        $modules = $this->pdo->query(
            "SELECT COUNT(*) AS n FROM module_registry WHERE module_id = 'reset_probe_finance'"
        );

        return $settings !== false && $modules !== false
            && (int) ($settings->fetch(\PDO::FETCH_ASSOC)['n'] ?? 0) === 1
            && (int) ($modules->fetch(\PDO::FETCH_ASSOC)['n'] ?? 0) === 1;
    }

    private function writeStorageFile(string $relativePath, string $contents): void
    {
        $absolute = $this->storagePath . '/' . $relativePath;
        if (!is_dir(dirname($absolute))) {
            mkdir(dirname($absolute), 0755, true);
        }
        file_put_contents($absolute, $contents);
    }

    /**
     * @return list<string>
     */
    private static function privateConstant(string $class, string $name): array
    {
        /** @var list<string> $value */
        $value = (new \ReflectionClass($class))->getConstant($name);

        return $value;
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
        $this->yearIds = $replay->ensureYears();
        $replay->importAll($this->yearIds, $superadminId);
        $replay->confirmFunctionRoles($this->yearIds);

        return $superadminId;
    }
}
