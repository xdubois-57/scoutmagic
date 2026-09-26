<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Controller;

use Core\Config\AppConfig;
use Core\Http\FlashMessage;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\SessionStore;
use Core\View\TwigFactory;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Controller\ConfigController;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Service\ConnectionService;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeMetaTransport;
use Tests\Modules\Social\SocialTestHelper as H;
use Twig\Environment;

/**
 * « Configuration › Réseaux sociaux »: who may reach it, and every
 * decision of the two OAuth round trips — Meta played by a fake
 * transport, the session, the settings and the row real.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private ConnectionRepository $connections;
    private RecordingJournalRepository $journal;
    private RemoteBackupSettingsDouble $settings;
    private FakeMetaTransport $meta;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($this->pdo);
        $this->connections = new ConnectionRepository($this->pdo, H::encryption());
        $this->journal = new RecordingJournalRepository();
        $this->settings = new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example/']);
        $this->meta = H::transport([]);
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $_POST = [];
    }

    // ————— Who may reach it —————

    /**
     * Every route module.json declares, driven through the real router
     * and guard: allowed to a superadmin, refused to an admin.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function routes(): array
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/social/module.json'), true);
        $cases = [];
        foreach ($manifest['routes'] as $route) {
            self::assertSame('superadmin', $route['role_min'], $route['path']);
            $cases[$route['method'] . ' ' . $route['path']] = [$route['method'], $route['path'], $route['action']];
        }

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAnAdminIsRefused(string $method, string $path, string $action): void
    {
        AuthSession::login(2, 'admin@test.be', 'admin');

        $response = $this->frontController($method, $path, $action)
            ->handle(new Request($method, str_replace('{platform}', 'facebook', $path), [], [], [], []));

        $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} must refuse an admin.");
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testASuperadminGetsThrough(string $method, string $path, string $action): void
    {
        AuthSession::login(1, 'super@test.be', 'superadmin');
        $body = $method === 'POST' ? ['_csrf_token' => $this->csrf()] : [];

        $response = $this->frontController($method, $path, $action)
            ->handle(new Request($method, str_replace('{platform}', 'facebook', $path), [], $body, [], []));

        $this->assertNotSame(403, $response->getStatusCode(), "{$method} {$path} must let a superadmin through.");
        $this->assertLessThan(400, $response->getStatusCode(), substr($response->getBody(), 0, 400));
    }

    public function testThePageShowsTheMaquettesTwoCards(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25 SV', 'P', null, new \DateTimeImmutable());
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        $this->connections->connect(
            SocialPlatform::Instagram,
            '9',
            'unite25sv',
            'T',
            new \DateTimeImmutable('+60 days'),
            new \DateTimeImmutable()
        );
        AuthSession::login(1, 'super@test.be', 'superadmin');

        $page = $this->frontController('GET', '/config/reseaux-sociaux', 'index')
            ->handle(new Request('GET', '/config/reseaux-sociaux', [], [], [], []))->getBody();

        $this->assertStringContainsString('Votre unité crée sa propre application Meta', $page);
        $this->assertStringContainsString('Unité 25 SV', $page);
        $this->assertStringContainsString('@unite25sv', $page);
        $this->assertStringContainsString('Jeton de page sans expiration.', $page);
        $this->assertStringContainsString('Compte professionnel.', $page);
        $this->assertStringContainsString('valable jusqu', $page);
        $this->assertSame(2, substr_count($page, 'Tester la connexion'));
        $this->assertSame(2, substr_count($page, 'Reconnecter'));
        $this->assertStringContainsString('https://unite.example/config/reseaux-sociaux/instagram/retour', $page);
    }

    // ————— The app's credentials —————

    public function testAnAppIdThatIsNotANumberIsRefused(): void
    {
        $response = $this->controller()->saveCredentials(
            $this->post(['app_id' => 'mon-app', 'app_secret' => 'S']),
            ['platform' => 'facebook']
        );

        $this->assertRedirectsToThePage($response);
        $this->assertNull($this->connections->find(SocialPlatform::Facebook));
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
    }

    public function testCredentialsAreSavedAndJournalledWithoutTheSecret(): void
    {
        $this->controller()->saveCredentials(
            $this->post(['app_id' => '123456', 'app_secret' => 'APP-SECRET']),
            ['platform' => 'instagram']
        );

        $this->assertSame('APP-SECRET', $this->connections->secretsOf(SocialPlatform::Instagram)->appSecret);
        $this->assertSame(1, $this->journal->countOf('credentials_saved'));
        $this->assertStringNotContainsString('APP-SECRET', $this->journal->textOf('credentials_saved'));
    }

    public function testAnUnknownPlatformIsNotFound(): void
    {
        $response = $this->controller()->saveCredentials($this->post(['app_id' => '123456']), ['platform' => 'tiktok']);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ————— Leaving for Meta, and coming back —————

    public function testConnectingNeedsTheSitesOwnAddress(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->settings->values = [];

        $response = $this->controller()->connect($this->get(), ['platform' => 'facebook']);

        $this->assertRedirectsToThePage($response);
        $this->assertStringContainsString('propre adresse', FlashMessage::get()['message'] ?? '');
    }

    public function testConnectingLeavesForMetaWithASingleUseState(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');

        $response = $this->controller()->connect($this->get(), ['platform' => 'facebook']);

        $location = ($response->getHeaders()['Location'] ?? '');
        $this->assertStringStartsWith('https://www.facebook.com/', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertSame('https://unite.example/config/reseaux-sociaux/facebook/retour', $query['redirect_uri']);
        $this->assertSame(SessionStore::get('social_oauth_state_facebook'), $query['state']);
    }

    public function testAReturnWithAnotherStateIsRefused(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        SessionStore::set('social_oauth_state_instagram', 'expected');

        $response = $this->controller()->callback(
            $this->get(['state' => 'forged', 'code' => 'C']),
            ['platform' => 'instagram']
        );

        $this->assertRedirectsToThePage($response);
        $this->assertSame([], $this->meta->requests, 'Nothing is exchanged on a forged return.');
        $this->assertNull(SessionStore::get('social_oauth_state_instagram'), 'The state is single-use.');
    }

    public function testInstagramConnectsAndTheJournalNamesNoAccount(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        SessionStore::set('social_oauth_state_instagram', 'st');
        $this->meta->answers = [
            'api.instagram.com/oauth/access_token' => H::ok(['access_token' => 'SHORT', 'user_id' => 9]),
            'ig_exchange_token' => H::ok(['access_token' => 'LONG', 'expires_in' => 5184000]),
            '/me?' => H::ok(['user_id' => '9', 'username' => 'unite25sv', 'account_type' => 'BUSINESS']),
        ];

        $this->controller()->callback($this->get(['state' => 'st', 'code' => 'C']), ['platform' => 'instagram']);

        $connection = $this->connections->find(SocialPlatform::Instagram);
        $this->assertNotNull($connection);
        $this->assertTrue($connection->isConnected());
        $this->assertSame('unite25sv', $connection->accountName);
        $this->assertNotNull($connection->tokenExpiresAt);
        $this->assertSame('LONG', $this->connections->secretsOf(SocialPlatform::Instagram)->accessToken);
        $this->assertSame(1, $this->journal->countOf('connected'));
        $this->assertStringNotContainsString('unite25sv', $this->journal->textOf('connected'));
        $this->assertStringNotContainsString('LONG', $this->journal->textOf('connected'));
    }

    public function testASingleFacebookPageIsAttachedStraightAway(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        SessionStore::set('social_oauth_state_facebook', 'st');
        $this->meta->answers = $this->facebookAnswers([['id' => '42', 'name' => 'Unité 25', 'access_token' => 'PAGE']]);

        $this->controller()->callback($this->get(['state' => 'st', 'code' => 'C']), ['platform' => 'facebook']);

        $connection = $this->connections->find(SocialPlatform::Facebook);
        $this->assertSame('42', $connection?->accountId);
        $this->assertNull($connection?->tokenExpiresAt, 'A Page token has no end.');
        $this->assertSame('PAGE', $this->connections->secretsOf(SocialPlatform::Facebook)->accessToken);
    }

    public function testSeveralPagesAreOfferedAndOnlyAnOfferedOneCanBeChosen(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        SessionStore::set('social_oauth_state_facebook', 'st');
        $pages = [
            ['id' => '41', 'name' => 'Ma page perso', 'access_token' => 'P41'],
            ['id' => '42', 'name' => 'Unité 25', 'access_token' => 'P42'],
        ];
        $this->meta->answers = $this->facebookAnswers($pages);

        $this->controller()->callback($this->get(['state' => 'st', 'code' => 'C']), ['platform' => 'facebook']);

        $this->assertFalse($this->connections->find(SocialPlatform::Facebook)?->isConnected());
        $this->assertSame('LONG-USER', $this->connections->secretsOf(SocialPlatform::Facebook)->pendingUserToken);
        $offered = SessionStore::get('social_facebook_pages');
        $this->assertIsArray($offered);
        $this->assertStringNotContainsString('P42', (string) json_encode($offered), 'No Page token in the session.');

        $this->controller()->choosePage($this->post(['page_id' => '999']), []);
        $this->assertFalse($this->connections->find(SocialPlatform::Facebook)?->isConnected());

        $this->controller()->choosePage($this->post(['page_id' => '42']), []);
        $this->assertSame('42', $this->connections->find(SocialPlatform::Facebook)?->accountId);
        $this->assertSame('P42', $this->connections->secretsOf(SocialPlatform::Facebook)->accessToken);
        $this->assertSame('', $this->connections->secretsOf(SocialPlatform::Facebook)->pendingUserToken);
    }

    public function testAReconnectionIsJournalledAsSuch(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'OLD', null, new \DateTimeImmutable());
        SessionStore::set('social_oauth_state_facebook', 'st');
        $this->meta->answers = $this->facebookAnswers([['id' => '42', 'name' => 'Unité 25', 'access_token' => 'NEW']]);

        $this->controller()->callback($this->get(['state' => 'st', 'code' => 'C']), ['platform' => 'facebook']);

        $this->assertSame(1, $this->journal->countOf('reconnected'));
        $this->assertSame(0, $this->journal->countOf('connected'));
    }

    // ————— Testing and disconnecting —————

    public function testARefusedTestIsRecordedAndJournalledWithoutTheToken(): void
    {
        $token = str_repeat('EAAB', 20);
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', $token, null, new \DateTimeImmutable());
        $this->meta->answers = ['graph.facebook.com' => ['status' => 400, 'body' => (string) json_encode(
            ['error' => ['message' => 'Invalid token ' . $token, 'code' => 190]]
        )]];

        $this->controller()->test($this->post([]), ['platform' => 'facebook']);

        $this->assertFalse($this->connections->find(SocialPlatform::Facebook)?->checkOk);
        $this->assertSame(1, $this->journal->countOf('auth_failed'));
        $this->assertStringNotContainsString($token, $this->journal->textOf('auth_failed'));
        $this->assertStringContainsString('Reconnectez', FlashMessage::get()['message'] ?? '');
    }

    public function testASuccessfulTestKeepsTheNameCurrent(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Ancien nom', 'P', null, new \DateTimeImmutable());
        $this->meta->answers = ['graph.facebook.com' => H::ok(['id' => '42', 'name' => 'Nouveau nom'])];

        $this->controller()->test($this->post([]), ['platform' => 'facebook']);

        $this->assertSame('Nouveau nom', $this->connections->find(SocialPlatform::Facebook)?->accountName);
        $this->assertTrue($this->connections->find(SocialPlatform::Facebook)?->checkOk);
    }

    public function testDisconnectingForgetsTheRow(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');

        $this->controller()->disconnect($this->post([]), ['platform' => 'instagram']);

        $this->assertNull($this->connections->find(SocialPlatform::Instagram));
        $this->assertSame(1, $this->journal->countOf('disconnected'));
    }

    public function testAPostWithoutItsCsrfTokenChangesNothing(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');

        $this->controller()->disconnect(new Request('POST', '/x', [], [], [], []), ['platform' => 'instagram']);

        $this->assertNotNull($this->connections->find(SocialPlatform::Instagram));
    }

    public function testReconnectingWithSeveralPagesShowsThePickerOverTheConnectedCard(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '41', 'Ancienne page', 'OLD', null, new \DateTimeImmutable());
        SessionStore::set('social_oauth_state_facebook', 'st');
        $this->meta->answers = $this->facebookAnswers([
            ['id' => '41', 'name' => 'Ancienne page', 'access_token' => 'P41'],
            ['id' => '42', 'name' => 'Unité 25', 'access_token' => 'P42'],
        ]);
        AuthSession::login(1, 'super@test.be', 'superadmin');

        $this->controller()->callback($this->get(['state' => 'st', 'code' => 'C']), ['platform' => 'facebook']);
        $page = $this->frontController('GET', '/config/reseaux-sociaux', 'index')
            ->handle(new Request('GET', '/config/reseaux-sociaux', [], [], [], []))->getBody();

        $this->assertStringContainsString('Quelle Page est celle de l\'unité ?', $page);
        $this->assertStringContainsString('Unité 25', $page);

        $this->controller()->choosePage($this->post(['page_id' => '42']), []);
        $this->assertSame('42', $this->connections->find(SocialPlatform::Facebook)?->accountId);
        $this->assertSame(1, $this->journal->countOf('reconnected'));
    }

    public function testMetaNotAnsweringIsNotARefusal(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'P', null, new \DateTimeImmutable());
        $this->meta->answers = ['graph.facebook.com' => ['status' => 503, 'body' => 'Service Unavailable']];

        $this->controller()->test($this->post([]), ['platform' => 'facebook']);

        $this->assertTrue($this->connections->find(SocialPlatform::Facebook)?->checkOk, 'The last verdict stands.');
        $this->assertSame(0, $this->journal->countOf('auth_failed'));
        $this->assertStringContainsString('Réessayez', FlashMessage::get()['message'] ?? '');
    }

    public function testAnExpiredTokenIsReportedWithoutCallingMeta(): void
    {
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        $this->connections->connect(
            SocialPlatform::Instagram,
            '9',
            'unite25sv',
            'T',
            new \DateTimeImmutable('-1 day'),
            new \DateTimeImmutable('-61 days')
        );

        $this->controller()->test($this->post([]), ['platform' => 'instagram']);

        $this->assertSame([], $this->meta->requests);
        $this->assertStringContainsString('expiré', FlashMessage::get()['message'] ?? '');
    }

    /**
     * @param list<array{id: string, name: string, access_token: string}> $pages
     * @return array<string, array{status: int, body: string}>
     */
    private function facebookAnswers(array $pages): array
    {
        return [
            'fb_exchange_token' => H::ok(['access_token' => 'LONG-USER']),
            'oauth/access_token' => H::ok(['access_token' => 'SHORT-USER']),
            'me/accounts' => H::ok(['data' => $pages]),
        ];
    }

    private function assertRedirectsToThePage(Response $response): void
    {
        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(ConfigController::PAGE_URL, $response->getHeaders()['Location'] ?? '');
    }

    private function controller(): ConfigController
    {
        $meta = new MetaClient($this->meta);

        return new ConfigController(
            $this->twig(),
            $this->connections,
            new ConnectionService($this->connections, new JournalService($this->journal), $meta),
            $this->settings,
            $meta
        );
    }

    private function frontController(string $method, string $path, string $action): FrontController
    {
        $router = new Router();
        $router->addRoute($method, $path, ConfigController::class, $action, 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_social_config_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $front = new FrontController($router, $this->twig(), new AppConfig($configFile));
        $front->registerController(ConfigController::class, $this->controller());

        return $front;
    }

    private function twig(): Environment
    {
        $root = dirname(__DIR__, 4);
        $twig = TwigFactory::create($root . '/core/View/templates', false, ['social' => $root . '/modules/social/views']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/config/reseaux-sociaux');
        $twig->addFunction(new \Twig\TwigFunction('param', static fn (string $key): string => 'Test Unit'));

        return $twig;
    }

    private function csrf(): string
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return $token;
    }

    /**
     * @param array<string, string> $body
     */
    private function post(array $body): Request
    {
        return new Request('POST', '/config/reseaux-sociaux/x', [], $body + ['_csrf_token' => $this->csrf()], [], []);
    }

    /**
     * @param array<string, string> $query
     */
    private function get(array $query = []): Request
    {
        return new Request('GET', '/config/reseaux-sociaux/x', $query, [], [], []);
    }
}
