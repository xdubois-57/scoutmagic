<?php

declare(strict_types=1);

namespace Tests\Modules\Social\Controller;

use Core\Config\AppConfig;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\View\TwigFactory;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Controller\CardController;
use Modules\Social\Repository\CardRepository;
use PHPUnit\Framework\TestCase;
use Tests\Core\Http\Controller\RecordingJournalRepository;
use Tests\Core\Http\Controller\RemoteBackupSettingsDouble;
use Tests\DatabaseTestHelper;
use Tests\Modules\Social\SocialTestHelper as H;

/**
 * GET /partage/carte/{token} through the real router and guard, as Meta's
 * servers reach it: anonymous.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CardControllerTest extends TestCase
{
    private CardService $cards;
    private string $directory;

    protected function setUp(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            @session_start();
        }
        AuthSession::logout();

        $pdo = DatabaseTestHelper::createTestDatabase();
        H::createTables($pdo);
        $this->directory = sys_get_temp_dir() . '/social-cards-' . bin2hex(random_bytes(4));
        $this->cards = new CardService(
            new CardRepository($pdo),
            new CardRenderer(),
            new RemoteBackupSettingsDouble([]),
            new JournalService(new RecordingJournalRepository()),
            $this->directory
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testAnAnonymousFetchGetsTheImageWithHeadersThatKeepItShortLived(): void
    {
        $card = $this->cards->issue(H::groupPhoto(), 'Camp', 'a.be', true, new \DateTimeImmutable());

        $response = $this->get($card->path);

        $this->assertSame(200, $response->getStatusCode());
        $headers = $response->getHeaders();
        $this->assertSame('image/jpeg', $headers['Content-Type'] ?? null);
        $this->assertSame('no-store', $headers['Cache-Control'] ?? null);
        $this->assertStringContainsString('noindex', $headers['X-Robots-Tag'] ?? '');
        $this->assertNotNull($response->getBodyFilePath());
    }

    public function testAnExpiredOrUnknownCardIsAPlain404(): void
    {
        $card = $this->cards->issue(H::groupPhoto(), 'Camp', 'a.be', true, new \DateTimeImmutable('-2 hours'));

        $this->assertSame(404, $this->get($card->path)->getStatusCode());
        $this->assertSame(404, $this->get(CardService::ROUTE_PREFIX . str_repeat('0', 64))->getStatusCode());
        $this->assertSame(404, $this->get(CardService::ROUTE_PREFIX . 'abc')->getStatusCode());
    }

    private function get(string $path): \Core\Http\Response
    {
        $router = new Router();
        $router->addRoute('GET', '/partage/carte/{token}', CardController::class, 'show', 'public');

        $configFile = sys_get_temp_dir() . '/test_social_card_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $root = dirname(__DIR__, 4);
        $twig = TwigFactory::create($root . '/core/View/templates', false, []);
        $twig->addGlobal('site_name', 'Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', $path);
        $twig->addFunction(new \Twig\TwigFunction('param', static fn (string $key): string => 'Test'));

        $front = new FrontController($router, $twig, new AppConfig($configFile));
        $front->registerController(CardController::class, new CardController($twig, $this->cards));

        return $front->handle(new Request('GET', $path, [], [], [], []));
    }
}
