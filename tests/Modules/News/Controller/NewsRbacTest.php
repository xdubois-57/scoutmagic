<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Mail\MailService;
use Core\Member\SectionService;
use Core\Pdf\PosterPdfService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\News\Controller\FormController;
use Modules\News\Controller\NewsController;
use Modules\News\Repository\Article;
use Modules\News\Repository\ArticleRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\NewsForm;
use Modules\News\Service\ArticleService;
use Modules\News\Service\FormService;
use Modules\News\Service\ResponseService;
use Modules\News\Service\SeoKeywordService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;
use Twig\Environment;

/**
 * RBAC boundary through the REAL Router/RbacGuard pipeline (module.json's
 * declared role_min per route) — distinct from NewsIntegrationTest, which
 * calls controller actions directly and so never exercises the guard
 * itself, only each action's own in-controller fine-grained checks.
 *
 * @group database
 */
class NewsRbacTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private NewsController $newsController;
    private FormController $formController;
    private int $chiefAccountId;
    private int $articleId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com'), $encryption->blindIndex('chief@test.com')]);
        $this->chiefAccountId = (int) $this->pdo->lastInsertId();

        $articleRepository = new ArticleRepository($this->pdo);
        $formRepository = new FormRepository($this->pdo);
        $this->articleId = $articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        // response_role_min is 'intendant' here specifically so the
        // responses/export route tests below isolate the ROUTE-level
        // role_min guard from FormController's own extra in-controller
        // check against the form's response_role_min (covered instead by
        // NewsIntegrationTest::testResponsesPageRejectsRoleBelowResponseRoleMin).
        $formRepository->create($this->articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'intendant', false, null);

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo));
        $articleService = new ArticleService($articleRepository, $formRepository, $editableContentService, $shortUrlService);
        $formService = new FormService($formRepository, new \Modules\News\Repository\FormFieldRepository($this->pdo), $articleService);

        $connection = Connection::withPdo($this->pdo);
        $roleResolver = new RoleResolver(new MemberYearRepository($this->pdo), $encryption, $this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $mailService = $this->createMock(MailService::class);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/news/views';
        $twig = TwigFactory::create($templateDir, false, ['news' => $moduleViews]);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/');
        $this->twig = $twig;

        $responseService = new ResponseService(
            new \Modules\News\Repository\FormResponseRepository($this->pdo, $encryption),
            $roleResolver, $sectionService, $mailService, $twig, $shortUrlService, 'https://example.com', 'Test Unit'
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);
        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);

        $this->newsController = new NewsController(
            $twig, $articleService, $formService, $responseService, new SeoKeywordService(null),
            new PosterPdfService(), $scoutYearService, $settingService, $schedulerService, $userAccountRepository
        );
        $this->formController = new FormController($twig, $articleService, $formService, $responseService, $scoutYearService);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    /**
     * Path templates use the literal placeholder "{id}", resolved against
     * the test's real article id inside each test method — PHPUnit builds
     * data providers before setUp() runs, so $this->articleId isn't
     * available here yet.
     *
     * @return array<string, array{string, string, string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'manage (chief)' => ['/news/manage', 'NewsController', 'manage', 'chief', 'identified'],
            'create (chief)' => ['/news/create', 'NewsController', 'create', 'chief', 'identified'],
            'poster (chief)' => ['/news/{id}/poster', 'NewsController', 'poster', 'chief', 'identified'],
            'responses (intendant)' => ['/news/{id}/form/responses', 'FormController', 'responses', 'intendant', 'identified'],
            'export (intendant)' => ['/news/{id}/form/responses/export', 'FormController', 'exportResponses', 'intendant', 'identified'],
        ];
    }

    /**
     * @dataProvider routeProvider
     */
    public function testAllowedRoleGetsThroughTheGuard(string $path, string $controllerName, string $action, string $allowedRole, string $deniedRole): void
    {
        AuthSession::login($this->chiefAccountId, 'allowed@test.com', $allowedRole);

        $response = $this->buildFrontController($path, $controllerName, $action, $allowedRole)
            ->handle(new Request('GET', $this->resolvedPath($path, ['id' => (string) $this->articleId]), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode(), "Expected non-403 for role {$allowedRole} on {$path}, got {$response->getStatusCode()}: " . $response->getBody());
    }

    /**
     * @dataProvider routeProvider
     */
    public function testRoleBelowFloorIsRejectedByTheGuard(string $path, string $controllerName, string $action, string $allowedRole, string $deniedRole): void
    {
        AuthSession::login($this->chiefAccountId, 'denied@test.com', $deniedRole);

        $response = $this->buildFrontController($path, $controllerName, $action, $allowedRole)
            ->handle(new Request('GET', $this->resolvedPath($path, ['id' => (string) $this->articleId]), [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPublicShowRouteIsReachableWithoutLogin(): void
    {
        $response = $this->buildFrontController('/news/{id}', 'NewsController', 'show', 'public')
            ->handle(new Request('GET', '/news/' . $this->articleId, [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    private function resolvedPath(string $path, array $params): string
    {
        $resolved = $path;
        foreach ($params as $key => $value) {
            $resolved = str_replace('{' . $key . '}', $value, $resolved);
        }
        return $resolved;
    }

    private function buildFrontController(string $path, string $controllerName, string $action, string $roleMin): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', $path, $this->controllerClass($controllerName), $action, $roleMin);

        $configFile = sys_get_temp_dir() . '/test_news_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController($this->controllerClass($controllerName), $this->instantiateController($controllerName));

        return $fc;
    }

    private function controllerClass(string $name): string
    {
        return "Modules\\News\\Controller\\{$name}";
    }

    private function instantiateController(string $name): object
    {
        return match ($name) {
            'NewsController' => $this->newsController,
            'FormController' => $this->formController,
            default => throw new \RuntimeException("Unknown controller {$name}"),
        };
    }
}
