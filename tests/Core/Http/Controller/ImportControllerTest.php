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
        $stmt->execute([$this->encryption->encrypt('admin@test.com')]);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = new Environment(new FilesystemLoader($templateDir), [
            'cache' => false,
            'autoescape' => 'html',
        ]);
        $twig->addGlobal('site_name', 'Test');
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
        $twig->addFunction(new \Twig\TwigFunction('editable_image', function (): string {
            return '';
        }, ['is_safe' => ['html']]));
        $twig->addFunction(new \Twig\TwigFunction('file_url', function (): string {
            return '';
        }));

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
            $memberRepo, $memberYearRepo, $importJournalRepo, $userAccountRepo
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, $memberYearRepo);

        $storagePath = sys_get_temp_dir() . '/scoutmagic_test_' . uniqid();
        mkdir($storagePath, 0755, true);

        $this->controller = new ImportController(
            $twig, $importService, $scoutYearResolver, $importJournalRepo, $functionRepo, $storagePath
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

    public function testImportRejectsMissingCsrf(): void
    {
        $request = new Request('POST', '/admin/import', [], ['_csrf_token' => 'invalid'], [], []);
        $response = $this->controller->import($request, []);

        $this->assertSame(403, $response->getStatusCode());
    }
}
