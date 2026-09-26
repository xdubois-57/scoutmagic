<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Controller;

use Core\Config\AppConfig;
use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\Http\FlashMessage;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\TwigFactory;
use Modules\Gallery\Api\SharedAlbum;
use Modules\News\Api\SharedArticle;
use Modules\Social\Api\SocialPlatform;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Controller\ShareController;
use Modules\Social\Meta\MetaClient;
use Modules\Social\Repository\CardRepository;
use Modules\Social\Repository\ConnectionRepository;
use Modules\Social\Repository\PublicationRepository;
use Modules\Social\Service\PublishingService;
use Modules\Social\Service\ShareSourceResolver;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\FakeAlbumSource;
use Tests\Modules\Social\FakeArticleSource;
use Tests\Modules\Social\FakeMetaTransport;
use Tests\Modules\Social\SocialTestHelper as H;
use Twig\Environment;

/**
 * « Partager » an album or an article: who reaches it through the real
 * router and guard, what the page shows, and what a POST sends.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class ShareControllerTest extends TestCase
{
    public const ARTICLE_IMAGE = 11;

    private \PDO $pdo;
    private ConnectionRepository $connections;
    private PublicationRepository $publications;
    private RecordingJournalRepository $journal;
    private FakeMetaTransport $meta;
    private FakeAlbumSource $albums;
    private FakeArticleSource $articles;
    private string $directory;

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
        $this->publications = new PublicationRepository($this->pdo);
        $this->journal = new RecordingJournalRepository();
        $this->meta = H::transport([
            '/photos' => H::ok(['id' => 'P', 'post_id' => '42_1']),
            '/feed' => H::ok(['id' => '42_2']),
            '/media_publish' => H::ok(['id' => 'IG1']),
            '/media' => H::ok(['id' => 'C1']),
            'C1?' => H::ok(['status_code' => 'FINISHED']),
        ]);
        $this->albums = new FakeAlbumSource(new SharedAlbum(3, 'Camp d\'été', H::groupPhoto(), '/gallery/3'));
        $this->articles = new FakeArticleSource(
            new SharedArticle(5, 'Inscriptions', 'https://unite.example/s/abc', self::ARTICLE_IMAGE, true)
        );
        $this->directory = sys_get_temp_dir() . '/social-share-' . bin2hex(random_bytes(4));

        $now = new \DateTimeImmutable();
        $this->connections->saveCredentials(SocialPlatform::Facebook, '123456', 'S');
        $this->connections->connect(SocialPlatform::Facebook, '42', 'Unité 25', 'PAGE', null, $now);
        $this->connections->saveCredentials(SocialPlatform::Instagram, '123456', 'S');
        $this->connections->connect(SocialPlatform::Instagram, '9', 'unite25', 'IGT', $now->modify('+60 days'), $now);
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $_POST = [];
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    // ————— Who may reach it —————

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function routes(): array
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/social/module.json'), true);
        $cases = [];
        foreach ($manifest['routes'] as $route) {
            if (!str_ends_with($route['controller'], '\\ShareController')) {
                continue;
            }
            self::assertSame('chief', $route['role_min'], $route['path']);
            $cases[$route['method'] . ' ' . $route['path']] = [$route['method'], $route['path'], $route['action']];
        }
        self::assertCount(6, $cases);

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAParentIsRefused(string $method, string $path, string $action): void
    {
        AuthSession::login(8, FakeAlbumSource::MANAGER_EMAIL, 'parent');

        $response = $this->route($method, $path, $action, []);

        $this->assertSame(403, $response->getStatusCode(), "{$method} {$path} must refuse a parent.");
        $this->assertSame([], $this->meta->requests);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAChiefWhoManagesTheSourceGetsThrough(string $method, string $path, string $action): void
    {
        AuthSession::login(FakeArticleSource::EDITOR_ACCOUNT, FakeAlbumSource::MANAGER_EMAIL, 'chief');
        $body = $method === 'POST' ? ['_csrf_token' => $this->csrf()] : [];

        $response = $this->route($method, $path, $action, $body);

        $this->assertLessThan(400, $response->getStatusCode(), substr($response->getBody(), 0, 400));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routes')]
    public function testAChiefWhoDoesNotManageTheSourceGetsA404(string $method, string $path, string $action): void
    {
        AuthSession::login(99, 'autre@unite.be', 'chief');
        $body = $method === 'POST' ? ['_csrf_token' => $this->csrf(), 'destinations' => ['facebook']] : [];

        $response = $this->route($method, $path, $action, $body);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->meta->requests);
    }

    // ————— The page —————

    public function testTheAlbumPageShowsTheCardTheCaptionAndBothDestinations(): void
    {
        $this->loginManager();

        $html = $this->controller()->showAlbum($this->get(), ['id' => '3'])->getBody();

        $this->assertStringContainsString('src="/partage/album/3/apercu"', $html);
        $this->assertStringContainsString('floutée', $html);
        $this->assertStringContainsString('Les photos de « Camp d&#039;été » sont en ligne ! À voir sur unite.example/gallery/3', $html);
        $this->assertStringContainsString('data-destination="facebook" data-state="available"', $html);
        $this->assertStringContainsString('data-destination="instagram" data-state="available"', $html);
        $this->assertStringContainsString('@unite25', $html);
        $this->assertStringContainsString('ni vous ni ScoutMagic ne pourrez le reprendre', $html);
        $this->assertStringContainsString('href="/gallery/3/edit"', $html);
    }

    public function testAnArticleGoesAsALinkAndItsImageToInstagram(): void
    {
        $this->loginManager();

        $html = $this->controller()->showArticle($this->get(), ['id' => '5'])->getBody();

        $this->assertStringContainsString('en lien', $html);
        $this->assertStringContainsString('src="/partage/actualite/5/apercu"', $html);
        $this->assertStringNotContainsString('floutée', $html, 'An article image is the editor\'s choice, not blurred.');
        $this->assertStringContainsString('data-destination="instagram" data-state="available"', $html);
    }

    public function testAnArticleWithoutImageCanOnlyGoToTheFacebookPage(): void
    {
        $this->loginManager();
        $this->articles->article = new SharedArticle(5, 'Inscriptions', 'https://unite.example/s/abc', null, true);

        $html = $this->controller()->showArticle($this->get(), ['id' => '5'])->getBody();

        $this->assertStringContainsString('en lien', $html);
        $this->assertStringContainsString('data-destination="facebook" data-state="available"', $html);
        $this->assertStringContainsString('data-destination="instagram" data-state="blocked"', $html);
        $this->assertSame(404, $this->controller()->previewArticle($this->get(), ['id' => '5'])->getStatusCode());
    }

    public function testAnArticleReservedToChiefsShowsWhyItCannotLeave(): void
    {
        $this->loginManager();
        $this->articles->article = new SharedArticle(5, 'Réunion', 'https://unite.example/news/5', null, false);

        $html = $this->controller()->showArticle($this->get(), ['id' => '5'])->getBody();

        $this->assertStringContainsString('réservée aux animateurs', $html);
        $this->assertStringNotContainsString('data-state="available"', $html);
    }

    public function testThePreviewIsTheCardAsAPrivateJpeg(): void
    {
        $this->loginManager();

        $response = $this->controller()->previewAlbum($this->get(), ['id' => '3']);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('image/jpeg', $response->getHeaders()['Content-Type'] ?? null);
        $this->assertSame('private, no-store', $response->getHeaders()['Cache-Control'] ?? null);
        $size = getimagesizefromstring($response->getBody());
        $this->assertSame([1080, 1080], [$size[0] ?? 0, $size[1] ?? 0]);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_cards')->fetchColumn(), 'Nothing stored.');
    }

    public function testWithoutAnyConnectionThePageSaysSo(): void
    {
        $this->loginManager();
        $this->pdo->exec('DELETE FROM social_connections');

        $this->assertStringContainsString('Aucun compte', $this->controller()->showAlbum($this->get(), ['id' => '3'])->getBody());
    }

    // ————— Publishing —————

    public function testPublishingSendsAndThePageThenShowsItPublished(): void
    {
        $this->loginManager();

        $response = $this->controller()->publishAlbum(
            $this->post(['destinations' => ['facebook', 'instagram'], 'caption' => 'En ligne !']),
            ['id' => '3']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/partage/album/3', $response->getHeaders()['Location'] ?? '');
        $flash = FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertStringContainsString('Publié sur', $flash['message'] ?? '');
        $this->assertSame(FakeArticleSource::EDITOR_ACCOUNT, (int) $this->pdo->query(
            'SELECT user_account_id FROM social_publications LIMIT 1'
        )->fetchColumn());

        $html = $this->controller()->showAlbum($this->get(), ['id' => '3'])->getBody();
        $this->assertStringContainsString('data-destination="facebook" data-state="published"', $html);
        $this->assertStringContainsString('Déjà publié le', $html);
    }

    public function testAFailureIsShownWithItsReasonAndARetryToConfirm(): void
    {
        $this->loginManager();
        $this->meta->answers = ['/media' => ['status' => 400, 'body' => (string) json_encode(
            ['error' => ['message' => 'Only photo or video can be accepted', 'code' => 9004]]
        )]] + $this->meta->answers;

        $this->controller()->publishAlbum($this->post(['destinations' => ['facebook', 'instagram']]), ['id' => '3']);

        $this->assertSame('warning', FlashMessage::get()['type'] ?? null);
        $html = $this->controller()->showAlbum($this->get(), ['id' => '3'])->getBody();
        $this->assertStringContainsString('data-destination="instagram" data-state="failed"', $html);
        $this->assertStringContainsString('name="retry[]" value="instagram"', $html);
        $this->assertStringNotContainsString('name="retry[]" value="facebook"', $html);
    }

    public function testTickingOnlyTheRetryConfirmationRetriesThatDestination(): void
    {
        $this->loginManager();
        $failing = $this->meta->answers;
        $this->meta->answers = ['/media' => ['status' => 400, 'body' => (string) json_encode(
            ['error' => ['message' => 'Only photo or video can be accepted', 'code' => 9004]]
        )]] + $failing;
        $this->controller()->publishAlbum($this->post(['destinations' => ['instagram']]), ['id' => '3']);
        $this->meta->answers = $failing;

        $this->controller()->publishAlbum($this->post(['retry' => ['instagram']]), ['id' => '3']);

        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
        $this->assertTrue($this->publications->forSource('album', 3)['instagram']->isPublished());
    }

    public function testNoDestinationTickedPublishesNothing(): void
    {
        $this->loginManager();

        $this->controller()->publishArticle($this->post(['destinations' => ['nowhere']]), ['id' => '5']);

        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->meta->requests);
    }

    public function testAPostWithoutItsCsrfTokenSendsNothing(): void
    {
        $this->loginManager();
        $_POST['_csrf_token'] = 'forged';

        $this->controller()->publishAlbum(
            new Request('POST', '/partage/album/3', [], ['destinations' => ['facebook'], '_csrf_token' => 'forged'], [], []),
            ['id' => '3']
        );

        $this->assertSame([], $this->meta->requests);
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM social_publications')->fetchColumn());
    }

    private function loginManager(): void
    {
        AuthSession::login(FakeArticleSource::EDITOR_ACCOUNT, FakeAlbumSource::MANAGER_EMAIL, 'chief');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function route(string $method, string $path, string $action, array $body): Response
    {
        $router = new Router();
        $router->addRoute($method, $path, ShareController::class, $action, 'chief');

        $configFile = sys_get_temp_dir() . '/test_social_share_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $front = new FrontController($router, $this->twig(), new AppConfig($configFile));
        $front->registerController(ShareController::class, $this->controller());
        $concrete = str_replace('{id}', str_contains($path, 'album') ? '3' : '5', $path);

        return $front->handle(new Request($method, $concrete, [], $body, [], []));
    }

    private function controller(): ShareController
    {
        $settings = new RemoteBackupSettingsDouble(['base_url' => 'https://unite.example']);
        $journal = new JournalService($this->journal);
        $cards = new CardService(new CardRepository($this->pdo), new CardRenderer(), $settings, $journal, $this->directory);
        $storage = sys_get_temp_dir();
        $files = new FileRepository($this->pdo);
        $reader = new class ($files, new EncryptedFileStorageService($files, H::encryption(), $storage), $storage) extends StoredFileReader {
            public function read(int $fileId): ?string
            {
                return $fileId === ShareControllerTest::ARTICLE_IMAGE ? H::groupPhoto() : null;
            }
        };

        return new ShareController(
            $this->twig(),
            new ShareSourceResolver(
                $settings,
                $reader,
                $this->albums,
                $this->articles
            ),
            new PublishingService(
                $this->connections,
                $this->publications,
                $cards,
                $settings,
                $journal,
                new MetaClient($this->meta, static function (int $seconds): void {
                })
            ),
            $this->publications,
            $this->connections,
            $cards,
            $settings
        );
    }

    private function twig(): Environment
    {
        $root = dirname(__DIR__, 4);
        $twig = TwigFactory::create($root . '/core/View/templates', false, ['social' => $root . '/modules/social/views']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/partage/album/3');
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
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        return new Request('POST', '/partage/x', [], $body + ['_csrf_token' => $this->csrf()], [], []);
    }

    private function get(): Request
    {
        return new Request('GET', '/partage/x', [], [], [], []);
    }
}
