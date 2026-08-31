<?php

declare(strict_types=1);

namespace Tests\Modules\Attestations\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Modules\Attestations\Controller\AttestationsController;
use Modules\Attestations\Controller\BatchController;
use Modules\Attestations\Repository\BatchLineRepository;
use Modules\Attestations\Repository\BatchRepository;
use Modules\Attestations\Repository\MemberNameRepository;
use Modules\Attestations\Service\AttestationPdfReader;
use Modules\Attestations\Service\AttestationPdfSplitter;
use Modules\Attestations\Service\BatchDepositService;
use Core\Scheduler\SchedulerService;
use Modules\Attestations\Service\BatchPublicationService;
use Modules\Attestations\Service\BatchVerificationService;
use Modules\Attestations\Service\DuplicateDetector;
use Modules\Attestations\Value\AttestationCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Attestations\AttestationsTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFilter;
use Twig\TwigFunction;

/**
 * The RBAC boundary of every attestations route, exercised through the real
 * Router/RbacGuard/FrontController stack and the real templates — so a
 * template that fails to compile fails here rather than in production.
 *
 * Every route is `role_min: admin`, the floor of the Espace chefs d'U menu.
 * A `chief`, one level below, is refused on every one of them: an animateur
 * de section has no business opening a file holding the whole unit's
 * nominative paperwork.
 */
#[Group('database')]
class AttestationsRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private string $storageRoot;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        AttestationsTestHelper::createTables($this->pdo);
        $this->storageRoot = AttestationsTestHelper::createStorageRoot();
        $this->scoutYearId = AttestationsTestHelper::createScoutYear($this->pdo);
        $this->twig = $this->buildTwig();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_FILES = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $_FILES = [];
        AttestationsTestHelper::removeDirectory($this->storageRoot);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'deposit page' => ['GET', '/admin/attestations'],
            'deposit' => ['POST', '/admin/attestations'],
            'batch' => ['GET', '/admin/attestations/1'],
            'assign' => ['POST', '/admin/attestations/1/rattacher'],
            'publish' => ['POST', '/admin/attestations/1/publier'],
            'notify' => ['POST', '/admin/attestations/1/prevenir'],
            'reset' => ['POST', '/admin/attestations/1/reprendre'],
            'coverage' => ['GET', '/admin/attestations/couverture'],
        ];
    }

    #[DataProvider('routeProvider')]
    public function testASectionChiefIsRefusedOnEveryRoute(string $method, string $path): void
    {
        AuthSession::login(2, 'animateur@test.be', 'chief');

        $response = $this->frontController()->handle(new Request($method, $path, [], [], [], []));

        $this->assertSame(403, $response->getStatusCode(), $method . ' ' . $path);
    }

    #[DataProvider('routeProvider')]
    public function testAnAnonymousVisitorIsSentToTheLoginPage(string $method, string $path): void
    {
        $response = $this->frontController()->handle(new Request($method, $path, [], [], [], []));

        $this->assertSame(302, $response->getStatusCode(), $method . ' ' . $path);
        $this->assertStringStartsWith('/login', (string) $response->getHeaders()['Location']);
    }

    public function testTheUnitStaffReachesTheDepositPage(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $response = $this->frontController()->handle(new Request('GET', '/admin/attestations', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    }

    public function testAUnitWithNoBatchSeesAnEmptyStateAndTheDepositForm(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $body = (string) $this->frontController()
            ->handle(new Request('GET', '/admin/attestations', [], [], [], []))
            ->getBody();

        $this->assertStringContainsString('Aucun lot n&#039;a encore été déposé.', $body);
        $this->assertStringContainsString('name="pdf_file"', $body);
        $this->assertStringContainsString('name="scout_year_id"', $body);
        $this->assertStringContainsString('name="category"', $body);
        $this->assertStringContainsString('name="label"', $body);
        $this->assertStringContainsString('_csrf_token', $body);
    }

    /**
     * The year in progress is prefilled, and it is the CURRENT one rather
     * than the public one: the public year lags behind during a transition,
     * and a chef d'unité depositing a file in February is working in the
     * season they are living in. It stays a choice — a certificate covering
     * the year just gone is routinely filed under the one in progress, and
     * the site deduces nothing from the file itself.
     */
    public function testTheDepositFormPreselectsTheCurrentScoutYear(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $currentYearId = (int) (new ScoutYearService($this->pdo))->getCurrentYear()['id'];
        $otherYearId = AttestationsTestHelper::createScoutYear($this->pdo, '2019-2020');

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController()
            ->handle(new Request('GET', '/admin/attestations', [], [], [], []))
            ->getBody());

        $this->assertMatchesRegularExpression(
            '#<option value="' . $currentYearId . '"[^>]*selected#',
            $body
        );
        $this->assertDoesNotMatchRegularExpression(
            '#<option value="' . $otherYearId . '"[^>]*selected#',
            $body
        );
    }

    public function testADepositedBatchIsListedAndLinksToItsVerificationScreen(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $batchId = (new BatchRepository(Connection::withPdo($this->pdo)))->create(
            $this->scoutYearId,
            AttestationCategory::Tax,
            'Attestation fiscale 2025',
            10,
            2,
            5,
            1
        );

        $body = (string) preg_replace('/\s+/', ' ', (string) $this->frontController()
            ->handle(new Request('GET', '/admin/attestations', [], [], [], []))
            ->getBody());

        $this->assertStringContainsString('Attestation fiscale 2025', $body);
        $this->assertStringContainsString('href="/admin/attestations/' . $batchId . '"', $body);
        $this->assertStringContainsString('À vérifier', $body);
    }

    // --- depositing -----------------------------------------------------

    public function testDepositingTheGoldenFixtureRedirectsToItsVerificationScreen(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');
        AttestationsTestHelper::createMember($this->pdo, $this->scoutYearId, 'Margaux', 'Vandenbrande');

        $response = $this->post(AttestationsTestHelper::goldenFixturePath(), [
            'scout_year_id' => (string) $this->scoutYearId,
            'category' => AttestationCategory::Tax->value,
            'label' => 'Attestation fiscale 2025',
        ]);

        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesRegularExpression(
            '#^/admin/attestations/\d+$#',
            (string) $response->getHeaders()['Location']
        );
    }

    /**
     * The deposited file holds every family's certificate in one document
     * and has no use once the pieces are out of it. It goes in a `finally`,
     * so neither a success nor a crash can leave it on disk.
     */
    public function testTheDepositedFileIsNotKept(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $this->post(AttestationsTestHelper::goldenFixturePath(), [
            'scout_year_id' => (string) $this->scoutYearId,
            'category' => AttestationCategory::Tax->value,
            'label' => 'Attestation fiscale 2025',
        ]);

        $this->assertSame([], glob($this->storageRoot . '/temp/*') ?: []);
    }

    /**
     * The refusal is a state on the page, not a flash: it has three numbers
     * and a subtraction to show. A reader who only sees « le découpage a
     * échoué » redeposits the same file.
     */
    public function testAPageCountThatIsNotAMultipleRendersTheRefusalWithItsNumbers(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $pages = [];
        for ($i = 0; $i < 3; $i++) {
            $pages[] = ['ATTESTATION FISCALE', 'Personne ' . $i];
            $pages[] = ['ANNEXE', 'suite'];
        }
        $pages[] = ['ATTESTATION FISCALE', 'orpheline'];
        $path = AttestationsTestHelper::writeTemporaryPdf($pages);

        try {
            $response = $this->post($path, [
                'scout_year_id' => (string) $this->scoutYearId,
                'category' => AttestationCategory::Tax->value,
                'label' => 'Attestation fiscale 2025',
            ]);

            $body = (string) preg_replace('/\s+/', ' ', (string) $response->getBody());

            $this->assertSame(200, $response->getStatusCode());
            $this->assertStringContainsString('Découpage impossible', $body);
            // Static template text, so it is not escaped — the numbers are
            // what matter, and a reader who sees the remainder goes back to
            // the federation instead of redepositing the same file.
            $this->assertStringContainsString("7 n'est pas un multiple de 2", $body);
            $this->assertStringContainsString('reste 1', $body);
            // And nothing was produced.
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM attestation_batches')->fetchColumn());
        } finally {
            @unlink($path);
        }
    }

    public function testAFileThatIsNotAPdfIsRefused(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $path = sys_get_temp_dir() . '/attestations_not_pdf_' . bin2hex(random_bytes(4)) . '.pdf';
        file_put_contents($path, 'Ceci n\'est pas un PDF.');

        try {
            $response = $this->post($path, [
                'scout_year_id' => (string) $this->scoutYearId,
                'category' => AttestationCategory::Tax->value,
                'label' => 'Attestation fiscale 2025',
            ]);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM attestation_batches')->fetchColumn());
        } finally {
            @unlink($path);
        }
    }

    public function testADepositWithNoLabelIsRefusedBeforeTheFileIsEvenRead(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $response = $this->post(AttestationsTestHelper::goldenFixturePath(), [
            'scout_year_id' => (string) $this->scoutYearId,
            'category' => AttestationCategory::Tax->value,
            'label' => '   ',
        ]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM attestation_batches')->fetchColumn());
    }

    public function testADepositWithoutAValidCsrfTokenIsRefused(): void
    {
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $response = $this->post(
            AttestationsTestHelper::goldenFixturePath(),
            [
                'scout_year_id' => (string) $this->scoutYearId,
                'category' => AttestationCategory::Tax->value,
                'label' => 'Attestation fiscale 2025',
            ],
            false
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM attestation_batches')->fetchColumn());
    }

    // --- harness --------------------------------------------------------

    /**
     * @param array<string, string> $body
     */
    private function post(string $pdfPath, array $body, bool $validCsrf = true): \Core\Http\Response
    {
        $copy = sys_get_temp_dir() . '/attestations_upload_' . bin2hex(random_bytes(6)) . '.pdf';
        copy($pdfPath, $copy);

        $_FILES['pdf_file'] = [
            'name' => 'attestations.pdf',
            'type' => 'application/pdf',
            'tmp_name' => $copy,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($copy),
        ];

        $body['_csrf_token'] = $validCsrf ? \Core\Security\CsrfGuard::generateToken() : 'invalide';

        try {
            return $this->frontController()->handle(
                new Request('POST', '/admin/attestations', [], $body, [], [])
            );
        } finally {
            @unlink($copy);
        }
    }

    private function frontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/admin/attestations', AttestationsController::class, 'index', 'admin');
        $router->addRoute('POST', '/admin/attestations', AttestationsController::class, 'store', 'admin');
        $router->addRoute('GET', '/admin/attestations/{id}', BatchController::class, 'show', 'admin');
        $router->addRoute('POST', '/admin/attestations/{id}/rattacher', BatchController::class, 'assign', 'admin');
        $router->addRoute('POST', '/admin/attestations/{id}/publier', BatchController::class, 'publish', 'admin');
        $router->addRoute('POST', '/admin/attestations/{id}/prevenir', BatchController::class, 'notify', 'admin');
        $router->addRoute('POST', '/admin/attestations/{id}/reprendre', BatchController::class, 'resetBatch', 'admin');
        // Before the {id} route, as the manifest declares it — {id} matches
        // digits only, so the two could not collide, but the reader checks
        // the order first.
        $router->addRoute('GET', '/admin/attestations/couverture', \Modules\Attestations\Controller\CoverageController::class, 'index', 'admin');

        $configFile = sys_get_temp_dir() . '/test_attestations_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));

        $connection = Connection::withPdo($this->pdo);
        $encryption = AttestationsTestHelper::encryption();
        $files = new FileRepository($this->pdo);
        $fileStorage = new EncryptedFileStorageService($files, $encryption, $this->storageRoot);
        $batches = new BatchRepository($connection);
        $lines = new BatchLineRepository($connection, $encryption);
        $members = new MemberNameRepository($connection, $encryption);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $scoutYears = new ScoutYearService($this->pdo);
        $resolver = new ScoutYearResolver(
            $scoutYears,
            new SettingService(new SettingRepository($this->pdo)),
            new MemberYearRepository($this->pdo)
        );

        $frontController->registerController(
            AttestationsController::class,
            new AttestationsController(
                $this->twig,
                $batches,
                $scoutYears,
                $resolver,
                new BatchDepositService(
                    $connection,
                    $batches,
                    $lines,
                    $members,
                    new AttestationPdfReader(),
                    new AttestationPdfSplitter(),
                    $fileStorage,
                    $journal
                ),
                $journal,
                $this->storageRoot
            )
        );

        $frontController->registerController(
            \Modules\Attestations\Controller\CoverageController::class,
            new \Modules\Attestations\Controller\CoverageController(
                $this->twig,
                new \Modules\Attestations\Service\CoverageService($members, $lines),
                $resolver,
                $scoutYears
            )
        );

        $frontController->registerController(
            BatchController::class,
            new BatchController(
                $this->twig,
                $batches,
                $lines,
                $members,
                $attestationVerification = new BatchVerificationService(
                    $connection, $batches, $lines, $files, $fileStorage, $journal
                ),
                new BatchPublicationService(
                    $connection,
                    $batches,
                    $lines,
                    $attestationVerification,
                    new \Core\Member\MemberDocumentRepository($this->pdo),
                    SchedulerService::forPdo($this->pdo),
                    $journal
                ),
                new \Modules\Attestations\Service\BatchResetService(
                    $connection,
                    $batches,
                    $lines,
                    new \Core\Member\MemberDocumentRepository($this->pdo),
                    $fileStorage,
                    $journal
                ),
                new DuplicateDetector($lines),
                $scoutYears
            )
        );

        return $frontController;
    }

    private function buildTwig(): Environment
    {
        $loader = new FilesystemLoader(dirname(__DIR__, 4) . '/core/View/templates');
        $loader->addPath(dirname(__DIR__, 4) . '/modules/attestations/views', 'attestations');

        $twig = new Environment($loader, ['cache' => false, 'autoescape' => 'html']);
        $twig->addFunction(new TwigFunction('asset', static fn(string $path): string => $path));
        $twig->addFilter(new TwigFilter(
            'date_fr',
            static fn($d) => $d === null || $d === ''
                ? ''
                : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y')
        ));
        $twig->addFilter(new TwigFilter(
            'datetime_fr',
            static fn($d) => $d === null || $d === ''
                ? ''
                : ($d instanceof \DateTimeInterface ? $d : new \DateTimeImmutable((string) $d))->format('d/m/Y à H:i')
        ));

        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_email', 'test@test.be');
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $twig->addFunction(new TwigFunction('csrf_field', static fn(): string => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $twig->addFunction(new TwigFunction('csrf_token', static fn(): string => 'test'));
        $twig->addFunction(new TwigFunction('get_flash', static fn() => null));
        $twig->addFunction(new TwigFunction('file_url', static fn(): string => ''));

        return $twig;
    }
}
