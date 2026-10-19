<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\ImportController;
use Core\Http\Request;
use Core\Import\AgeBranchRepository;
use Core\Import\DeskCsvParser;
use Core\Import\DeskImportService;
use Core\Import\DeskImportYearPolicy;
use Core\Import\FeeCategoryRepository;
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\ImportSectionRepository;
use Core\Import\MappingResolver;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Member\UnitStaffSectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\TestTwig;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ImportControllerTest extends TestCase
{
    private ImportController $controller;
    /** @var \Closure(\Core\Storage\DiskBudget|null): ImportController */
    private \Closure $rebuildController;
    private string $storagePath;
    private string $installPath;
    private \PDO $pdo;
    private EncryptionService $encryption;
    /** « Today » for the year policy — moved by the tests that need another day. */
    private string $today;
    private bool $configurationMode = false;
    private int $publicYearId;
    private string $endCalendarYear;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
        $_SESSION = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        // Create scout year
        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");
        $this->publicYearId = (int) $this->pdo->lastInsertId();
        $this->endCalendarYear = substr($yearEnd, 0, 4);
        // Before the next year opens: the public year alone, no question
        // asked — the day every test that does not care about the policy
        // runs on.
        $this->today = $this->endCalendarYear . '-03-01';

        // Create admin user
        $stmt = $this->pdo->prepare("INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, 'admin_idx', 1)");
        $stmt->execute([$this->encryption->encrypt('admin@test.com', 'user_accounts.email')]);

        $twig = TestTwig::create();
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.com');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

        // The report names people the way every other admin screen does
        // (design.md § Display name convention) — registered here as
        // Core\View\TwigFactory does in production.

        // `storage/` nested inside an installation root of its own: a
        // declared quota is charged for the parent tree, so a `storage/`
        // sitting directly under the system temp directory would be
        // measured against everything else on the machine.
        $this->installPath = sys_get_temp_dir() . '/scoutmagic_test_' . bin2hex(random_bytes(8));
        $storagePath = $this->installPath . '/storage';
        mkdir($storagePath, 0755, true);
        $this->storagePath = $storagePath;

        $scoutYearService = new ScoutYearService($this->pdo);
        $functionRepo = new FunctionRepository($this->pdo);
        $ageBranchRepo = new AgeBranchRepository($this->pdo);
        $sectionRepo = new ImportSectionRepository($this->pdo);
        $feeRepo = new FeeCategoryRepository($this->pdo);
        $memberRepo = new MemberRepository($this->pdo);
        $memberYearRepo = new MemberYearRepository($this->pdo);
        $importJournalRepo = new ImportJournalRepository($this->pdo);
        $userAccountRepo = new UserAccountRepository($this->pdo, $this->encryption);
        $mappingResolver = new MappingResolver($functionRepo, $ageBranchRepo, $sectionRepo, $feeRepo);
        $parser = new DeskCsvParser();
        $yearPolicy = DeskImportYearPolicy::forSite(
            new ScoutYearResolver($scoutYearService, new SettingService(new SettingRepository($this->pdo)), $memberYearRepo),
            $scoutYearService,
            new SettingService(new SettingRepository($this->pdo)),
            fn(): \DateTimeImmutable => new \DateTimeImmutable($this->today),
            fn(): bool => $this->configurationMode
        );
        $importService = new DeskImportService(
            $this->pdo, $this->encryption, $parser, $mappingResolver,
            $memberRepo, $memberYearRepo, $importJournalRepo, $userAccountRepo,
            new UnitStaffSectionService(new \Core\Member\Repository\UnitStaffSectionRepository($this->pdo)),
            new \Core\Member\SectionMembershipService(new \Core\Member\SectionMembershipRepository($this->pdo), $scoutYearService),
            new \Core\Import\RosterReplacementGuard(
                new \Core\Import\RosterComparisonRepository($this->pdo),
                new ScoutYearResolver($scoutYearService, new SettingService(new SettingRepository($this->pdo)), $memberYearRepo)
            ),
            new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo)),
            new \Core\Import\RosterSnapshotRepository($this->pdo),
            new \Core\File\EncryptedFileStorageService(
                new \Core\File\FileRepository($this->pdo),
                $this->encryption,
                $storagePath
            ),
            new \Core\Import\ImportDiffCalculator(new \Core\Import\RosterSnapshotRepository($this->pdo)),
            $scoutYearService,
            $yearPolicy
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $fileRepository = new \Core\File\FileRepository($this->pdo);
        $rosterSnapshotRepo = new \Core\Import\RosterSnapshotRepository($this->pdo);

        // A closure rather than one instance, so a test can rebuild the
        // controller with a disk budget attached — the refusal branch is
        // otherwise structurally unreachable, the production wiring being
        // the only place that passes one.
        $this->rebuildController = function (
            ?\Core\Storage\DiskBudget $diskBudget = null,
            ?DeskImportService $importServiceOverride = null
        ) use (
            $twig,
            $importService,
            $yearPolicy,
            $scoutYearResolver,
            $importJournalRepo,
            $functionRepo,
            $rosterSnapshotRepo,
            $fileRepository,
            $userAccountRepo,
            $scoutYearService,
            $settingService,
            $storagePath
        ): ImportController {
            return new ImportController(
                $twig,
                $importServiceOverride ?? $importService,
                $yearPolicy,
                $scoutYearResolver,
                $importJournalRepo,
                $functionRepo,
                new \Core\Import\ImportRetentionService(
                    $this->pdo,
                    $importJournalRepo,
                    $rosterSnapshotRepo,
                    $fileRepository,
                    $scoutYearService,
                    $settingService,
                    new \Core\Journal\JournalService(new \Core\Journal\JournalRepository($this->pdo)),
                    $storagePath
                ),
                $rosterSnapshotRepo,
                $fileRepository,
                $userAccountRepo,
                new \Core\Import\ImportReportPresenter(
                    new \Core\Import\ImportReportRepository($this->pdo, $this->encryption)
                ),
                $storagePath,
                null,
                $diskBudget
            );
        };
        $this->controller = ($this->rebuildController)();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    public function testIndexPageRendersWithYearSelector(): void
    {
        $request = new Request('GET', '/admin/import', [], [], [], []);
        $response = $this->controller->index($request, []);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('Import Desk', $body);
        $this->assertStringContainsString('Année scoute', $body);
        // The year the page renders is the CURRENT one, so the assertion
        // has to ask for that rather than for a year written out.
        $this->assertStringContainsString(DatabaseTestHelper::scoutYear()[0], $body);
    }

    public function testIndexShowsUploadForm(): void
    {
        $request = new Request('GET', '/admin/import', [], [], [], []);
        $response = $this->controller->index($request, []);

        $body = $response->getBody();
        $this->assertStringContainsString('enctype="multipart/form-data"', $body);
        $this->assertStringContainsString('csv_file', $body);
        $this->assertStringContainsString('Importer', $body);
    }

    public function testTheHistoryRendersTheYearsImports(): void
    {
        $importId = $this->seedImport();

        $response = $this->controller->history(new Request('GET', '/admin/import/historique', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Historique des imports', $body);
        $this->assertStringContainsString('/admin/import/' . $importId . '/rapport', $body);
        // The retention is stated on the page, not left to be discovered.
        $this->assertStringContainsString('durée de conservation', $body);
    }

    public function testTheReportRendersTheFrozenDiff(): void
    {
        $importId = $this->seedImport();

        $response = $this->controller->report(
            new Request('GET', '/admin/import/' . $importId . '/rapport', [], [], [], []),
            ['id' => (string) $importId]
        );
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString("Rapport d'import", $body);
        $this->assertStringContainsString('Aucun point de comparaison', $body);
        $this->assertStringContainsString('Qualité des données', $body);
        // Attention points live on their own page; a report never carries one.
        $this->assertStringNotContainsString("Points d'attention", $body);
    }

    public function testTheReportOfAnUnknownImportIsNotFound(): void
    {
        $response = $this->controller->report(
            new Request('GET', '/admin/import/9999/rapport', [], [], [], []),
            ['id' => '9999']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    /**
     * One import of the current year, with the deliberately unavailable
     * diff a season's first import stores.
     */
    private function seedImport(): int
    {
        $currentYearId = (int) $this->pdo->query('SELECT id FROM scout_years LIMIT 1')->fetchColumn();
        $repo = new ImportJournalRepository($this->pdo);
        $importId = $repo->create($currentYearId, 1, 268, 262, 0);
        $repo->storeDiff($importId, \Core\Import\ImportDiff::unavailable(\Core\Import\ImportDiff::UNAVAILABLE_FIRST_OF_SEASON));

        return $importId;
    }

    // ------------------------------------------------------------------
    // The year policy (Core\Import\DeskImportYearPolicy) on the page and
    // on the POST. The page is checked for what it OFFERS — an <option> —
    // and the POST for what it REFUSES, journal and all.
    // ------------------------------------------------------------------

    private function yearId(int $offset): int
    {
        return (new ScoutYearService($this->pdo))->ensureYear(DatabaseTestHelper::scoutYear($offset)[0]);
    }

    private function indexBody(string $query = ''): string
    {
        parse_str($query, $get);
        $response = $this->controller->index(new Request('GET', '/admin/import', $get, [], [], []), []);
        $this->assertSame(200, $response->getStatusCode());

        return html_entity_decode($response->getBody(), ENT_QUOTES | ENT_HTML5);
    }

    /** @return array<string, mixed> */
    private function island(string $body): array
    {
        $this->assertSame(1, preg_match('#<script type="application/json" id="desk-import-years">(.*?)</script>#s', $body, $m));
        $data = json_decode(trim($m[1]), true);
        $this->assertIsArray($data);

        return $data;
    }

    public function testBeforeTheOpeningThePageOffersThePublicYearAlone(): void
    {
        $body = $this->indexBody();
        $next = $this->yearId(1);

        $this->assertStringContainsString('<option value="' . $this->publicYearId . '" selected>', $body);
        $this->assertStringNotContainsString('<option value="' . $next . '"', $body);
        $this->assertStringContainsString("Une seule année est importable aujourd'hui.", $body);
        $this->assertStringContainsString('data-import-period="before" aria-current="date"', $body);
        $this->assertStringContainsString('src="/assets/js/admin-import.js', $body);
    }

    public function testInTheWindowBothYearsAreOfferedTheNextOneProposedAndThePublicOneAsked(): void
    {
        $this->today = $this->endCalendarYear . '-07-20';
        $next = $this->yearId(1);

        $body = $this->indexBody();

        $this->assertStringContainsString('<option value="' . $next . '" selected>', $body);
        $this->assertStringContainsString('<option value="' . $this->publicYearId . '">', $body);
        $this->assertStringContainsString(DatabaseTestHelper::scoutYear(1)[0] . ' — année suivante', $body);
        $this->assertStringContainsString(DatabaseTestHelper::scoutYear()[0] . ' — année publique', $body);

        $years = array_column($this->island($body)['years'], null, 'id');
        $this->assertNull($years[$next]['confirm']);
        $this->assertSame(
            'Importer dans ' . DatabaseTestHelper::scoutYear()[0],
            $years[$this->publicYearId]['confirm']['confirmLabel']
        );
        $this->assertStringContainsString("l'année que voient les familles", $years[$this->publicYearId]['confirm']['message']);
    }

    public function testTheLinkFromTheScoutYearPageSelectsAnOfferedYearAndNothingElse(): void
    {
        $this->today = $this->endCalendarYear . '-07-20';
        $next = $this->yearId(1);
        $this->assertStringContainsString(
            '<option value="' . $this->publicYearId . '" selected>',
            $this->indexBody('annee=' . $this->publicYearId)
        );
        $this->assertStringContainsString('<option value="' . $next . '" selected>', $this->indexBody('annee=' . $next));

        // Before the opening the next year is not offered: the link names
        // it, the page ignores it.
        $this->today = $this->endCalendarYear . '-03-01';
        $body = $this->indexBody('annee=' . $next);
        $this->assertStringNotContainsString('<option value="' . $next . '"', $body);
        $this->assertStringContainsString('<option value="' . $this->publicYearId . '" selected>', $body);
    }

    public function testFromTheEncodingDateOnlyTheNextYearIsOffered(): void
    {
        $this->today = $this->endCalendarYear . '-08-15';
        $next = $this->yearId(1);

        $body = $this->indexBody();

        $this->assertStringContainsString('<option value="' . $next . '" selected>', $body);
        $this->assertStringNotContainsString('<option value="' . $this->publicYearId . '"', $body);
    }

    public function testConfigurationModeOffersEveryYearAndSaysSo(): void
    {
        $previous = $this->yearId(-1);
        $this->configurationMode = true;

        $body = $this->indexBody();

        $this->assertStringContainsString('<option value="' . $previous . '">', $body);
        $this->assertStringContainsString('Mode configuration actif : toutes les années existantes sont importables.', $body);
        $years = array_column($this->island($body)['years'], null, 'id');
        $this->assertStringContainsString("n'est importable qu'en mode configuration", $years[$previous]['confirm']['message']);
        $this->assertNull($years[$this->publicYearId]['confirm']);
    }

    public function testTheLastImportBlockFollowsTheSelectedYear(): void
    {
        $importId = $this->seedImport();
        $this->today = $this->endCalendarYear . '-07-20';
        $nextLabel = DatabaseTestHelper::scoutYear(1)[0];
        $publicLabel = DatabaseTestHelper::scoutYear()[0];

        // The next year is selected and has no import yet.
        $body = $this->indexBody();
        $this->assertStringContainsString('Dernier import de ' . $nextLabel, $body);
        $this->assertStringContainsString('Aucun import pour ' . $nextLabel . '.', $body);

        // The public one carries its import in the island, ready for the select.
        $years = array_column($this->island($body)['years'], null, 'id');
        $this->assertSame($importId, $years[$this->publicYearId]['lastImport']['id']);
        $this->assertStringContainsString('262 membres, 268 lignes', $years[$this->publicYearId]['lastImport']['summary']);

        // Selected through the link, the block is the public year's, with its history link.
        $body = $this->indexBody('annee=' . $this->publicYearId);
        $this->assertStringContainsString('Dernier import de ' . $publicLabel, $body);
        $this->assertStringContainsString('/admin/import/' . $importId . '/rapport', $body);
        $this->assertStringContainsString('/admin/import/historique?annee=' . $this->publicYearId, $body);
    }

    /**
     * @param array<string, string> $extra
     */
    private function postYear(int $yearId, array $extra = []): \Core\Http\Response
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;

        return $this->controller->import(new Request(
            'POST',
            '/admin/import',
            [],
            ['_csrf_token' => $token, 'scout_year_id' => (string) $yearId] + $extra,
            [],
            []
        ), []);
    }

    /** @return list<array<string, mixed>> */
    private function yearRefusals(): array
    {
        $stmt = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'desk_import_year_refused' ORDER BY id");

        return $stmt === false ? [] : $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function assertRefusedWith(\Core\Http\Response $response, string $sentence, string $reason): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/import', $response->getHeaders()['Location'] ?? null);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString($sentence, (string) ($flash['message'] ?? ''));

        $refusals = $this->yearRefusals();
        $this->assertNotSame([], $refusals);
        $this->assertStringContainsString('"reason":"' . $reason . '"', (string) $refusals[count($refusals) - 1]['context']);

        // Nothing deposited, nothing imported.
        $this->assertSame([], glob($this->storagePath . '/temp/import_*.csv') ?: []);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM import_journal')->fetchColumn());
    }

    /** The year is the server's question: a POST naming a year the page does not offer is refused, file or no file. */
    public function testAPostOfAYearNotOfferedIsRefused(): void
    {
        $next = $this->yearId(1);
        $_FILES['csv_file'] = [
            'name' => 'effectifs.csv',
            'type' => 'text/csv',
            'tmp_name' => $this->storagePath . '/never-read.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => 4096,
        ];

        try {
            $response = $this->postYear($next, ['confirmed_year' => (string) $next]);
        } finally {
            unset($_FILES['csv_file']);
        }

        $this->assertRefusedWith($response, "ne peut pas recevoir d'import Desk aujourd'hui", 'not_offered');
    }

    /** A forged or replayed POST into the public year during the window, without the confirmation, is refused. */
    public function testAForgedPostWithoutConfirmedYearIsRefused(): void
    {
        $this->today = $this->endCalendarYear . '-07-20';
        $this->yearId(1);

        $this->assertRefusedWith($this->postYear($this->publicYearId), 'demande une confirmation', 'confirmation_missing');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function confirmationsThatAreNot(): iterable
    {
        yield 'empty' => [''];
        yield 'not a number' => ['12abc'];
        yield 'another year' => ['999999'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('confirmationsThatAreNot')]
    public function testOnlyTheYearItselfConfirmsIt(string $confirmedYear): void
    {
        $this->today = $this->endCalendarYear . '-07-20';
        $this->yearId(1);

        $this->assertRefusedWith(
            $this->postYear($this->publicYearId, ['confirmed_year' => $confirmedYear]),
            'demande une confirmation',
            'confirmation_missing'
        );
    }

    /** With its confirmation the year gets past the policy — to the next check, the file. */
    public function testAConfirmedYearGetsPastThePolicy(): void
    {
        $this->today = $this->endCalendarYear . '-07-20';
        $this->yearId(1);

        $response = $this->postYear($this->publicYearId, ['confirmed_year' => (string) $this->publicYearId]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Veuillez sélectionner un fichier CSV valide.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame([], $this->yearRefusals());
    }

    public function testConfigurationModeOpensAPastYearBehindTheSameConfirmation(): void
    {
        $previous = $this->yearId(-1);

        // Off: not offered at all, confirmation or not.
        $this->assertRefusedWith(
            $this->postYear($previous, ['confirmed_year' => (string) $previous]),
            "ne peut pas recevoir d'import Desk",
            'not_offered'
        );

        // On: offered, and asked.
        $this->configurationMode = true;
        $this->assertRefusedWith($this->postYear($previous), 'demande une confirmation', 'confirmation_missing');

        $response = $this->postYear($previous, ['confirmed_year' => (string) $previous]);
        $this->assertSame('Veuillez sélectionner un fichier CSV valide.', \Core\Http\FlashMessage::get()['message'] ?? null);
        $this->assertSame(302, $response->getStatusCode());
    }

    public function testImportRejectsMissingCsrf(): void
    {
        $request = new Request('POST', '/admin/import', [], ['_csrf_token' => 'invalid'], [], []);
        $response = $this->controller->import($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
    }

    /**
     * The deposited CSV is a real write against the quota, and the refusal
     * has to reach the operator as the sentence that says what to do —
     * never as a 500, and never as a half-written file under
     * `storage/temp`.
     *
     * `InsufficientDiskSpaceException` is not caught by anything upstream
     * of this route, so without this branch a full quota would take the
     * import screen down rather than decline one upload.
     */
    public function testImportRefusesWhenTheDepositedCsvWouldNotFit(): void
    {
        $controller = ($this->rebuildController)($this->exhaustedBudget());

        $_FILES['csv_file'] = [
            'name' => 'effectifs.csv',
            'type' => 'text/csv',
            'tmp_name' => $this->storagePath . '/never-read.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => 4096,
        ];

        try {
            $response = $controller->import($this->postWithCsrf(), []);
        } finally {
            unset($_FILES['csv_file']);
        }

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/import', $response->getHeaders()['Location'] ?? null);

        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Espace disque insuffisant', (string) ($flash['message'] ?? ''));

        // And nothing was deposited: the refusal runs before the write.
        $this->assertSame([], glob($this->storagePath . '/temp/import_*.csv') ?: []);
    }

    /** The same upload goes through when the budget has room, so the test above pins the refusal and not the wiring. */
    public function testImportWithRoomToSpareGetsPastTheDiskCheck(): void
    {
        $controller = ($this->rebuildController)($this->roomyBudget());

        $_FILES['csv_file'] = [
            'name' => 'effectifs.csv',
            'type' => 'text/csv',
            'tmp_name' => $this->storagePath . '/never-read.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => 4096,
        ];

        try {
            $response = $controller->import($this->postWithCsrf(), []);
        } finally {
            unset($_FILES['csv_file']);
        }

        $this->assertSame(302, $response->getStatusCode());
        $flash = \Core\Http\FlashMessage::get();
        $this->assertStringNotContainsString('Espace disque', (string) ($flash['message'] ?? ''));
    }

    /**
     * Issue #854: a refused file is laid out as a list on the import page
     * itself — the summary, then every error — rather than squeezed into
     * one flash message. 200, not a redirect: the errors are not stored
     * anywhere to be read back after one.
     */
    public function testARefusedFileListsEveryErrorOnTheImportPage(): void
    {
        $service = $this->createStub(DeskImportService::class);
        $service->method('import')->willThrowException(new \Core\Import\DeskImportRejectedException([
            new \Core\Import\DeskImportError(
                12,
                \Core\Import\DeskImportRule::BIRTH_DATE,
                'Ligne 12 (T0280) — Date de naissance « 28/11/20 » illisible : format attendu JJ/MM/AAAA.'
            ),
            new \Core\Import\DeskImportError(
                15,
                \Core\Import\DeskImportRule::GENDER,
                'Ligne 15 (T0281) — Genre « M » : valeur attendue F, H ou X.'
            ),
        ]));
        $controller = ($this->rebuildController)($this->roomyBudget(), $service);

        $_FILES['csv_file'] = [
            'name' => 'effectifs.csv',
            'type' => 'text/csv',
            'tmp_name' => $this->storagePath . '/never-read.csv',
            'error' => UPLOAD_ERR_OK,
            'size' => 4096,
        ];

        try {
            $response = $controller->import($this->postWithCsrf(), []);
        } finally {
            unset($_FILES['csv_file']);
        }

        $this->assertSame(200, $response->getStatusCode());
        $body = html_entity_decode($response->getBody(), ENT_QUOTES | ENT_HTML5);
        $this->assertStringContainsString('Import refusé : le fichier contient 2 erreurs', $body);
        $this->assertStringContainsString(
            '<li>Ligne 12 (T0280) — Date de naissance « 28/11/20 » illisible : format attendu JJ/MM/AAAA.</li>',
            $body
        );
        $this->assertStringContainsString('<li>Ligne 15 (T0281) — Genre « M » : valeur attendue F, H ou X.</li>', $body);
        // The page is still the import page, ready for the corrected file.
        $this->assertStringContainsString('enctype="multipart/form-data"', $body);
        $this->assertNull(\Core\Http\FlashMessage::get());
    }

    private function postWithCsrf(): Request
    {
        $token = bin2hex(random_bytes(32));
        $_SESSION['_csrf_token'] = $token;
        $yearId = (int) $this->pdo->query('SELECT id FROM scout_years LIMIT 1')->fetchColumn();

        return new Request(
            'POST',
            '/admin/import',
            [],
            ['_csrf_token' => $token, 'scout_year_id' => (string) $yearId],
            [],
            []
        );
    }

    /** A quota of one byte: whatever the upload weighs, it does not fit. */
    private function exhaustedBudget(): \Core\Storage\DiskBudget
    {
        return $this->budgetWithQuota('1');
    }

    private function roomyBudget(): \Core\Storage\DiskBudget
    {
        return $this->budgetWithQuota((string) (100 * 1024 * 1024 * 1024));
    }

    private function budgetWithQuota(string $quota): \Core\Storage\DiskBudget
    {
        $settings = new SettingService(new SettingRepository($this->pdo));
        $settings->register(\Core\Storage\DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');
        $settings->set(\Core\Storage\DiskBudget::QUOTA_SETTING, $quota);

        return new \Core\Storage\DiskBudget($this->storagePath, $settings);
    }
}
