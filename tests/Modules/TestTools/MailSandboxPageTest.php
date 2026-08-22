<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\View\TwigFactory;
use Modules\TestTools\Controller\MailSandboxController;
use Modules\TestTools\Repository\CapturedEmailRepository;
use Modules\TestTools\Service\MailSandboxFilters;
use Modules\TestTools\Service\MailSandboxService;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;

/**
 * The sandbox interface itself (ARCHITECTURE.md §8.63): list, search,
 * pagination, the five detail tabs, and the two properties that make the
 * HTML preview safe to look at.
 */
#[Group('database')]
class MailSandboxPageTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private MailSandboxService $sandboxService;
    private CapturedEmailRepository $repository;
    private EncryptedFileStorageService $fileStorage;
    private string $tempDir;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        TestToolsTestHelper::createTables($this->pdo);

        $encryption = TestToolsTestHelper::encryption();
        $this->repository = new CapturedEmailRepository($this->pdo, $encryption);

        $this->tempDir = sys_get_temp_dir() . '/test_tools_page_' . uniqid();
        mkdir($this->tempDir, 0700, true);

        $this->fileStorage = new EncryptedFileStorageService(
            new FileRepository($this->pdo),
            $encryption,
            $this->tempDir
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(
            MailSandboxService::SETTING_ARMED,
            '0',
            'boolean',
            'Capture des e-mails sortants',
            'Réglage de test.',
            MailSandboxService::MODULE_ID,
            null,
            null,
            false
        );

        $this->sandboxService = new MailSandboxService(
            $this->repository,
            $settingService,
            $this->fileStorage,
            new JournalService(new JournalRepository($this->pdo))
        );

        $root = dirname(__DIR__, 3);
        $this->twig = TwigFactory::create(
            $root . '/core/View/templates',
            false,
            ['test_tools' => $root . '/modules/test_tools/views']
        );
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'superadmin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/test-tools/mail-sandbox');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login(1, 'superadmin@test.com', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        self::removeDir($this->tempDir);
    }

    private function capture(
        string $subject,
        string $recipient,
        string $capturedAt,
        ?string $html = null,
        ?string $text = null,
        bool $hasDkim = false,
        string $rawMessage = "Subject: brut\r\nTo: qui@example.be\r\n\r\ncorps brut"
    ): int {
        $store = fn(?string $content, string $mime, string $name): ?int => $content === null
            ? null
            : $this->fileStorage->store($content, $mime, $name, 'test', 'superadmin', 'test_tools');

        return $this->repository->create(
            new \DateTimeImmutable($capturedAt),
            $subject,
            $recipient,
            'noreply@example.be',
            null,
            strlen($rawMessage),
            $hasDkim,
            $store($rawMessage, 'message/rfc822', 'message.eml'),
            $store($html, 'text/html', 'corps.html'),
            $store($text, 'text/plain', 'corps.txt'),
            null,
            []
        );
    }

    private function get(string $routePath, string $action, string $url): string
    {
        $router = new Router();
        $router->addRoute('GET', $routePath, MailSandboxController::class, $action, 'superadmin');

        $configFile = $this->tempDir . '/config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(
            MailSandboxController::class,
            new MailSandboxController($this->twig, $this->sandboxService)
        );

        $path = parse_url($url, PHP_URL_PATH) ?: $url;
        $queryString = parse_url($url, PHP_URL_QUERY);
        $query = [];
        if (is_string($queryString)) {
            parse_str($queryString, $query);
        }

        return $frontController->handle(new Request('GET', $path, $query, [], [], []))->getBody();
    }

    private function list(string $url = '/test-tools/mail-sandbox'): string
    {
        return $this->get('/test-tools/mail-sandbox', 'index', $url);
    }

    private function detail(int $id): string
    {
        return $this->get('/test-tools/mail-sandbox/{id}', 'detail', '/test-tools/mail-sandbox/' . $id);
    }

    public function testTheListShowsSubjectRecipientDateSizeAttachmentsAndDkim(): void
    {
        $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:30:00', hasDkim: true);

        $body = $this->list();

        $this->assertStringContainsString('[25SV] Convocation', $body);
        $this->assertStringContainsString('chef@example.be', $body);
        $this->assertStringContainsString('01/03/2026 09:30', $body);
        $this->assertStringContainsString('Ko', $body);
        $this->assertStringContainsString('DKIM', $body);
    }

    public function testSearchingBySubjectNarrowsTheList(): void
    {
        $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00');
        $this->capture('[25SV] Facture', 'tresorier@example.be', '2026-03-02 09:00:00');

        $body = $this->list('/test-tools/mail-sandbox?subject=Convoc');

        $this->assertStringContainsString('Convocation', $body);
        $this->assertStringNotContainsString('Facture', $body);
    }

    public function testSearchingByExactRecipientUsesTheBlindIndex(): void
    {
        $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00');
        $this->capture('[25SV] Facture', 'tresorier@example.be', '2026-03-02 09:00:00');

        $body = $this->list('/test-tools/mail-sandbox?recipient=tresorier@example.be');

        $this->assertStringContainsString('Facture', $body);
        $this->assertStringNotContainsString('Convocation', $body);
    }

    /**
     * The address is encrypted, so only an exact match can work — a partial
     * one has no blind index to look up and must find nothing rather than
     * quietly falling back to a scan.
     */
    public function testAPartialRecipientMatchesNothing(): void
    {
        $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00');

        $body = $this->list('/test-tools/mail-sandbox?recipient=chef');

        $this->assertStringNotContainsString('Convocation', $body);
        $this->assertStringContainsString('Aucun message ne correspond', $body);
    }

    public function testTheOptInBodySearchFindsAMessageBySubstanceAndStatesItsBound(): void
    {
        $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00', text: 'Rendez-vous au local');
        $this->capture('[25SV] Facture', 'tresorier@example.be', '2026-03-02 09:00:00', text: 'Montant dû');

        $body = $this->list('/test-tools/mail-sandbox?subject=local&bodies=1');

        $this->assertStringContainsString('Convocation', $body);
        $this->assertStringNotContainsString('Facture', $body);
        // The bound is stated in the interface, not hidden.
        $this->assertStringContainsString((string) MailSandboxFilters::BODY_SEARCH_BOUND, $body);
    }

    public function testTheBodySearchAlsoLooksAtTheHtmlHalf(): void
    {
        $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00', html: '<p>Rendez-vous au local</p>');

        $body = $this->list('/test-tools/mail-sandbox?subject=local&bodies=1');

        $this->assertStringContainsString('Convocation', $body);
    }

    public function testTheBodySearchIsOffUnlessOptedIn(): void
    {
        $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00', text: 'Rendez-vous au local');

        // Same term, no checkbox: the subject filter applies instead and
        // matches nothing.
        $body = $this->list('/test-tools/mail-sandbox?subject=local');

        $this->assertStringNotContainsString('Convocation', $body);
    }

    public function testPaginationBoundaries(): void
    {
        for ($i = 1; $i <= MailSandboxFilters::PAGE_SIZE + 3; $i++) {
            $this->capture(
                sprintf('[25SV] Message %02d', $i),
                'chef@example.be',
                (new \DateTimeImmutable('2026-03-01 09:00:00'))->modify('+' . $i . ' minutes')->format('Y-m-d H:i:s')
            );
        }

        $first = $this->list('/test-tools/mail-sandbox');
        $this->assertStringContainsString('Page 1 sur 2', $first);
        // Newest first: the last message captured heads the first page.
        $this->assertStringContainsString('[25SV] Message 28', $first);
        $this->assertStringNotContainsString('[25SV] Message 01', $first);

        $second = $this->list('/test-tools/mail-sandbox?page=2');
        $this->assertStringContainsString('Page 2 sur 2', $second);
        $this->assertStringContainsString('[25SV] Message 01', $second);

        // Past the last page: an empty slice with a way back, never an error.
        $beyond = $this->list('/test-tools/mail-sandbox?page=9');
        $this->assertStringContainsString('Cette page ne contient aucun message', $beyond);
        $this->assertStringContainsString('Revenir à la première page', $beyond);

        // Before the first page: clamped to page 1 rather than a negative offset.
        $before = $this->list('/test-tools/mail-sandbox?page=0');
        $this->assertStringContainsString('Page 1 sur 2', $before);
    }

    public function testTheEmptyStateInvitesAnAction(): void
    {
        $body = $this->list();

        $this->assertStringContainsString('Activez la capture', $body);
    }

    public function testTheArmedStateWarnsAboutMagicLinks(): void
    {
        $this->sandboxService->setArmed(true, 1);

        $body = $this->list();

        $this->assertStringContainsString('liens magiques', $body);
        $this->assertStringContainsString('passkey', $body);
    }

    public function testTheDisarmedStateCarriesNoWarning(): void
    {
        $body = $this->list();

        $this->assertStringNotContainsString('liens magiques', $body);
    }

    public function testTheDetailPageShowsTheFiveTabs(): void
    {
        $id = $this->capture(
            '[25SV] Convocation',
            'chef@example.be',
            '2026-03-01 09:00:00',
            html: '<p>Bonjour</p>',
            text: 'Bonjour en texte'
        );

        $body = $this->detail($id);

        foreach (['Aperçu HTML', 'Texte brut', 'En-têtes', 'Source MIME', 'Pièces jointes'] as $tab) {
            $this->assertStringContainsString($tab, $body, $tab);
        }
    }

    public function testTheHtmlTabRendersInASandboxedIframeAndNeverInline(): void
    {
        $id = $this->capture(
            '[25SV] Convocation',
            'chef@example.be',
            '2026-03-01 09:00:00',
            html: '<p>Bonjour</p><script>alert(1)</script>'
        );

        $body = $this->detail($id);

        $this->assertStringContainsString('<iframe srcdoc=', $body);
        $this->assertStringContainsString('sandbox', $body);
        $this->assertStringNotContainsString('allow-same-origin', $body);
        $this->assertStringNotContainsString('allow-scripts', $body);
        // The captured markup only ever appears escaped, inside the
        // attribute — never as live markup in the page.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
    }

    public function testTheTextTabShowsThePlainTextHalf(): void
    {
        $id = $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00', text: 'Bonjour en texte');

        $this->assertStringContainsString('Bonjour en texte', $this->detail($id));
    }

    public function testTheHeadersTabShowsOnlyTheHeaderBlock(): void
    {
        $id = $this->capture(
            '[25SV] Convocation',
            'chef@example.be',
            '2026-03-01 09:00:00',
            rawMessage: "Subject: brut\r\nTo: qui@example.be\r\n\r\nDANS-LE-CORPS"
        );

        $email = $this->repository->findById($id);
        $this->assertNotNull($email);

        $headers = $this->sandboxService->headers($email);
        $this->assertSame("Subject: brut\r\nTo: qui@example.be", $headers);
        $this->assertIsString($headers);
        $this->assertStringNotContainsString('DANS-LE-CORPS', $headers);
    }

    public function testTheSourceTabShowsTheWholeRawMessage(): void
    {
        $id = $this->capture(
            '[25SV] Convocation',
            'chef@example.be',
            '2026-03-01 09:00:00',
            rawMessage: "Subject: brut\r\nTo: qui@example.be\r\n\r\nDANS-LE-CORPS"
        );

        $this->assertStringContainsString('DANS-LE-CORPS', $this->detail($id));
    }

    public function testTheEmlDownloadsThroughTheFileRoute(): void
    {
        $id = $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00');
        $email = $this->repository->findById($id);
        $this->assertNotNull($email);

        $body = $this->detail($id);

        $this->assertStringContainsString('href="/files/' . $email->mimeFileId . '"', $body);
        $this->assertStringContainsString('Télécharger le .eml', $body);
    }

    public function testAttachmentsDownloadThroughTheFileRoute(): void
    {
        $fileId = $this->fileStorage->store('contenu', 'text/plain', 'note.txt', 'test', 'superadmin', 'test_tools');
        $id = $this->repository->create(
            new \DateTimeImmutable('2026-03-01 09:00:00'),
            '[25SV] Avec pièce jointe',
            'chef@example.be',
            'noreply@example.be',
            null,
            10,
            false,
            null,
            null,
            null,
            null,
            [['file_name' => 'note.txt', 'mime_type' => 'text/plain', 'size_bytes' => 7, 'file_id' => $fileId]]
        );

        $body = $this->detail($id);

        $this->assertStringContainsString('note.txt', $body);
        $this->assertStringContainsString('href="/files/' . $fileId . '"', $body);
    }

    public function testAnUnknownMessageIsA404(): void
    {
        $router = new Router();
        $router->addRoute('GET', '/test-tools/mail-sandbox/{id}', MailSandboxController::class, 'detail', 'superadmin');

        $configFile = $this->tempDir . '/config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(
            MailSandboxController::class,
            new MailSandboxController($this->twig, $this->sandboxService)
        );

        $response = $frontController->handle(new Request('GET', '/test-tools/mail-sandbox/9999', [], [], [], []));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAdminIsRejectedFromTheDetailPage(): void
    {
        $id = $this->capture('[25SV] Convocation', 'chef@example.be', '2026-03-01 09:00:00');

        AuthSession::login(1, 'admin@test.com', 'admin');

        $router = new Router();
        $router->addRoute('GET', '/test-tools/mail-sandbox/{id}', MailSandboxController::class, 'detail', 'superadmin');

        $configFile = $this->tempDir . '/config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(
            MailSandboxController::class,
            new MailSandboxController($this->twig, $this->sandboxService)
        );

        $response = $frontController->handle(new Request('GET', '/test-tools/mail-sandbox/' . $id, [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
