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
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ImportControllerTest extends TestCase
{
    private ImportController $controller;
    private \PDO $pdo;
    private EncryptionService $encryption;

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
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");

        // Create admin user
        $stmt = $this->pdo->prepare("INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, 'admin_idx', 1)");
        $stmt->execute([$this->encryption->encrypt('admin@test.com', 'user_accounts.email')]);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $twig->addGlobal('site_name', 'Test');
        // The shared French format filters (core/View/TwigFactory.php) used by
        // the templates under test - same rendering as the shipped ones.
        $twig->addFilter(new \Twig\TwigFilter('date_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')));
        $twig->addFilter(new \Twig\TwigFilter('datetime_fr', fn($d) => $d === null || $d === '' ? '' : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')));
        $twig->addFilter(new \Twig\TwigFilter('money', fn($a) => $a === null || $a === '' ? '' : number_format((float) $a, 2, ',', ' ') . ' €'));
        $twig->addFilter(new \Twig\TwigFilter('money_cents', fn($c) => $c === null || $c === '' ? '' : number_format(((int) $c) / 100, 2, ',', ' ') . ' €'));
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'admin@test.com');
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

        $twig->addFunction(new \Twig\TwigFunction('csrf_field', function (): string {
            return '<input type="hidden" name="_csrf_token" value="test">';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('get_flash', function (): ?array {
            return null;
        }));
        $twig->addFunction(new \Twig\TwigFunction('csrf_token', function (): string {
            return 'test';
        }));
        $twig->addFunction(new \Twig\TwigFunction('editable', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        // The shared person avatar (Core\View\PersonAvatar), registered here
        // the way Core\View\TwigFactory does with no photo service: same
        // markup as production for an account that has set no photo.
        $twig->addFunction(new \Twig\TwigFunction('person_avatar', function (string $name, array $options = []): string {
            return \Core\View\PersonAvatar::render($name, null, (int) ($options['size'] ?? 40));
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));
        // The report names people the way every other admin screen does
        // (design.md § Display name convention) — registered here as
        // Core\View\TwigFactory does in production.
        $twig->addFilter(new \Twig\TwigFilter('display_name_full', function ($member): string {
            $full = trim(($member['first_name'] ?? '') . ' ' . ($member['last_name'] ?? ''));

            return ($member['totem'] ?? null) ? $member['totem'] . ' (' . $full . ')' : $full;
        }));

        $storagePath = sys_get_temp_dir() . '/scoutmagic_test_' . bin2hex(random_bytes(8));
        mkdir($storagePath, 0755, true);

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
        $importService = new DeskImportService(
            $this->pdo, $this->encryption, $parser, $mappingResolver,
            $memberRepo, $memberYearRepo, $importJournalRepo, $userAccountRepo,
            new UnitStaffSectionService($this->pdo),
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
            new \Core\Import\ImportDiffCalculator(new \Core\Import\RosterSnapshotRepository($this->pdo))
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);
        $fileRepository = new \Core\File\FileRepository($this->pdo);
        $rosterSnapshotRepo = new \Core\Import\RosterSnapshotRepository($this->pdo);

        $this->controller = new ImportController(
            $twig,
            $importService,
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
            $storagePath
        );
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
        $this->assertStringContainsString('2025-2026', $body);
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
}
