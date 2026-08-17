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
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Pdf\PosterPdfService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\HumanCheck\HumanCheckService;
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

/**
 * Core\Security\HumanCheck integration on the news module's public form
 * response (POST /news/{id}/form/submit, ARCHITECTURE.md §8's iteration-1
 * spec, integration point 2) — full end-to-end: renders the real article
 * page for an anonymous visitor, then submits as a human (after the
 * minimum delay) and as a simulated bot (honeypot filled).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class NewsFormHumanCheckTest extends TestCase
{
    private \PDO $pdo;
    private ArticleRepository $articleRepository;
    private FormRepository $formRepository;
    private FormFieldRepository $fieldRepository;
    private NewsController $newsController;
    private FormController $formController;
    private int $chiefAccountId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([$encryption->encrypt('chief@test.com'), $encryption->blindIndex('chief@test.com')]);
        $this->chiefAccountId = (int) $this->pdo->lastInsertId();

        $this->articleRepository = new ArticleRepository($this->pdo);
        $this->formRepository = new FormRepository($this->pdo);
        $this->fieldRepository = new FormFieldRepository($this->pdo);
        $responseRepository = new FormResponseRepository($this->pdo, $encryption);

        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $shortUrlService = new ShortUrlService(new ShortUrlRepository($this->pdo));
        $articleService = new ArticleService($this->articleRepository, $this->formRepository, $editableContentService, $shortUrlService);
        $formService = new FormService($this->formRepository, $this->fieldRepository, $articleService);

        $connection = Connection::withPdo($this->pdo);
        $roleResolver = new RoleResolver(new MemberYearRepository($this->pdo), $encryption, $this->pdo);
        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
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
            $responseRepository, $roleResolver, $sectionService, $mailService, $twig, $shortUrlService,
            'https://example.com', 'Test Unit'
        );

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('human_check_min_delay_seconds', '2', 'number', 'Délai minimum', 'Description de test.');
        $settingService->register('human_check_form_validity_seconds', '100', 'number', 'Durée de validité', 'Description de test.');
        $scoutYearService = new ScoutYearService($this->pdo);
        $schedulerService = new SchedulerService(new SchedulerRepository($this->pdo));
        $userAccountRepository = new UserAccountRepository($this->pdo, $encryption);
        $memberService = new MemberService(new MemberYearRepository($this->pdo), $encryption, $connection);
        $uploadHandler = new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir());
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $humanCheck = new HumanCheckService(
            $encryption,
            new HumanCheckRateLimitRepository($this->pdo),
            $settingService,
            $journalService
        );

        $this->newsController = new NewsController(
            $twig, $articleService, $formService, $responseService, new SeoKeywordService(null),
            new PosterPdfService(), $scoutYearService, $settingService, $schedulerService, $userAccountRepository,
            $memberService, $sectionService, $uploadHandler, new FileRepository($this->pdo), sys_get_temp_dir(), $journalService,
            null, $humanCheck
        );
        $this->formController = new FormController(
            $twig, $articleService, $formService, $responseService, $scoutYearService, $journalService,
            null, $humanCheck
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * @return array{0: int, 1: int} [$articleId, $fieldId]
     */
    private function createOpenPublicForm(): array
    {
        $articleId = $this->articleRepository->create('Camp', Article::VISIBILITY_PUBLIC, false, null, null, $this->chiefAccountId);
        $formId = $this->formRepository->create($articleId, NewsForm::ACCESS_PUBLIC, NewsForm::RESPONSE_LIMIT_UNLIMITED, null, null, false, 'chief', false, null);
        $fieldId = $this->fieldRepository->create($formId, 0, FormField::TYPE_SHORT_TEXT, 'Nom', true, null, null, null, null, null);

        return [$articleId, $fieldId];
    }

    /**
     * @return array<string, string>
     */
    private function humanCheckFields(int $articleId): array
    {
        $showResponse = $this->newsController->show(new Request('GET', '/news/' . $articleId, [], [], [], []), ['id' => (string) $articleId]);
        $body = $showResponse->getBody();

        preg_match('/name="human_check_token" value="([^"]+)"/', $body, $tokenMatch);
        preg_match('/class="hc-trap"[^>]*>\s*<label[^>]*>[^<]*<\/label>\s*<input[^>]*id="([^"]+)"/', $body, $trapMatch);

        $this->assertNotEmpty($tokenMatch, 'Article page must render a human_check_token field for an anonymous visitor');
        $this->assertNotEmpty($trapMatch, 'Article page must render the honeypot trap field');

        return [
            'human_check_token' => $tokenMatch[1],
            $trapMatch[1] => '',
        ];
    }

    public function testHumanSubmissionAfterTheDelayIsAccepted(): void
    {
        [$articleId, $fieldId] = $this->createOpenPublicForm();
        $fields = $this->humanCheckFields($articleId);

        sleep(2);

        $request = new Request('POST', '/news/' . $articleId . '/form/submit', [], array_merge([
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'parent@test.com',
            'field_' . $fieldId => 'Alice',
        ], $fields), [], []);

        $response = $this->formController->submit($request, ['id' => (string) $articleId]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
    }

    public function testBotFillingTheHoneypotIsRejectedAndRedisplaysTheFormWithoutCreatingAResponse(): void
    {
        [$articleId, $fieldId] = $this->createOpenPublicForm();
        $fields = $this->humanCheckFields($articleId);
        $trapFieldName = array_key_last($fields);
        $fields[$trapFieldName] = 'I am a bot';

        $request = new Request('POST', '/news/' . $articleId . '/form/submit', [], array_merge([
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'bot@test.com',
            'field_' . $fieldId => 'Alice',
        ], $fields), [], []);

        $response = $this->formController->submit($request, ['id' => (string) $articleId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
        // Same generic message regardless of which barrier tripped —
        // never a dead-end error page, the form is redisplayed.
        $this->assertStringContainsString('Une erreur est survenue', $response->getBody());
        $this->assertStringContainsString('field_' . $fieldId, $response->getBody());
    }

    public function testBotSubmittingInstantlyIsRejectedAndRedisplaysTheFormWithoutCreatingAResponse(): void
    {
        [$articleId, $fieldId] = $this->createOpenPublicForm();
        $fields = $this->humanCheckFields($articleId);

        // No sleep() — submitted the same instant the challenge was
        // rendered, well under the 2-second minimum delay.
        $request = new Request('POST', '/news/' . $articleId . '/form/submit', [], array_merge([
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'bot@test.com',
            'field_' . $fieldId => 'Alice',
        ], $fields), [], []);

        $response = $this->formController->submit($request, ['id' => (string) $articleId]);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn());
        $this->assertStringContainsString('Une erreur est survenue', $response->getBody());
    }
}
