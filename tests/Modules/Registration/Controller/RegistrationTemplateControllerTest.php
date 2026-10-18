<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Config\AppConfig;
use Core\Http\FlashMessage;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Response;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Controller\RegistrationTemplateController;
use Modules\Registration\Service\RequestEmailTemplateService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\TestTwig;
use Twig\Environment;

/**
 * « Gabarits » (issue #843, IT-07) through the real controller, the real
 * templates and — for the role boundary and the breadcrumb — the module's
 * real manifest and front controller: the list of the four request e-mails
 * with their state and pencil, the warning while a decision e-mail cannot
 * leave, one edit page per e-mail, saving, restoring the default, and
 * `role_min: admin` on every route (chief refused).
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationTemplateControllerTest extends TestCase
{
    private const LIST = '/config/inscriptions/gabarits';

    private \PDO $pdo;
    private Environment $twig;
    private EditableContentService $content;
    private RegistrationTemplateController $controller;
    private JournalRepository $journal;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);

        $twig = TestTwig::create(['registration']);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', self::LIST);
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig = $twig;

        $this->content = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->journal = new JournalRepository($this->pdo);
        $this->controller = new RegistrationTemplateController(
            $twig,
            new RequestEmailTemplateService($this->content),
            new JournalService($this->journal)
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // ------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------

    public function testTheListShowsTheFourEmailsWithTheirDescriptionStateAndPencil(): void
    {
        $body = $this->renderList();

        $this->assertStringContainsString('Gabarits des e-mails de demande', $body);
        $rows = [
            'accuse-de-reception' => [
                'Accusé de réception de la demande',
                'Envoyé à la famille dès le dépôt.',
                'Par défaut',
            ],
            'alerte-unite' => [
                'Alerte à l&#039;unité',
                'Envoyé à l&#039;unité à chaque nouvelle demande.',
                'Par défaut',
            ],
            'acceptation' => [
                'Acceptation de la demande',
                'Envoyé à la famille quand la demande est acceptée.',
                'Par défaut',
            ],
            'refus' => ['Refus de la demande', 'Envoyé à la famille quand la demande est refusée.', 'Par défaut'],
        ];
        $this->assertSame(4, substr_count($body, 'class="list-editor-item '));
        foreach ($rows as $slug => [$name, $description, $state]) {
            $row = $this->rowOf($body, $slug);
            $this->assertStringContainsString($name, $row);
            $this->assertStringContainsString($description, $row);
            $this->assertMatchesRegularExpression(
                '#<span class="badge [^"]*">' . preg_quote($state, '#') . '</span>#u',
                $row
            );
            $this->assertMatchesRegularExpression(
                '#<a href="/config/inscriptions/gabarits/' . $slug . '"\s+class="btn btn-sm btn-outline-secondary"\s+'
                    . 'aria-label="Modifier — ' . preg_quote($name, '#') . '" title="Modifier — '
                    . preg_quote($name, '#') . '">\s*<i class="bi bi-pencil"#u',
                $row
            );
        }
        $this->assertStringContainsString(
            'Les e-mails de réinscription se règlent dans la',
            $body
        );
        $this->assertStringContainsString(
            '<a href="/config/reinscription/reglages">gestion des réinscriptions</a>',
            $body
        );
    }

    /**
     * Issue #932: no template is « à rédiger » any more, so the list shows
     * no warning and no such state; it says the three family e-mails carry
     * the tracking link.
     */
    public function testTheListNeverWarnsOfATemplateToWriteAndNamesTheLinkRule(): void
    {
        $body = $this->renderList();

        $this->assertStringNotContainsString('À rédiger', $body);
        $this->assertStringNotContainsString('doivent être rédigés', $body);
        $this->assertStringContainsString('doivent contenir le lien de suivi', $body);

        $this->content->set(RequestEmailTemplateService::KEY_ACCEPTED, '<p>Bienvenue {{ lien_suivi }}</p>', 'rich_text', 1);
        $this->content->set(RequestEmailTemplateService::KEY_REFUSED, '<p>Désolé {{ lien_suivi }}</p>', 'rich_text', 1);
        $body = $this->renderList();
        $this->assertStringContainsString('Personnalisé', $this->rowOf($body, 'acceptation'));
        $this->assertStringContainsString('Personnalisé', $this->rowOf($body, 'refus'));
    }

    public function testACustomisedSubmissionEmailReadsPersonnalise(): void
    {
        $this->content->set(
            RequestEmailTemplateService::KEY_RECEIPT,
            '<p>Merci {{ prenom_enfant }}</p>',
            'rich_text',
            1
        );

        $body = $this->renderList();

        $this->assertStringContainsString('Personnalisé', $this->rowOf($body, 'accuse-de-reception'));
        $this->assertStringContainsString('Par défaut', $this->rowOf($body, 'alerte-unite'));
    }

    /**
     * A sub-page of « Formulaire d'inscription » like the other three: the
     * module's title, the rail with « Gabarits » selected — on the edit page
     * too — and nothing folded away.
     */
    public function testTheListAndTheEditPageCarryTheRailWithGabaritsSelected(): void
    {
        $pages = [
            $this->renderList(),
            $this->renderEdit('refus', self::LIST . '/refus'),
        ];

        foreach ($pages as $body) {
            $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Formulaire d&#039;inscription\s*</', $body);
            $this->assertSame(1, substr_count($body, 'aria-current="page"'));
            $this->assertMatchesRegularExpression('#<a[^>]*aria-current="page"[^>]*>\s*<span>Gabarits</span>#u', $body);
            $this->assertStringNotContainsString('data-bs-toggle="collapse"', $body);
        }
    }

    // ------------------------------------------------------------------
    // The edit page
    // ------------------------------------------------------------------

    public function testTheEditPageOfASubmissionEmailStartsFromItsDefaultWithItsVariables(): void
    {
        $body = $this->renderEdit('accuse-de-reception');

        $this->assertStringContainsString('Modifier — Accusé de réception de la demande', $body);
        $this->assertStringContainsString('texte par défaut</strong>', $body);
        $this->assertStringContainsString('Nous avons bien reçu votre demande', $this->hiddenBodyValue($body));
        $this->assertStringContainsString('action="/config/inscriptions/gabarits/accuse-de-reception"', $body);
        foreach (['prenom_enfant', 'annee_scoute', 'lien_suivi', 'nom_unite'] as $variable) {
            $this->assertStringContainsString('data-insert-keyword="' . $variable . '"', $body);
            $this->assertStringContainsString('<code>{{ ' . $variable . ' }}</code>', $body);
        }
        $this->assertStringNotContainsString('data-insert-keyword="lien_fiche"', $body);
        // Nothing to restore while the default is in force.
        $this->assertStringNotContainsString('Rétablir le texte par défaut', $body);
        $this->assertStringContainsString(
            '<a href="/config/inscriptions/gabarits" class="btn btn-outline-secondary">Annuler</a>',
            $body
        );
    }

    public function testTheEditPageOfADecisionEmailStartsFromItsDefaultWithTheLink(): void
    {
        $body = $this->renderEdit('acceptation');

        $this->assertStringContainsString('texte par défaut</strong>', $body);
        $this->assertStringContainsString('a été acceptée', $this->hiddenBodyValue($body));
        $this->assertStringContainsString('lien_suivi', $this->hiddenBodyValue($body));
        $this->assertStringContainsString('doit contenir le lien de suivi', $body);
        $this->assertStringNotContainsString('à rédiger', $body);
        $this->assertStringNotContainsString('Rétablir le texte par défaut', $body);
    }

    public function testTheUnitAlertEditPageDoesNotAskForTheTrackingLink(): void
    {
        $body = $this->renderEdit('alerte-unite');

        $this->assertStringNotContainsString('doit contenir le lien de suivi', $body);
        $this->assertStringNotContainsString('data-insert-keyword="lien_suivi"', $body);
    }

    public function testACustomisedSubmissionEmailOffersToRestoreTheDefaultAndAsksFirst(): void
    {
        $this->content->set(
            RequestEmailTemplateService::KEY_UNIT_ALERT,
            '<p>Demande : {{ prenom_enfant }}</p>',
            'rich_text',
            1
        );

        $body = $this->renderEdit('alerte-unite');

        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/config/inscriptions/gabarits/alerte-unite/defaut" class="mt-2"\s+'
                . 'data-confirm="Rétablir le texte par défaut \? Le texte que vous avez écrit sera perdu.">#u',
            $body
        );
        $this->assertStringContainsString('Demande : {{ prenom_enfant }}', $this->hiddenBodyValue($body));
    }

    public function testACustomisedDecisionEmailOffersToRestoreTheDefault(): void
    {
        $this->content->set(RequestEmailTemplateService::KEY_REFUSED, '<p>Désolé {{ lien_suivi }}</p>', 'rich_text', 1);

        $body = $this->renderEdit('refus');

        $this->assertStringContainsString('Rétablir le texte par défaut', $body);
        $this->assertStringContainsString('/config/inscriptions/gabarits/refus/defaut', $body);
    }

    public function testTheEditPageNamesAnUnknownVariable(): void
    {
        $this->content->set(RequestEmailTemplateService::KEY_ACCEPTED, '<p>{{ creneau }}</p>', 'rich_text', 1);

        $body = $this->renderEdit('acceptation');

        $this->assertStringContainsString('Variables non reconnues', $body);
        $this->assertStringContainsString('<code>{{ creneau }}</code>', $body);
    }

    public function testAnUnknownTemplateIsNotFound(): void
    {
        $response = $this->controller->edit(new Request('GET', self::LIST . '/inconnu', [], [], [], []), [
            'template' => 'inconnu',
        ]);

        $this->assertSame(404, $response->getStatusCode());
    }

    // ------------------------------------------------------------------
    // Saving and restoring
    // ------------------------------------------------------------------

    public function testSavingStoresTheSanitisedBodyJournalsAndGoesBackToTheList(): void
    {
        $response = $this->post('save', 'acceptation', [
            'body' => '<p>Bienvenue {{ prenom_enfant }} ! {{ lien_suivi }}</p><script>alert(1)</script>',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(self::LIST, $response->getHeaders()['Location'] ?? null);
        $stored = (string) $this->content->get(RequestEmailTemplateService::KEY_ACCEPTED);
        $this->assertStringContainsString('Bienvenue {{ prenom_enfant }} !', $stored);
        $this->assertStringNotContainsString('script', $stored);
        $this->assertSame(
            ['type' => 'success', 'message' => '« Acceptation de la demande » enregistré.'],
            FlashMessage::get()
        );

        $entries = $this->journalEntries('registration_email_template_saved');
        $this->assertCount(1, $entries);
        $this->assertSame(
            ['template' => 'registration_email_accepted_body'],
            json_decode((string) $entries[0]['context'], true)
        );
        $this->assertStringNotContainsString('Bienvenue', json_encode($entries[0], JSON_UNESCAPED_UNICODE) ?: '');
    }

    public function testABlankBodyIsRefusedAndTheEditPageReopened(): void
    {
        $response = $this->post('save', 'refus', ['body' => '<p>&nbsp;</p>']);

        $this->assertSame(self::LIST . '/refus', $response->getHeaders()['Location'] ?? null);
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
        $this->assertNull($this->content->get(RequestEmailTemplateService::KEY_REFUSED));
        $this->assertCount(0, $this->journalEntries('registration_email_template_saved'));
    }

    /**
     * Issue #932: a family template saved without `{{ lien_suivi }}` is
     * refused with the way to put it back, nothing is stored or journaled,
     * and the edit page reopens.
     */
    public function testAFamilyBodyWithoutTheTrackingLinkIsRefusedWithAHelpfulMessage(): void
    {
        foreach (['accuse-de-reception', 'acceptation', 'refus'] as $slug) {
            $response = $this->post('save', $slug, ['body' => '<p>Merci, à bientôt.</p>']);

            $this->assertSame(self::LIST . '/' . $slug, $response->getHeaders()['Location'] ?? null, $slug);
            $flash = FlashMessage::get();
            $this->assertSame('error', $flash['type'] ?? null, $slug);
            $this->assertSame(RequestEmailTemplateService::MISSING_LINK_MESSAGE, $flash['message'] ?? null, $slug);
        }
        foreach ([
            RequestEmailTemplateService::KEY_RECEIPT,
            RequestEmailTemplateService::KEY_ACCEPTED,
            RequestEmailTemplateService::KEY_REFUSED,
        ] as $key) {
            $this->assertNull($this->content->get($key), $key);
        }
        $this->assertCount(0, $this->journalEntries('registration_email_template_saved'));
    }

    public function testTheUnitAlertIsSavedWithoutTheTrackingLink(): void
    {
        $response = $this->post('save', 'alerte-unite', ['body' => '<p>Nouvelle demande {{ lien_fiche }}</p>']);

        $this->assertSame(self::LIST, $response->getHeaders()['Location'] ?? null);
        $this->assertStringContainsString(
            '{{ lien_fiche }}',
            (string) $this->content->get(RequestEmailTemplateService::KEY_UNIT_ALERT)
        );
    }

    public function testAnExpiredTokenSavesNothing(): void
    {
        CsrfGuard::generateToken();
        $response = $this->controller->save(
            new Request(
                'POST',
                self::LIST . '/refus',
                [],
                ['_csrf_token' => 'nope', 'body' => '<p>Désolé {{ lien_suivi }}</p>'],
                [],
                []
            ),
            ['template' => 'refus']
        );

        $this->assertSame(self::LIST . '/refus', $response->getHeaders()['Location'] ?? null);
        $this->assertNull($this->content->get(RequestEmailTemplateService::KEY_REFUSED));
    }

    public function testRestoringTheDefaultForgetsTheUnitsTextAndJournals(): void
    {
        $this->content->set(RequestEmailTemplateService::KEY_RECEIPT, '<p>Merci</p>', 'rich_text', 1);

        $response = $this->post('restoreDefault', 'accuse-de-reception', []);

        $this->assertSame(self::LIST, $response->getHeaders()['Location'] ?? null);
        $this->assertNull($this->content->get(RequestEmailTemplateService::KEY_RECEIPT));
        $this->assertStringContainsString('Par défaut', $this->rowOf($this->renderList(), 'accuse-de-reception'));
        $this->assertCount(1, $this->journalEntries('registration_email_template_reset'));
    }

    /**
     * « Rétablir le texte par défaut » on a decision e-mail gives back a
     * text with a working link (issue #932).
     */
    public function testRestoringADecisionEmailGivesBackTheDefaultWithTheLink(): void
    {
        $this->content->set(RequestEmailTemplateService::KEY_ACCEPTED, '<p>Bienvenue {{ lien_suivi }}</p>', 'rich_text', 1);

        $response = $this->post('restoreDefault', 'acceptation', []);

        $this->assertSame(self::LIST, $response->getHeaders()['Location'] ?? null);
        $this->assertNull($this->content->get(RequestEmailTemplateService::KEY_ACCEPTED));
        $this->assertStringContainsString('lien_suivi', $this->hiddenBodyValue($this->renderEdit('acceptation')));
    }

    // ------------------------------------------------------------------
    // RBAC and breadcrumb, through the manifest
    // ------------------------------------------------------------------

    /**
     * Every « Gabarits » route the manifest declares.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function routeProvider(): array
    {
        return [
            'list' => ['GET', self::LIST, 'index'],
            'edit' => ['GET', self::LIST . '/accuse-de-reception', 'edit'],
            'save' => ['POST', self::LIST . '/accuse-de-reception', 'save'],
            'restore' => ['POST', self::LIST . '/accuse-de-reception/defaut', 'restoreDefault'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testAdminReachesEveryRoute(string $method, string $path, string $action): void
    {
        $response = $this->dispatch($method, $path, $action, 'admin');

        if ($method === 'GET') {
            $this->assertSame(200, $response->getStatusCode(), substr((string) $response->getBody(), 0, 400));
        } else {
            $this->assertSame(302, $response->getStatusCode());
            $this->assertSame(self::LIST, $response->getHeaders()['Location'] ?? null, 'the action went through');
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testOneRoleBelowIsRefused(string $method, string $path, string $action): void
    {
        $this->content->set(RequestEmailTemplateService::KEY_RECEIPT, '<p>Merci</p>', 'rich_text', 1);

        $response = $this->dispatch($method, $path, $action, 'chief');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('<p>Merci</p>', $this->content->get(RequestEmailTemplateService::KEY_RECEIPT));
    }

    public function testTheListAndTheEditPageCarryTheFullBreadcrumb(): void
    {
        $list = (string) $this->dispatch('GET', self::LIST, 'index', 'admin')->getBody();
        $edit = (string) $this->dispatch('GET', self::LIST . '/alerte-unite', 'edit', 'admin')->getBody();

        foreach ([$list, $edit] as $body) {
            $this->assertStringContainsString('Espace chefs d&#039;U', $body);
            $this->assertMatchesRegularExpression(
                '#<a href="/config/inscriptions" class="text-decoration-none">Formulaire d&\#039;inscription</a>#',
                $body
            );
        }
        $this->assertMatchesRegularExpression('#aria-current="page">Gabarits</li>#', $list);
        $this->assertMatchesRegularExpression(
            '#<a href="/config/inscriptions/gabarits" class="text-decoration-none">Gabarits</a>#',
            $edit
        );
        $this->assertMatchesRegularExpression('#aria-current="page">Modifier — Alerte à l&\#039;unité</li>#u', $edit);
    }

    // ------------------------------------------------------------------

    private function renderList(): string
    {
        $this->twig->addGlobal('current_path', self::LIST);
        $response = $this->controller->index(new Request('GET', self::LIST, [], [], [], []), []);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    private function renderEdit(string $slug, ?string $currentPath = null): string
    {
        $this->twig->addGlobal('current_path', $currentPath ?? self::LIST . '/' . $slug);
        $response = $this->controller->edit(new Request('GET', self::LIST . '/' . $slug, [], [], [], []), [
            'template' => $slug,
        ]);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
    }

    /**
     * @param array<string, string> $body
     */
    private function post(string $action, string $slug, array $body): Response
    {
        $path = self::LIST . '/' . $slug . ($action === 'restoreDefault' ? '/defaut' : '');

        return $this->controller->$action(
            new Request('POST', $path, [], $body + ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            ['template' => $slug]
        );
    }

    /** The list row of one template, from its pencil's address back to its opening tag. */
    private function rowOf(string $body, string $slug): string
    {
        $this->assertSame(
            1,
            preg_match(
                '#<div class="list-editor-item [^"]*"[^>]*data-id="' . preg_quote($slug, '#') . '".*?</a>#su',
                $body,
                $match
            ),
            "the list has a row for {$slug}"
        );

        return $match[0];
    }

    /** The value the editor's form would post, decoded. */
    private function hiddenBodyValue(string $body): string
    {
        $this->assertSame(
            1,
            preg_match(
                '#<input type="hidden" class="rich-text-form-value" name="body" value="([^"]*)">#',
                $body,
                $match
            )
        );

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function journalEntries(string $type): array
    {
        return array_values(array_filter(
            $this->journal->search('registration'),
            static fn(array $entry): bool => $entry['event_type'] === $type
        ));
    }

    private function dispatch(string $method, string $path, string $action, string $role): Response
    {
        AuthSession::login(1, $role . '@test.be', $role);
        $this->twig->addGlobal('current_path', $path);

        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
        $router = new Router();
        foreach ($manifest['routes'] as $route) {
            $router->addRoute(
                $route['method'],
                $route['path'],
                $route['controller'],
                $route['action'],
                $route['role_min'],
                $route['breadcrumb'] ?? null,
                'registration'
            );
        }

        $request = $method === 'POST'
            ? new Request('POST', $path, [], [
                '_csrf_token' => CsrfGuard::generateToken(),
                'body' => '<p>Bonjour {{ prenom_enfant }} {{ lien_suivi }}</p>',
            ], [], [])
            : new Request('GET', $path, [], [], [], []);
        $resolved = $router->resolve($request);
        $this->assertNotNull($resolved, "the manifest declares {$method} {$path}");
        $this->assertSame(RegistrationTemplateController::class, $resolved->controllerClass);
        $this->assertSame($action, $resolved->action);

        $configFile = sys_get_temp_dir() . '/test_registration_templates_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(RegistrationTemplateController::class, $this->controller);

        try {
            return $frontController->handle($request);
        } finally {
            @unlink($configFile);
        }
    }
}
