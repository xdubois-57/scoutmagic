<?php

declare(strict_types=1);

namespace Tests\Modules\News\Controller;

use Core\Badge\MemberBadgeRepository;
use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;
use Core\Member\SectionService;
use Core\Pdf\PosterPdfService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\RoleResolver;
use Core\Security\UserAccountRepository;
use Core\Url\ShortUrlRepository;
use Core\Url\ShortUrlService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
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
use Modules\News\Service\NewsException;
use Modules\News\Service\ResponseService;
use Modules\News\Service\SeoKeywordService;
use Modules\News\Service\TicketService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\News\NewsTestHelper;
use Tests\RequestWithInput;
use Tests\TestTwig;
use Twig\Environment;

/**
 * The « Importé » lock (chantier « Migration de données », D11): an article
 * brought over from the previous site, its form and its responses are
 * read-only for everybody, refused BY THE SERVER on every route that would
 * change them — not only hidden — and shown with the badge and the banner
 * of the maquette.
 *
 * Nothing of the data migration module is built here, as nothing of it is
 * on a site where it is disabled: the lock is the article's own
 * `imported_at` column, read by the news module alone.
 */
#[Group('database')]
#[\PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations]
final class ImportedArticleLockTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private Environment $twig;
    private ArticleRepository $articles;
    private FormRepository $forms;
    private FormFieldRepository $fields;
    private FormResponseRepository $responses;
    private ArticleService $articleService;
    private FormService $formService;
    private ResponseService $responseService;
    private NewsController $newsController;
    private FormController $formController;
    private int $adminId;
    private int $importedId;
    private int $formId;
    /** @var list<int> */
    private array $fieldIds = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        NewsTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)')
            ->execute([$this->encryption->encrypt('admin@test.com', 'user_accounts.email'), $this->encryption->blindIndex('admin@test.com', 'email')]);
        $this->adminId = (int) $this->pdo->lastInsertId();

        $this->articles = new ArticleRepository($this->pdo);
        $this->forms = new FormRepository($this->pdo);
        $this->fields = new FormFieldRepository($this->pdo);
        $this->responses = new FormResponseRepository($this->pdo, $this->encryption);

        $shortUrls = new ShortUrlService(new ShortUrlRepository($this->pdo, $this->encryption));
        $this->articleService = new ArticleService(
            $this->articles,
            $this->forms,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $shortUrls,
            new FileRepository($this->pdo)
        );
        $this->formService = new FormService($this->forms, $this->fields, $this->articleService, $this->responses);

        $connection = Connection::withPdo($this->pdo);
        $sections = new SectionService(
            new SectionRepository($connection),
            new MemberProfileRepository($connection, $this->encryption, new MemberBadgeRepository($this->pdo))
        );
        $this->twig = TestTwig::create(['news']);
        $this->twig->addGlobal('site_name', 'Test Unit');
        $this->twig->addGlobal('is_authenticated', true);
        $this->twig->addGlobal('current_user_role', 'admin');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/news');

        $this->responseService = new ResponseService(
            $this->responses,
            new RoleResolver(new MemberYearRepository($this->pdo), $this->encryption, $this->pdo),
            $sections,
            $this->createStub(MailService::class),
            EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'news'),
            $shortUrls,
            'https://example.com',
            'Test Unit'
        );
        $journal = $this->createStub(JournalService::class);
        $this->newsController = new NewsController(
            $this->twig,
            $this->articleService,
            $this->formService,
            $this->responseService,
            new SeoKeywordService(null),
            new PosterPdfService(),
            new ScoutYearService($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            new MemberService(new MemberYearRepository($this->pdo), new MemberProfileRepository($connection, $this->encryption)),
            $sections,
            new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo),
            sys_get_temp_dir(),
            $journal,
            new TicketService($this->responses)
        );
        $this->formController = new FormController(
            $this->twig,
            $this->articleService,
            $this->formService,
            $this->responseService,
            new ScoutYearService($this->pdo),
            $journal
        );

        // An article as the import leaves it: online since 2023, locked,
        // no summary, no image, its body and one question in a closed form.
        $this->importedId = NewsTestHelper::publish(
            $this->pdo,
            $this->articles->create('Fermeture du local', Article::VISIBILITY_PUBLIC, false, null, null, $this->adminId)
        );
        $this->pdo->prepare('UPDATE news_articles SET published_at = ? WHERE id = ?')->execute(['2023-03-02 18:00:00', $this->importedId]);
        $this->articles->markImported($this->importedId, '2026-11-10 09:00:00', 'scoutmagic-news-history:ancien-site');
        $this->articles->setHasForm($this->importedId, true);
        $this->formId = $this->forms->create(
            $this->importedId,
            NewsForm::ACCESS_PUBLIC,
            NewsForm::RESPONSE_LIMIT_UNLIMITED,
            null,
            null,
            true,
            'chief',
            null,
            null
        );
        $this->fieldIds[] = $this->fields->create($this->formId, 0, FormField::TYPE_TEXT, null, false, null, null, null, null, '<p>Le local est fermé.</p>');
        $this->fieldIds[] = $this->fields->create($this->formId, 1, FormField::TYPE_SHORT_TEXT, 'Nombre d\'adultes', true, null, null, null, null, null);
        $this->fieldIds[] = $this->fields->create($this->formId, 2, FormField::TYPE_LONG_TEXT, 'Allergies', false, null, null, null, null, null);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // ------------------------------------------------------------------
    // The server refuses
    // ------------------------------------------------------------------

    public function testTheEditorIsNotOfferedAndLeadsBackToTheArticle(): void
    {
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');

        $response = $this->newsController->edit(new Request('GET', '/news/' . $this->importedId . '/gerer', [], [], [], []), ['id' => (string) $this->importedId]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/news/' . $this->importedId, $response->getHeaders()['Location']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function intents(): array
    {
        return [
            'save' => ['save'],
            'publish' => ['publish'],
            'back to draft' => ['unpublish'],
            'schedule' => ['schedule'],
        ];
    }

    /**
     * Modification, publication, return to draft and scheduling all go
     * through POST /news/{id}: every one of them refused, nothing written.
     */
    #[DataProvider('intents')]
    public function testEveryIntentOfTheEditorIsRefused(string $intent): void
    {
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');
        $before = $this->snapshot();

        $response = $this->newsController->update(new Request('POST', '/news/' . $this->importedId, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'title' => 'Titre réécrit',
            'summary' => 'Un résumé inventé.',
            'visibility' => 'admin',
            'intent' => $intent,
            'schedule_date' => (new \DateTimeImmutable('+2 days'))->format('Y-m-d'),
            'schedule_time' => '08:00',
            'fields_json' => json_encode([['id' => null, 'field_type' => 'short_text', 'label' => 'Nouvelle question']]),
            'form_is_force_closed' => '',
        ], [], []), ['id' => (string) $this->importedId]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('lecture seule', $response->getBody());
        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions')->fetchColumn());
    }

    public function testDeletionIsRefused(): void
    {
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');

        $response = $this->newsController->delete(
            new RequestWithInput('DELETE', '/news/' . $this->importedId, [], [], [], [], (string) json_encode(['_csrf_token' => CsrfGuard::generateToken()])),
            ['id' => (string) $this->importedId]
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertNotNull($this->articles->findById($this->importedId));
    }

    public function testTheFormsQuestionsCannotBeReordered(): void
    {
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');
        $before = $this->snapshot();

        $response = $this->formController->reorderFields(
            new RequestWithInput('PATCH', '/news/' . $this->importedId . '/form/fields/reorder', [], [], [], [], (string) json_encode([
                '_csrf_token' => CsrfGuard::generateToken(),
                'ids' => array_reverse($this->fieldIds),
            ])),
            ['id' => (string) $this->importedId]
        );

        $this->assertSame(409, $response->getStatusCode());
        $this->assertSame($before, $this->snapshot());
    }

    /** No response, from anybody — the form being closed is not the only guard. */
    public function testTheFormTakesNoResponseEvenWereItsRowReopened(): void
    {
        // Somebody reopens the row by hand: the article's lock still holds.
        $this->pdo->prepare('UPDATE news_forms SET is_force_closed = 0 WHERE id = ?')->execute([$this->formId]);

        $response = $this->formController->submit(new Request('POST', '/news/' . $this->importedId . '/form/submit', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'parent@test.com',
            'field_' . $this->fieldIds[1] => '2',
        ], [], []), ['id' => (string) $this->importedId]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(0, $this->responses->countByFormId($this->formId));
    }

    /** Nor any change to a response, should a row exist all the same. */
    public function testAResponseCannotBeOpenedNorChanged(): void
    {
        $responseId = $this->responses->create($this->formId, null, null, 'parent@test.com', [$this->fieldIds[1] => '2'], null, null);
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');
        $params = ['id' => (string) $this->importedId, 'response_id' => (string) $responseId];
        $path = '/news/' . $this->importedId . '/form/responses/' . $responseId . '/edit';

        $this->assertSame(404, $this->formController->editResponse(new Request('GET', $path, [], [], [], []), $params)->getStatusCode());
        $response = $this->formController->updateResponse(new Request('POST', $path, [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'contact_email' => 'autre@test.com',
            'field_' . $this->fieldIds[1] => '5',
        ], [], []), $params);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame(['2'], array_values($this->responses->getValues($responseId)));
    }

    /**
     * The services refuse on their own, whichever route or future caller
     * reaches them.
     */
    public function testTheServicesRefuseEveryChange(): void
    {
        $form = $this->forms->findById($this->formId) ?? $this->fail('No form.');
        $article = $this->articles->findById($this->importedId) ?? $this->fail('No article.');
        $calls = [
            'update' => fn () => $this->articleService->update($this->importedId, 'X', 'public', false, null, null, 'Résumé', null),
            'publish' => fn () => $this->articleService->publish($this->importedId),
            'schedule' => fn () => $this->articleService->schedule($this->importedId, new \DateTimeImmutable('+1 day')),
            'unpublish' => fn () => $this->articleService->unpublish($this->importedId),
            'delete' => fn () => $this->articleService->delete($this->importedId),
            'form save' => fn () => $this->formService->save($this->importedId, [
                'access' => 'public', 'response_limit' => 'unlimited', 'opens_at' => null, 'closes_at' => null,
                'is_force_closed' => false, 'response_role_min' => 'chief', 'digest_email' => 'x@test.com',
                'finance_account_id' => null,
            ], []),
            'reorder' => fn () => $this->formService->reorderFields($this->formId, array_reverse($this->fieldIds)),
            'submit' => fn () => $this->responseService->submit($article, $form, [], null, null, 1, 'parent@test.com', [], null),
        ];
        $before = $this->snapshot();

        foreach ($calls as $what => $call) {
            try {
                $call();
                $this->fail('The service accepted: ' . $what);
            } catch (NewsException $e) {
                $this->assertStringContainsString('ancien site', $e->getMessage(), $what);
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    /**
     * D11: the lock survives the data migration module being disabled. It
     * can, because the news module does not know that module exists: the
     * lock reads `news_articles.imported_at`, and not one line of the news
     * module names the data migration — which is also why every test above
     * holds with nothing of it built.
     */
    public function testTheLockHoldsWithTheDataMigrationModuleDisabled(): void
    {
        $root = dirname(__DIR__, 4) . '/modules/news';
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || !in_array($file->getExtension(), ['php', 'twig', 'sql', 'json'], true)) {
                continue;
            }
            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $this->assertStringNotContainsString('DataMigration', $token[1], $file->getPathname());
                }
            }
        }

        $this->assertTrue(($this->articles->findById($this->importedId) ?? $this->fail('No article.'))->isImported());
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');
        $response = $this->newsController->delete(
            new RequestWithInput('DELETE', '/news/' . $this->importedId, [], [], [], [], (string) json_encode(['_csrf_token' => CsrfGuard::generateToken()])),
            ['id' => (string) $this->importedId]
        );
        $this->assertSame(409, $response->getStatusCode());
    }

    public function testAnArticleWrittenHereIsNotLocked(): void
    {
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');
        $native = NewsTestHelper::publish($this->pdo, $this->articles->create('Écrite ici', Article::VISIBILITY_PUBLIC, false, null, null, $this->adminId, 'Résumé.', null));

        $this->assertFalse(($this->articles->findById($native) ?? $this->fail('No article.'))->isImported());
        $this->assertSame(200, $this->newsController->edit(new Request('GET', '/news/' . $native . '/gerer', [], [], [], []), ['id' => (string) $native])->getStatusCode());
        $this->articleService->unpublish($native);
        $this->assertSame(Article::STATUS_DRAFT, ($this->articles->findById($native) ?? $this->fail('No article.'))->status);
    }

    // ------------------------------------------------------------------
    // What the reader sees (maquette « Marques Importé » — Actualités)
    // ------------------------------------------------------------------

    public function testThePageCarriesTheBannerAndTheClosedFormWithoutAnyEditingControl(): void
    {
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');

        $response = $this->newsController->show(new Request('GET', '/news/' . $this->importedId, [], [], [], []), ['id' => (string) $this->importedId]);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Élément historique en lecture seule', $body);
        $this->assertStringContainsString('Importé de l\'ancien site le 10 novembre 2026', $body);
        $this->assertStringContainsString('>Importé<', $body);
        $this->assertStringContainsString('Publié le 2 mars 2023 · Visible de tout le monde', $body);
        $this->assertStringContainsString('Formulaire fermé', $body);
        $this->assertStringContainsString('Nombre d&#039;adultes · Allergies', $body);
        $this->assertStringContainsString('Les réponses n\'ont pas été reprises', $body);
        $this->assertStringContainsString('Le local est fermé.', $body);
        // Neither the editor, nor the form to fill in.
        $this->assertStringNotContainsString('/gerer', $body);
        $this->assertStringNotContainsString('/form/submit', $body);
    }

    public function testTheListsCarryTheBadgeANeutralTileAndNoInventedSummary(): void
    {
        AuthSession::login($this->adminId, 'admin@test.com', 'admin');

        foreach ([
            'manage' => $this->newsController->manage(new Request('GET', '/news/manage', [], [], [], []), [])->getBody(),
            'public' => $this->newsController->index(new Request('GET', '/news', [], [], [], []), [])->getBody(),
        ] as $list => $body) {
            $this->assertStringContainsString('Fermeture du local', $body, $list);
            $this->assertStringContainsString('badge-imported', $body, $list);
            $this->assertStringContainsString('Pas d\'image', $body, $list);
            $this->assertStringContainsString('href="/news/' . $this->importedId . '"', $body, $list);
            $this->assertStringNotContainsString('/news/' . $this->importedId . '/gerer', $body, $list);
            $this->assertStringContainsString('Formulaire fermé', $body, $list);
        }
    }

    // ------------------------------------------------------------------
    // RBAC of every route this change touched, through the real guard
    // ------------------------------------------------------------------

    /**
     * method, path, controller, action, role_min, role one rung below (null
     * for a public route, which has none).
     *
     * @return array<string, array{string, string, string, string, string, ?string}>
     */
    public static function routes(): array
    {
        return [
            'list' => ['GET', '/news', 'NewsController', 'index', 'public', null],
            'manage' => ['GET', '/news/manage', 'NewsController', 'manage', 'chief', 'intendant'],
            'show' => ['GET', '/news/{id}', 'NewsController', 'show', 'public', null],
            'edit' => ['GET', '/news/{id}/gerer', 'NewsController', 'edit', 'chief', 'intendant'],
            'update' => ['POST', '/news/{id}', 'NewsController', 'update', 'chief', 'intendant'],
            'delete' => ['DELETE', '/news/{id}', 'NewsController', 'delete', 'chief', 'intendant'],
            'reorder' => ['PATCH', '/news/{id}/form/fields/reorder', 'FormController', 'reorderFields', 'chief', 'intendant'],
            'submit' => ['POST', '/news/{id}/form/submit', 'FormController', 'submit', 'public', null],
            'edit response' => ['GET', '/news/{id}/form/responses/{response_id}/edit', 'FormController', 'editResponse', 'public', null],
            'update response' => ['POST', '/news/{id}/form/responses/{response_id}/edit', 'FormController', 'updateResponse', 'public', null],
        ];
    }

    #[DataProvider('routes')]
    public function testTheRouteIsGrantedAtItsFloor(string $method, string $path, string $controller, string $action, string $roleMin, ?string $below): void
    {
        $this->assertSame($roleMin, $this->declaredRoleMin($method, $path), 'module.json moved: update this test.');
        if ($roleMin !== 'public') {
            AuthSession::login($this->adminId, 'admin@test.com', $roleMin);
        }

        $response = $this->guarded($method, $path, $controller, $action, $roleMin)->handle(new Request($method, $this->resolved($path), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode(), $response->getBody());
    }

    #[DataProvider('routes')]
    public function testTheRouteIsRefusedOneRungBelow(string $method, string $path, string $controller, string $action, string $roleMin, ?string $below): void
    {
        if ($below === null) {
            $this->assertSame('public', $roleMin, 'Only a public route has no rung below it.');

            return;
        }
        AuthSession::login($this->adminId, 'admin@test.com', $below);

        $response = $this->guarded($method, $path, $controller, $action, $roleMin)->handle(new Request($method, $this->resolved($path), [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    private function guarded(string $method, string $path, string $controller, string $action, string $roleMin): FrontController
    {
        $class = 'Modules\\News\\Controller\\' . $controller;
        $router = new Router();
        $router->addRoute($method, $path, $class, $action, $roleMin);
        $configFile = sys_get_temp_dir() . '/test_news_lock_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $front = new FrontController($router, $this->twig, new AppConfig($configFile));
        $front->registerController($class, $controller === 'NewsController' ? $this->newsController : $this->formController);

        return $front;
    }

    /** The guard's subject is a NATIVE article: the lock is not what is tested here. */
    private function resolved(string $path): string
    {
        $native = NewsTestHelper::publish($this->pdo, $this->articles->create('Écrite ici', Article::VISIBILITY_PUBLIC, false, null, null, $this->adminId, 'Résumé.', null));

        return str_replace(['{id}', '{response_id}'], [(string) $native, '999'], $path);
    }

    private function declaredRoleMin(string $method, string $path): ?string
    {
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/news/module.json'), true);
        foreach ((array) ($manifest['routes'] ?? []) as $route) {
            if (($route['method'] ?? null) === $method && ($route['path'] ?? null) === $path) {
                return (string) $route['role_min'];
            }
        }

        return null;
    }

    /**
     * Everything the lock protects, as rows.
     *
     * @return array<string, mixed>
     */
    private function snapshot(): array
    {
        return [
            'article' => $this->rows(
                'SELECT title, summary, visibility, status, published_at, image_file_id, imported_at'
                    . ' FROM news_articles WHERE id = ?',
                $this->importedId
            )[0] ?? false,
            'form' => $this->rows(
                'SELECT is_force_closed, digest_email, issues_ticket, finance_account_id FROM news_forms WHERE id = ?',
                $this->formId
            )[0] ?? false,
            'fields' => $this->rows(
                'SELECT id, sort_order, field_type, label, confirmation_text FROM news_form_fields'
                    . ' WHERE form_id = ? ORDER BY id',
                $this->formId
            ),
            'responses' => (int) $this->pdo->query('SELECT COUNT(*) FROM news_form_responses')->fetchColumn(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, int $id): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute([$id]);

        return $statement->fetchAll(\PDO::FETCH_ASSOC);
    }
}
