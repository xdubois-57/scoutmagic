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
use Core\Mail\MailService;
use Core\Mail\Template\EmailTemplateCustomisationService;
use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Mail\Template\EmailTestSendThrottler;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HtmlSanitizer;
use Modules\Registration\Controller\RegistrationTemplateController;
use Modules\Registration\Service\RegistrationService;
use Modules\Registration\Service\RequestEmailService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Tests\RequestWithInput;
use Tests\TestTwig;
use Twig\Environment;

/**
 * « Gabarits » (issue #843, IT-07; issue #905) through the real controller,
 * the real templates and — for the role boundary and the breadcrumb — the
 * module's real manifest and front controller.
 *
 * Since issue #905 the page IS Configuration > E-mails narrowed to the four
 * request-flow e-mails the module declares in its manifest: the list with
 * « Par défaut » / « Personnalisé », the shared editor, the reset and the
 * test send, under `/config/inscriptions/gabarits` and `role_min: admin`.
 * What this file pins is what that narrowing must hold:
 *
 * - **the scope** — the four ids and nothing else, neither the module's
 *   other e-mails nor core's, on a read or a write: this door is open to a
 *   wider role than the core page;
 * - **the family's link** — `lien_suivi` is required in the receipt, the
 *   acceptance and the refusal, so a body without it is refused and named,
 *   and the unit alert does not even offer it;
 * - **`role_min: admin`** on every route, a chief refused.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationTemplateControllerTest extends TestCase
{
    private const LIST = RegistrationTemplateController::PAGE_URL;

    /** The three e-mails that go to the family, and so must carry its link. */
    private const FAMILY_EMAILS = [
        RegistrationService::RECEIPT_TEMPLATE,
        RequestEmailService::ACCEPTED_TEMPLATE,
        RequestEmailService::REFUSED_TEMPLATE,
    ];

    private \PDO $pdo;
    private Environment $twig;
    private EmailTemplateRegistry $registry;
    private EmailTemplateOverrideRepository $overrides;
    private RegistrationTemplateController $controller;

    /** Every message the controller's MailService "sends", in order. */
    private \ArrayObject $sent;

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

        // Core's e-mails plus every one this module declares — the shape
        // the composition root builds — so the scope is tested against
        // e-mails that really are there to leak.
        $this->registry = RegistrationTestHelper::emailRegistry();
        $this->overrides = new EmailTemplateOverrideRepository($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->sent = new \ArrayObject();
        $mailService = new class ($this->sent) extends MailService {
            public function __construct(private \ArrayObject $sent)
            {
            }

            /**
             * @param array<int, array{path: string, name: string}> $attachments
             * @param array<string, string> $extraHeaders
             * @param string|list<string>|null $cc
             */
            public function send(
                string $to,
                string $subject,
                string $bodyHtml,
                string $bodyText,
                ?string $replyTo = null,
                array $attachments = [],
                ?string $fromAddressOverride = null,
                ?string $fromNameOverride = null,
                array $extraHeaders = [],
                \Core\Mail\MailPurpose $purpose = \Core\Mail\MailPurpose::Ordinary,
                bool $vouchesForRecipient = false,
                ?string $bulkRunReference = null,
                ?\Core\Mail\Feedback\Seed\SeedCopyContent $bulkCopy = null,
                string|array|null $cc = null
            ): void {
                $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $bodyHtml, 'text' => $bodyText];
            }
        };

        $this->controller = new RegistrationTemplateController(
            $twig,
            $this->registry,
            $this->overrides,
            new EmailTemplateRenderer($twig, $this->registry, $this->overrides, $journal),
            new EmailTemplateCustomisationService($this->registry, $this->overrides, new HtmlSanitizer()),
            $journal,
            $mailService,
            new EmailTestSendThrottler($this->pdo)
        );

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $_POST = [];
    }

    // ------------------------------------------------------------------
    // The list
    // ------------------------------------------------------------------

    public function testTheListShowsTheFourRequestEmailsAndNothingElse(): void
    {
        $body = $this->renderList();

        $this->assertStringContainsString('Gabarits des e-mails de demande', $body);
        $this->assertSame(4, substr_count($body, '<a href="' . self::LIST . '/'));
        foreach (RegistrationTemplateController::REQUEST_EMAILS as $id) {
            $template = $this->registry->find($id);
            $this->assertNotNull($template, $id);
            $row = $this->rowOf($body, $id);
            $this->assertStringContainsString($this->escaped($template->label), $row);
            $this->assertStringContainsString($this->escaped($template->description), $row);
            $this->assertStringContainsString('>Par défaut</span>', $row, $id);
        }

        // Neither the module's other e-mails nor core's: this page is open
        // to a chief d'unité, Configuration > E-mails is not.
        foreach ([
            'registration.secondary_email_confirmation',
            'registration.reenrollment_opening',
            'magic_link',
            'member_email_unsubscribe_confirmation',
        ] as $outside) {
            $this->assertNotNull($this->registry->find($outside), "{$outside} is declared, so it could leak");
            $this->assertStringNotContainsString($outside, $body);
        }
        $this->assertStringNotContainsString('/config/emails', $body);

        $this->assertStringContainsString('doivent contenir le lien de suivi', $body);
        $this->assertStringNotContainsString('À rédiger', $body);
        $this->assertStringContainsString(
            '<a href="/config/reinscription/reglages">gestion des réinscriptions</a>',
            $body
        );
    }

    public function testACustomisedEmailReadsPersonnaliseAndTheOthersParDefaut(): void
    {
        $this->overrides->save(
            RequestEmailService::ACCEPTED_TEMPLATE,
            'Bienvenue',
            '<p>Bienvenue {{ lien_suivi }}</p>',
            null
        );

        $body = $this->renderList();

        $this->assertStringContainsString(
            '>Personnalisé</span>',
            $this->rowOf($body, RequestEmailService::ACCEPTED_TEMPLATE)
        );
        $others = array_diff(RegistrationTemplateController::REQUEST_EMAILS, [RequestEmailService::ACCEPTED_TEMPLATE]);
        foreach ($others as $id) {
            $this->assertStringContainsString('>Par défaut</span>', $this->rowOf($body, $id), $id);
        }
    }

    /**
     * A sub-page of « Formulaire d'inscription » like the other three: the
     * module's title and the rail with « Gabarits » selected.
     */
    public function testTheListCarriesTheRailWithGabaritsSelected(): void
    {
        $body = $this->renderList();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Formulaire d&#039;inscription\s*</', $body);
        $this->assertSame(1, substr_count($body, 'aria-current="page"'));
        $this->assertMatchesRegularExpression('#<a[^>]*aria-current="page"[^>]*>\s*<span>Gabarits</span>#u', $body);
    }

    // ------------------------------------------------------------------
    // The scope
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string}>
     */
    public static function outsideTheScope(): array
    {
        return [
            'another e-mail of the module' => ['registration.secondary_email_confirmation'],
            'a re-enrolment e-mail' => ['registration.reenrollment_opening'],
            'a core e-mail' => ['member_email_unsubscribe_confirmation'],
            'an undeclared id' => ['registration.inconnu'],
        ];
    }

    #[DataProvider('outsideTheScope')]
    public function testAnEmailOutsideTheFourIsAnsweredAsAnUnknownOne(string $id): void
    {
        $edit = $this->controller->edit(new Request('GET', self::LIST . '/' . $id, [], [], [], []), [
            'template' => $id,
        ]);
        $this->assertSame(302, $edit->getStatusCode());
        $this->assertSame(self::LIST, $edit->getHeaders()['Location'] ?? null);

        $body = $this->controller->saveBody(
            $this->jsonRequest(['key' => 'email_body', 'value' => '<p>Écrit ailleurs.</p>', 'type' => 'html']),
            ['template' => $id]
        );
        $this->assertSame(404, $body->getStatusCode());

        $subject = $this->controller->saveSubject($this->formRequest(['subject' => 'Détourné']), ['template' => $id]);
        $this->assertSame(self::LIST, $subject->getHeaders()['Location'] ?? null);

        $this->controller->sendTest($this->formRequest([]), ['template' => $id]);

        $this->assertNull($this->overrides->find($id));
        $this->assertCount(0, $this->sent);
    }

    // ------------------------------------------------------------------
    // The edit page
    // ------------------------------------------------------------------

    public function testTheEditPageOfAFamilyEmailMarksTheTrackingLinkObligatoire(): void
    {
        foreach (self::FAMILY_EMAILS as $id) {
            $body = $this->renderEdit($id);

            $this->assertStringContainsString('action="' . self::LIST . '/' . $id . '/sujet"', $body, $id);
            $this->assertStringContainsString(self::LIST . '/' . $id . '/corps', $body, $id);
            $this->assertStringNotContainsString('/config/emails/', $body, $id);
            $this->assertStringContainsString('data-variable="lien_suivi"', $body, $id);
            // The body's two rows of buttons (the page's and the editor
            // modal's) mark it, never the subject's.
            $this->assertSame(2, substr_count($body, '>obligatoire</span>'), $id);
            $this->assertStringContainsString('« obligatoire » doivent rester dans le texte', $body, $id);
            // Seeded with the shipped text, the placeholder kept as such.
            $this->assertStringContainsString('{{ lien_suivi }}', $body, $id);
        }
    }

    public function testTheUnitAlertEditPageOffersNoTrackingLink(): void
    {
        $body = $this->renderEdit(RegistrationService::UNIT_ALERT_TEMPLATE);

        $this->assertStringNotContainsString('data-variable="lien_suivi"', $body);
        $this->assertStringNotContainsString('{{ lien_suivi }}', $body);
        $this->assertStringNotContainsString('>obligatoire</span>', $body);
        $this->assertStringContainsString('data-variable="lien_fiche"', $body);
    }

    /**
     * A family body that lost its link by another road than this editor
     * (an older version, a restored backup) opens with the reason it is no
     * longer sent, and is not sent as a test either.
     */
    public function testAStoredFamilyBodyWithoutTheLinkOpensWithTheReasonAndIsNotSentAsATest(): void
    {
        $this->overrides->save(RequestEmailService::REFUSED_TEMPLATE, 'Refus', '<p>Désolés.</p>', null);

        $body = $this->renderEdit(RequestEmailService::REFUSED_TEMPLATE);
        $this->assertStringContainsString("Cet email n'est plus envoyé", $body);
        $this->assertStringContainsString('Lien de suivi de la demande', $body);

        $this->controller->sendTest($this->formRequest([]), ['template' => RequestEmailService::REFUSED_TEMPLATE]);

        $this->assertCount(0, $this->sent);
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
    }

    // ------------------------------------------------------------------
    // Saving, resetting, testing
    // ------------------------------------------------------------------

    /**
     * Issue #932, kept by #905: a family e-mail saved without
     * `{{ lien_suivi }}` is refused with the variable named as the editor
     * labels it, and nothing is stored or journaled.
     */
    public function testAFamilyBodyWithoutTheTrackingLinkIsRefusedNamingIt(): void
    {
        foreach (self::FAMILY_EMAILS as $id) {
            $response = $this->controller->saveBody(
                $this->jsonRequest(['key' => 'email_body', 'value' => '<p>Merci, à bientôt.</p>', 'type' => 'html']),
                ['template' => $id]
            );

            $this->assertSame(400, $response->getStatusCode(), $id);
            $error = (string) (json_decode($response->getBody(), true)['error'] ?? '');
            $this->assertStringContainsString('« Lien de suivi de la demande »', $error, $id);
            $this->assertStringContainsString('{{ lien_suivi }}', $error, $id);
            $this->assertNull($this->overrides->find($id), $id);
        }
        $this->assertSame(0, $this->journalCount('email_template_customised'));
    }

    public function testAFamilyBodyWithTheLinkIsStoredSanitisedAndJournaledWithoutItsContent(): void
    {
        $response = $this->controller->saveBody(
            $this->jsonRequest([
                'key' => 'email_body',
                'value' => '<p>Bienvenue {{ prenom_enfant }} ! {{ lien_suivi }}</p><script>alert(1)</script>',
                'type' => 'html',
            ]),
            ['template' => RequestEmailService::ACCEPTED_TEMPLATE]
        );

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $stored = $this->overrides->find(RequestEmailService::ACCEPTED_TEMPLATE);
        $this->assertNotNull($stored);
        $this->assertStringContainsString('Bienvenue {{ prenom_enfant }} ! {{ lien_suivi }}', $stored['body_html']);
        $this->assertStringNotContainsString('script', $stored['body_html']);
        $this->assertSame("Votre demande d'inscription a été acceptée", $stored['subject']);
        $this->assertSame(1, $stored['updated_by']);
        $this->assertSame(1, $this->journalCount('email_template_customised'));
        $context = $this->journalContext('email_template_customised');
        $this->assertStringContainsString(RequestEmailService::ACCEPTED_TEMPLATE, $context);
        $this->assertStringNotContainsString('Bienvenue', $context);
    }

    public function testTheUnitAlertIsSavedWithoutTheTrackingLink(): void
    {
        $response = $this->controller->saveBody(
            $this->jsonRequest(['key' => 'email_body', 'value' => '<p>Nouvelle demande {{ lien_fiche }}</p>']),
            ['template' => RegistrationService::UNIT_ALERT_TEMPLATE]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString(
            '{{ lien_fiche }}',
            (string) ($this->overrides->find(RegistrationService::UNIT_ALERT_TEMPLATE)['body_html'] ?? '')
        );
    }

    /**
     * Saving the subject alone stores a customisation whose body is the
     * shipped one, link included — so the receipt still leaves with it.
     */
    public function testSavingTheSubjectStoresItAndKeepsTheShippedBodyWithItsLink(): void
    {
        $response = $this->controller->saveSubject(
            $this->formRequest(['subject' => 'Bien reçu pour {{ prenom_enfant }}']),
            ['template' => RegistrationService::RECEIPT_TEMPLATE]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            self::LIST . '/' . RegistrationService::RECEIPT_TEMPLATE,
            $response->getHeaders()['Location'] ?? null
        );
        $stored = $this->overrides->find(RegistrationService::RECEIPT_TEMPLATE);
        $this->assertNotNull($stored);
        $this->assertSame('Bien reçu pour {{ prenom_enfant }}', $stored['subject']);
        $this->assertStringContainsString('{{ lien_suivi }}', $stored['body_html']);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
    }

    public function testAnExpiredTokenSavesNothing(): void
    {
        CsrfGuard::generateToken();
        $response = $this->controller->saveSubject(
            new Request('POST', self::LIST, [], ['_csrf_token' => 'nope', 'subject' => 'Autre'], [], []),
            ['template' => RequestEmailService::REFUSED_TEMPLATE]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->overrides->find(RequestEmailService::REFUSED_TEMPLATE));
    }

    public function testResetDeletesTheCustomisationAndJournals(): void
    {
        $this->overrides->save(RegistrationService::RECEIPT_TEMPLATE, 'Merci', '<p>Merci {{ lien_suivi }}</p>', null);

        $response = $this->controller->reset(
            $this->formRequest([]),
            ['template' => RegistrationService::RECEIPT_TEMPLATE]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->overrides->find(RegistrationService::RECEIPT_TEMPLATE));
        $this->assertSame(1, $this->journalCount('email_template_reset'));
        $this->assertStringContainsString(
            '>Par défaut</span>',
            $this->rowOf($this->renderList(), RegistrationService::RECEIPT_TEMPLATE)
        );
    }

    public function testTheTestSendGoesToTheSignedInAdminWithTheExampleLink(): void
    {
        $this->controller->sendTest($this->formRequest([]), ['template' => RequestEmailService::ACCEPTED_TEMPLATE]);

        $this->assertCount(1, $this->sent);
        $this->assertSame('admin@test.be', $this->sent[0]['to']);
        $this->assertSame("Votre demande d'inscription a été acceptée", $this->sent[0]['subject']);
        $this->assertStringContainsString('https://exemple.be/inscriptions/suivi/42/abc', $this->sent[0]['html']);
    }

    // ------------------------------------------------------------------
    // RBAC and breadcrumb, through the manifest
    // ------------------------------------------------------------------

    /**
     * Every « Gabarits » route the manifest declares, on one of the four
     * e-mails.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function routeProvider(): array
    {
        $edit = self::LIST . '/' . RegistrationService::RECEIPT_TEMPLATE;

        return [
            'list' => ['GET', self::LIST, 'index'],
            'edit' => ['GET', $edit, 'edit'],
            'subject' => ['POST', $edit . '/sujet', 'saveSubject'],
            'body' => ['POST', $edit . '/corps', 'saveBody'],
            'reset' => ['POST', $edit . '/defaut', 'reset'],
            'test' => ['POST', $edit . '/test', 'sendTest'],
        ];
    }

    public function testTheManifestDeclaresExactlyTheseRoutesAllRoleMinAdmin(): void
    {
        $declared = [];
        foreach ($this->manifestRoutes() as $route) {
            if ($route['controller'] !== RegistrationTemplateController::class) {
                continue;
            }
            $declared[$route['method'] . ' ' . $route['path'] . ' ' . $route['action']] = $route['role_min'];
        }

        $expected = [];
        foreach (self::routeProvider() as [$method, $path, $action]) {
            $path = str_replace('/' . RegistrationService::RECEIPT_TEMPLATE, '/{template}', $path);
            $expected[$method . ' ' . $path . ' ' . $action] = 'admin';
        }
        ksort($declared);
        ksort($expected);
        $this->assertSame($expected, $declared);
    }

    #[DataProvider('routeProvider')]
    public function testAdminReachesEveryRoute(string $method, string $path, string $action): void
    {
        $response = $this->dispatch($method, $path, $action, 'admin');

        $status = $response->getStatusCode();
        $this->assertContains($status, $method === 'GET' ? [200] : [200, 302], substr($response->getBody(), 0, 400));
        if ($action === 'saveBody') {
            $this->assertTrue((bool) (json_decode($response->getBody(), true)['success'] ?? false));
        }
        if ($action === 'saveSubject') {
            $stored = $this->overrides->find(RegistrationService::RECEIPT_TEMPLATE);
            $this->assertSame('Sujet', $stored['subject'] ?? null);
        }
        if ($action === 'sendTest') {
            $this->assertCount(1, $this->sent);
        }
    }

    #[DataProvider('routeProvider')]
    public function testOneRoleBelowIsRefused(string $method, string $path, string $action): void
    {
        $this->overrides->save(RegistrationService::RECEIPT_TEMPLATE, 'Merci', '<p>Merci {{ lien_suivi }}</p>', null);

        $response = $this->dispatch($method, $path, $action, 'chief');

        $this->assertSame(403, $response->getStatusCode(), $action);
        $stored = $this->overrides->find(RegistrationService::RECEIPT_TEMPLATE);
        $this->assertSame(
            ['Merci', '<p>Merci {{ lien_suivi }}</p>'],
            [$stored['subject'] ?? null, $stored['body_html'] ?? null]
        );
        $this->assertCount(0, $this->sent);
    }

    public function testTheListAndTheEditPageCarryTheFullBreadcrumb(): void
    {
        $list = $this->dispatch('GET', self::LIST, 'index', 'admin')->getBody();
        $edit = $this->dispatch(
            'GET',
            self::LIST . '/' . RegistrationService::UNIT_ALERT_TEMPLATE,
            'edit',
            'admin'
        )->getBody();

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
        $this->assertMatchesRegularExpression(
            '#aria-current="page">Alerte à l&\#039;unité pour une nouvelle demande d&\#039;inscription</li>#u',
            $edit
        );
    }

    // ------------------------------------------------------------------

    private function renderList(): string
    {
        $this->twig->addGlobal('current_path', self::LIST);
        $response = $this->controller->index(new Request('GET', self::LIST, [], [], [], []), []);
        $this->assertSame(200, $response->getStatusCode());

        return $response->getBody();
    }

    private function renderEdit(string $id): string
    {
        $this->twig->addGlobal('current_path', self::LIST . '/' . $id);
        $response = $this->controller->edit(new Request('GET', self::LIST . '/' . $id, [], [], [], []), [
            'template' => $id,
        ]);
        $this->assertSame(200, $response->getStatusCode());

        return $response->getBody();
    }

    /** The table row of one e-mail, found by its link. */
    private function rowOf(string $body, string $id): string
    {
        $this->assertSame(
            1,
            preg_match(
                '#<tr>\s*<td>\s*<a href="' . preg_quote(self::LIST . '/' . $id, '#') . '">.*?</tr>#su',
                $body,
                $match
            ),
            "the list has a row for {$id}"
        );

        return $match[0];
    }

    private function escaped(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, string> $fields
     */
    private function formRequest(array $fields): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request('POST', self::LIST, [], $fields + ['_csrf_token' => $token], [], []);
    }

    /**
     * The JSON payload `public/assets/js/rich-text-field.js` posts.
     *
     * @param array<string, string> $payload
     */
    private function jsonRequest(array $payload, string $path = self::LIST): Request
    {
        $payload['_csrf_token'] = CsrfGuard::generateToken();

        return new RequestWithInput('POST', $path, [], [], [], [], json_encode($payload));
    }

    private function journalCount(string $type): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $stmt->execute([$type]);

        return (int) $stmt->fetchColumn();
    }

    private function journalContext(string $type): string
    {
        $stmt = $this->pdo->prepare('SELECT description, context FROM event_log WHERE event_type = ?');
        $stmt->execute([$type]);

        return implode(' ', array_map('strval', (array) $stmt->fetch(\PDO::FETCH_ASSOC)));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function manifestRoutes(): array
    {
        $manifest = json_decode(
            (string) file_get_contents(dirname(__DIR__, 4) . '/modules/registration/module.json'),
            true,
            512,
            JSON_THROW_ON_ERROR
        );

        return $manifest['routes'];
    }

    private function dispatch(string $method, string $path, string $action, string $role): Response
    {
        AuthSession::login(1, $role . '@test.be', $role);
        $this->twig->addGlobal('current_path', $path);

        $router = new Router();
        foreach ($this->manifestRoutes() as $route) {
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

        if ($action === 'saveBody') {
            $request = $this->jsonRequest(
                ['key' => 'email_body', 'value' => '<p>Bonjour {{ prenom_enfant }} {{ lien_suivi }}</p>'],
                $path
            );
        } elseif ($method === 'POST') {
            $token = CsrfGuard::generateToken();
            $_POST['_csrf_token'] = $token;
            $request = new Request('POST', $path, [], ['_csrf_token' => $token, 'subject' => 'Sujet'], [], []);
        } else {
            $request = new Request('GET', $path, [], [], [], []);
        }
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
