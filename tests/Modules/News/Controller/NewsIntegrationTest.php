<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Mail\MailService;
use Core\Member\SectionService;
use Core\Pdf\PosterPdfService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\Role;
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
use Modules\News\Repository\FormField;
use Modules\News\Repository\FormFieldRepository;
use Modules\News\Repository\FormRepository;
use Modules\News\Repository\FormResponseRepository;
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
 * End-to-end controller tests rendering the REAL templates (not stubs),
 * so a Twig runtime error (undefined filter/method/property) fails here
 * rather than only being caught manually in a browser.
 *
 * @group database
 */
class NewsIntegrationTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private NewsController $newsController;
    private FormController $formController;
    private ArticleRepository $articleRepository;
    private FormRepository $formRepository;
    private FormFieldRepository $fieldRepository;
    private FormResponseRepository $responseRepository;
    private int $chiefAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$this->encryption->encrypt('chief@test.com'), $this->encryption->blindIndex('chief@test.com')]);
        $this->chiefAccountId = (int) $this->pdo->lastInsertId();

        $this->articleRepository = new ArticleRepository($this->pdo);
        $this->formRepository = new FormRepository($this->pdo);
        $this->fieldRepository = new FormFieldRepository($this->pdo);
        $this->responseRepository = new FormResponseRepository($this->pdo, $this->encryption);

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo));
        $articleService = new ArticleService($this->articleRepository, $this->formRepository, $editableContentService, $shortUrlService);
        $formService = new FormService($this->formRepository, $this->fieldRepository, $articleService);

        $connection = Connection::withPdo($this->pdo);
        $roleResolver = new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $mailService = $this->createMock(MailService::class);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/news/views';
        $twig = TwigFactory::create($templateDir, false, ['news' => $moduleViews]);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/news');

        $responseService = new ResponseService(
            $this->responseRepository, $roleResolver, $sectionService, $mailService, $twig, $shortUrlService,
            'https://example.com', 'Test Unit'
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);
        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $userAccountRepository = new UserAccountRepository($this->pdo, $this->encryption);

        $this->newsController = new NewsController(
            $twig, $articleService, $formService, $responseService, new SeoKeywordService(null),
            new PosterPdfService(), $scoutYearService, $settingService, $schedulerService, $userAccountRepository
        );
        $this->formController = new FormController($twig, $articleService, $formService, $responseService, $scoutYearService);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    public function testPublicListRendersWithNoArticles(): void
    {
        $response = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aucune actualité', $response->getBody());
    }

    public function testPublicListRendersAnArticleCard(): void
    {
        $this->articleRepository->create('Camp ete', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Camp ete', $response->getBody());
    }

    public function testPublicListNeverShowsDirectLinkArticles(): void
    {
        $this->articleRepository->create('Secret', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);

        $this->assertStringNotContainsString('Secret', $response->getBody());
    }

    public function testManageListRendersForChief(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $this->articleRepository->create('Article', Article::VISIBILITY_CHIEF, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->manage(new Request('GET', '/news/manage', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Article', $response->getBody());
    }

    public function testCreateEditorPageRendersForChief(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $response = $this->newsController->create(new Request('GET', '/news/create', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Nouvel article', $response->getBody());
    }

    public function testEditEditorPageRendersWithAnExistingFormAndFields(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create('Article avec formulaire', Article::VISIBILITY_PUBLIC, true, 'mots,cles', '2027-01-01', $this->chiefAccountId);
        $this->articleRepository->setShortUrlCode($articleId, 'abc123');
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT, '2026-01-01', '2026-12-31', false, 'chief', true, null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->fieldRepository->create($formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 20, null, null);
        $this->fieldRepository->create($formId, 2, FormField::TYPE_DROPDOWN, 'Jour', false, 'manual', "Lundi\nMardi", null, null, null);
        $this->fieldRepository->create($formId, 3, FormField::TYPE_CONFIRMATION, null, false, null, null, null, null, 'Je confirme.');

        $response = $this->newsController->edit(new Request('GET', '/news/' . $articleId . '/edit', [], [], [], []), ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Article avec formulaire', $response->getBody());
        $this->assertStringContainsString('abc123', $response->getBody());
    }

    public function testEditEditorPreviewTabRendersWithAnExistingFormAndFields(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create('Article preview', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_NUMBER, 'Repas', false, null, null, 10, null, null);

        $response = $this->newsController->edit(new Request('GET', '/news/' . $articleId . '/edit', ['tab' => 'preview'], [], [], []), ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Aperçu', $response->getBody());
    }

    public function testShowRendersArticleDetailWithoutForm(): void
    {
        $id = $this->articleRepository->create('Titre article', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Titre article', $response->getBody());
    }

    public function testShowReturns403ForChiefArticleWhenPublic(): void
    {
        $id = $this->articleRepository->create('Réservé', Article::VISIBILITY_CHIEF, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowRendersOpenFormAndAcceptsASubmission(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $showResponse = $this->newsController->show(new Request('GET', '/news/' . $articleId, [], [], [], []), ['id' => (string) $articleId]);
        $this->assertStringContainsString('Envoyer', $showResponse->getBody());
        $this->assertStringContainsString('field_' . $fieldId, $showResponse->getBody());

        $csrfToken = CsrfGuard::generateToken();
        $submitRequest = new Request('POST', '/news/' . $articleId . '/form/submit', [], [
            '_csrf_token' => $csrfToken,
            'contact_email' => 'parent@test.com',
            'field_' . $fieldId => 'Alice',
        ], [], []);

        $confirmationResponse = $this->formController->submit($submitRequest, ['id' => (string) $articleId]);

        $this->assertSame(200, $confirmationResponse->getStatusCode());
        $this->assertStringContainsString('Votre réponse a été enregistrée', $confirmationResponse->getBody());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
    }

    public function testSubmitRejectsClosedFormAndRedisplaysArticle(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', false, null);

        $csrfToken = CsrfGuard::generateToken();
        $request = new Request('POST', '/news/' . $articleId . '/form/submit', [], [
            '_csrf_token' => $csrfToken,
            'contact_email' => 'parent@test.com',
        ], [], []);

        $response = $this->formController->submit($request, ['id' => (string) $articleId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('fermé', $response->getBody());
    }

    public function testResponsesPageRendersForChiefAndListsSubmission(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'parent@test.com', [$fieldId => 'Alice'], null, null);

        $response = $this->formController->responses(new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []), ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('parent@test.com', $response->getBody());
    }

    public function testResponsesPageRejectsRoleBelowResponseRoleMin(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'identified');
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);

        $response = $this->formController->responses(new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []), ['id' => (string) $articleId]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testExportResponsesReturnsAnXlsxFile(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'parent@test.com', [$fieldId => 'Alice'], null, null);

        $response = $this->formController->exportResponses(new Request('GET', '/news/' . $articleId . '/form/responses/export', [], [], [], []), ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->getHeaders()['Content-Type']);
        $this->assertStringStartsWith('PK', $response->getBody());
    }

    public function testEditResponsePageRendersForOwnerWhileOpen(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $responseId = $this->responseRepository->create($formId, 55, null, 'me@test.com', [$fieldId => 'Bob'], null, null);

        AuthSession::login(55, 'me@test.com', 'identified');

        $response = $this->formController->editResponse(
            new Request('GET', '/news/' . $articleId . '/form/responses/' . $responseId . '/edit', [], [], [], []),
            ['id' => (string) $articleId, 'response_id' => (string) $responseId]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Bob', $response->getBody());
    }

    public function testEditResponsePageRejectsADifferentAccount(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT, null, null, false, 'chief', false, null);
        $responseId = $this->responseRepository->create($formId, 55, null, 'me@test.com', [], null, null);

        AuthSession::login(56, 'other@test.com', 'identified');

        $response = $this->formController->editResponse(
            new Request('GET', '/news/' . $articleId . '/form/responses/' . $responseId . '/edit', [], [], [], []),
            ['id' => (string) $articleId, 'response_id' => (string) $responseId]
        );

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testPosterDownloadReturnsAPdf(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp d\'été', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $this->articleRepository->setShortUrlCode($id, 'abc123');

        $response = $this->newsController->poster(new Request('GET', '/news/' . $id . '/poster', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/pdf', $response->getHeaders()['Content-Type']);
        $this->assertStringStartsWith('%PDF-', $response->getBody());
    }

    public function testStoreCreatesArticleWithFormAndRedirects(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $csrfToken = CsrfGuard::generateToken();

        $fieldsJson = json_encode([
            ['id' => null, 'field_type' => 'short_text', 'label' => 'Nom', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);

        $request = new Request('POST', '/news', [], [
            '_csrf_token' => $csrfToken,
            'title' => 'Nouveau camp',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'has_form' => '1',
            'form_access' => 'public',
            'form_response_limit' => 'unlimited',
            'form_response_role_min' => 'chief',
            'fields_json' => $fieldsJson,
        ], [], []);

        $response = $this->newsController->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $this->assertSame('Nouveau camp', $article->title);
        $this->assertTrue($article->hasForm);
    }

    public function testDeleteRejectsNonAuthorChief(): void
    {
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);

        AuthSession::login(999, 'other-chief@test.com', 'chief');
        $csrfToken = CsrfGuard::generateToken();

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['DELETE', '/news/' . $id, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode(['_csrf_token' => $csrfToken]));

        $response = $this->newsController->delete($request, ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNotNull($this->articleRepository->findById($id));
    }
}
