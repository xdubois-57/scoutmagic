<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Http\Controller\EmailTemplateController;
use Core\Http\Request;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\Template\EmailTemplateCustomisationService;
use Core\Mail\Template\EmailTemplateOverrideRepository;
use Core\Mail\Template\EmailTemplateRegistry;
use Core\Mail\Template\EmailTemplateRenderer;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\HtmlSanitizer;
use Core\Security\RbacGuard;
use Core\Security\Role;
use Core\View\TwigFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Configuration > E-mails: the page that lets a unit reword what the site
 * sends in its name.
 *
 * Three of the four things proved here are boundaries rather than
 * behaviour, because this page's failure modes are all one-way:
 *
 * - **The RBAC floor.** Read out of public/index.php and fed to the real
 *   RbacGuard, so what is pinned is the boundary the application enforces
 *   and not a string this test also wrote. A chief d'unité (admin) reaches
 *   a great deal of this site; the wording of the login e-mail is not part
 *   of it.
 * - **`editable: false` survives a forged POST.** The page shows no
 *   control for the four authentication e-mails, but a page is not an
 *   enforcement point. Break the magic link and nobody — including the
 *   person who broke it — can log in to fix it.
 * - **Reset really deletes.** "Back to default" that left a row behind
 *   would leave the e-mail frozen on the day it was customised while the
 *   page claimed otherwise, and the next shipped improvement would silently
 *   not arrive.
 * - **The preview writes nothing.** Opening a page must never be a write:
 *   an administrator looking at an e-mail has not decided to customise it.
 *
 * @group database
 */
class EmailTemplateControllerTest extends TestCase
{
    /** An e-mail declared `editable: false` — one of the four authentication ones. */
    private const LOCKED_TEMPLATE = 'magic_link';

    /** An ordinary, rewordable e-mail. */
    private const OPEN_TEMPLATE = 'member_email_unsubscribe_confirmation';

    private \PDO $pdo;
    private EmailTemplateRegistry $registry;
    private EmailTemplateOverrideRepository $overrides;
    private EmailTemplateRenderer $renderer;
    private EmailTemplateController $controller;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();

        $twig = TwigFactory::create(dirname(__DIR__, 4) . '/core/View/templates');
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'superadmin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);

        $this->registry = new EmailTemplateRegistry();
        $this->overrides = new EmailTemplateOverrideRepository($this->pdo);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $this->renderer = new EmailTemplateRenderer($twig, $this->registry, $this->overrides, $journal);

        $this->controller = new EmailTemplateController(
            $twig,
            $this->registry,
            $this->overrides,
            $this->renderer,
            new EmailTemplateCustomisationService($this->registry, $this->overrides, new HtmlSanitizer()),
            $journal
        );

        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION = [];
        $_POST = [];
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    // ── the RBAC floor ────────────────────────────────────────────────

    /**
     * @return array<string, array{string, string}>
     */
    public static function emailRoutes(): array
    {
        return [
            'the inventory' => ['GET', '/config/emails'],
            'one e-mail' => ['GET', '/config/emails/{template}'],
            'saving the subject' => ['POST', '/config/emails/{template}/sujet'],
            'saving the body' => ['POST', '/config/emails/{template}/corps'],
            'back to default' => ['POST', '/config/emails/{template}/defaut'],
        ];
    }

    #[DataProvider('emailRoutes')]
    public function testEveryRouteIsRegisteredWithRoleMinSuperadmin(string $method, string $path): void
    {
        $this->assertSame(
            'superadmin',
            self::registeredRoleMin($method, $path),
            "{$method} {$path} must be role_min 'superadmin': what the site writes in a unit's "
                . 'name to its families is not an ordinary configuration screen.'
        );
    }

    #[DataProvider('emailRoutes')]
    public function testSuperadminIsAllowedThrough(string $method, string $path): void
    {
        AuthSession::login(1, 'superadmin@test.be', 'superadmin');

        $this->assertNull(
            (new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path))),
            "A superadmin must reach {$method} {$path}."
        );
    }

    #[DataProvider('emailRoutes')]
    public function testAdminIsRefused(string $method, string $path): void
    {
        AuthSession::login(2, 'chef-unite@test.be', 'admin');

        $response = (new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path)));

        $this->assertNotNull($response, "A chief d'unité must not reach {$method} {$path}.");
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * The route table lives in a procedural bootstrap no unit test loads,
     * so it is read at source — the same technique as
     * Tests\Core\Http\Controller\EditableContentRbacTest.
     */
    private static function registeredRoleMin(string $method, string $path): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents);

        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]' . $method . '[\'"]\s*,\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*,'
                . '[^;]*?EmailTemplateController::class\s*,\s*[\'"][a-zA-Z]+[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]/',
            $contents,
            $m
        );

        self::assertSame(1, $matched, "No addRoute registration found for {$method} {$path}");

        return $m[1];
    }

    // ── editable: false survives a forged POST ────────────────────────

    public function testSavingTheSubjectOfAnAuthenticationEmailIsRefusedAndJournalled(): void
    {
        $response = $this->controller->saveSubject(
            $this->formRequest(['subject' => 'Connectez-vous ici']),
            ['template' => self::LOCKED_TEMPLATE]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->overrides->find(self::LOCKED_TEMPLATE), 'Nothing may have been stored.');
        $this->assertRefusalJournalled();
    }

    public function testSavingTheBodyOfAnAuthenticationEmailIsRefusedAndJournalled(): void
    {
        $response = $this->controller->saveBody(
            $this->jsonRequest(['key' => 'email_body', 'value' => '<p>Cliquez ici</p>']),
            ['template' => self::LOCKED_TEMPLATE]
        );

        $this->assertSame(403, $response->getStatusCode());
        $this->assertNull($this->overrides->find(self::LOCKED_TEMPLATE));
        $this->assertRefusalJournalled();
    }

    public function testResettingAnAuthenticationEmailIsRefusedAndJournalled(): void
    {
        $response = $this->controller->reset($this->formRequest([]), ['template' => self::LOCKED_TEMPLATE]);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertRefusalJournalled();
    }

    public function testAnAuthenticationEmailIsListedWithoutAnEditLink(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/emails', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Lien de connexion', $body);
        $this->assertStringNotContainsString('/config/emails/' . self::LOCKED_TEMPLATE . '"', $body);
        $this->assertStringContainsString('Non modifiable', $body);
        // Why, not only that: a state with no reason reads as a defect.
        $this->assertStringContainsString('sauf ceux qui servent à se connecter', $body);
    }

    // ── back to default really deletes ────────────────────────────────

    public function testBackToDefaultDeletesTheRowAndTheNextEmailUsesTheShippedTemplate(): void
    {
        $this->overrides->save(
            self::OPEN_TEMPLATE,
            'Un sujet réécrit',
            '<p>Un corps entièrement réécrit par le Staff.</p>',
            1
        );
        $this->assertNotNull($this->overrides->find(self::OPEN_TEMPLATE), 'Precondition: it is customised.');

        $response = $this->controller->reset($this->formRequest([]), ['template' => self::OPEN_TEMPLATE]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull(
            $this->overrides->find(self::OPEN_TEMPLATE),
            'Back to default must delete the row, not blank it: a kept row would freeze the '
                . 'e-mail on the day it was customised.'
        );

        $context = ['site_name' => 'Unité Test', 'staffdu_email' => 'staff@exemple.be'];
        $email = $this->renderer->render(self::OPEN_TEMPLATE, $context);

        $template = $this->registry->find(self::OPEN_TEMPLATE);
        $this->assertNotNull($template);
        $this->assertSame($template->defaultSubject, $email->subject);
        $this->assertStringNotContainsString('entièrement réécrit', $email->bodyHtml);
    }

    public function testBackToDefaultIsJournalled(): void
    {
        $this->overrides->save(self::OPEN_TEMPLATE, 'Un sujet', '<p>Un corps.</p>', 1);

        $this->controller->reset($this->formRequest([]), ['template' => self::OPEN_TEMPLATE]);

        $this->assertSame(1, $this->journalCount('email_template_reset'));
    }

    public function testSavingTheSubjectStoresItAndJournalsTheIdWithoutTheContent(): void
    {
        $response = $this->controller->saveSubject(
            $this->formRequest(['subject' => 'Vous ne recevrez plus nos envois groupés']),
            ['template' => self::OPEN_TEMPLATE]
        );

        $this->assertSame(302, $response->getStatusCode());

        $stored = $this->overrides->find(self::OPEN_TEMPLATE);
        $this->assertNotNull($stored);
        $this->assertSame('Vous ne recevrez plus nos envois groupés', $stored['subject']);

        $this->assertSame(1, $this->journalCount('email_template_customised'));
        $this->assertStringNotContainsString(
            'envois groupés',
            $this->journalContext('email_template_customised'),
            'The journal keeps the id, never the wording: what a unit writes to its families '
                . 'is not something the journal needs a copy of.'
        );
    }

    public function testSavingTheBodySanitisesItBeforeStoringIt(): void
    {
        $this->controller->saveBody(
            $this->jsonRequest([
                'key' => 'email_body',
                'value' => '<p>Bonjour</p><script>alert(1)</script>',
            ]),
            ['template' => self::OPEN_TEMPLATE]
        );

        $stored = $this->overrides->find(self::OPEN_TEMPLATE);
        $this->assertNotNull($stored);
        $this->assertStringContainsString('Bonjour', $stored['body_html']);
        $this->assertStringNotContainsString('<script', $stored['body_html']);
    }

    public function testAnEmptySubjectIsRefusedWithoutTouchingWhatIsStored(): void
    {
        $this->overrides->save(self::OPEN_TEMPLATE, 'Le sujet en place', '<p>Le corps en place.</p>', 1);

        $response = $this->controller->saveSubject(
            $this->formRequest(['subject' => '   ']),
            ['template' => self::OPEN_TEMPLATE]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Le sujet en place', $this->overrides->find(self::OPEN_TEMPLATE)['subject']);
    }

    // ── the preview writes nothing ────────────────────────────────────

    public function testOpeningAnEmailWritesNothing(): void
    {
        $before = $this->overrides->customisedTemplateIds();

        $response = $this->controller->edit(
            new Request('GET', '/config/emails/' . self::OPEN_TEMPLATE, [], [], [], []),
            ['template' => self::OPEN_TEMPLATE]
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($before, $this->overrides->customisedTemplateIds());
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM email_template_overrides')->fetchColumn(),
            'Opening a page is not a decision to customise: the preview must not write a row.'
        );
    }

    public function testThePreviewShowsTheExampleValues(): void
    {
        $body = $this->controller->edit(
            new Request('GET', '/config/emails/' . self::OPEN_TEMPLATE, [], [], [], []),
            ['template' => self::OPEN_TEMPLATE]
        )->getBody();

        $template = $this->registry->find(self::OPEN_TEMPLATE);
        $this->assertNotNull($template);
        foreach ($template->variables as $variable) {
            $this->assertStringContainsString(
                $variable->example,
                $body,
                "The preview must show {$variable->name} filled in with its example value, so an "
                    . 'administrator judges the message rather than a page of braces.'
            );
        }
    }

    public function testTheEditorIsSeededWithTheUnitsNameRatherThanThePlaceholder(): void
    {
        $body = $this->controller->edit(
            new Request('GET', '/config/emails/' . self::OPEN_TEMPLATE, [], [], [], []),
            ['template' => self::OPEN_TEMPLATE]
        )->getBody();

        $this->assertStringNotContainsString(
            '{{ site_name }}',
            $body,
            'site_name is deliberately not a declared variable, so the renderer would never '
                . 'substitute it in a customised body: seeding the editor with the placeholder '
                . 'would put literal braces in the message a unit sends its families.'
        );
        $this->assertStringContainsString('Unité Test', $body);
    }

    public function testACustomisedBodyIsPreviewedWithItsVariablesFilledIn(): void
    {
        $this->overrides->save(
            self::OPEN_TEMPLATE,
            'Un sujet réécrit',
            '<p>Écrivez au Staff : {{ staffdu_email }}</p>',
            1
        );

        $body = $this->controller->edit(
            new Request('GET', '/config/emails/' . self::OPEN_TEMPLATE, [], [], [], []),
            ['template' => self::OPEN_TEMPLATE]
        )->getBody();

        $this->assertStringContainsString('Un sujet réécrit', $body);
        $this->assertStringContainsString('Écrivez au Staff : staff@exemple.be', $body);
    }

    public function testEachGroupIsTitledWithTheModuleName(): void
    {
        $registry = new EmailTemplateRegistry();
        $registry->registerModuleTemplates('rental', 'Location de salles', []);

        $this->assertSame('Location de salles', $registry->moduleLabel('rental'));
        $this->assertSame('Emails du site', $registry->moduleLabel(''));
    }

    public function testAnUnknownEmailRedirectsToTheInventoryRatherThanErroring(): void
    {
        $response = $this->controller->edit(
            new Request('GET', '/config/emails/nope', [], [], [], []),
            ['template' => 'nope']
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/config/emails', $response->getHeaders()['Location'] ?? null);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /**
     * @param array<string, string> $fields
     */
    private function formRequest(array $fields): Request
    {
        $token = CsrfGuard::generateToken();
        $_POST['_csrf_token'] = $token;

        return new Request('POST', '/config/emails', [], $fields + ['_csrf_token' => $token], [], []);
    }

    /**
     * @param array<string, string> $payload
     */
    private function jsonRequest(array $payload): Request
    {
        $payload['_csrf_token'] = CsrfGuard::generateToken();
        $raw = (string) json_encode($payload);

        // A real Request with its raw body swapped, not a mock: the CSRF
        // guard reads the parsed body and the superglobals off this same
        // object, and a stub that answered only getRawBody() would let the
        // guard pass for the wrong reason.
        return new class ('POST', '/config/emails', [], [], [], [], $raw) extends Request {
            public function __construct(
                string $method,
                string $path,
                array $query,
                array $body,
                array $cookies,
                array $server,
                private string $raw
            ) {
                parent::__construct($method, $path, $query, $body, $cookies, $server);
            }

            public function getRawBody(): string
            {
                return $this->raw;
            }
        };
    }

    private function assertRefusalJournalled(): void
    {
        $this->assertSame(
            1,
            $this->journalCount('email_template_edit_refused'),
            'A POST naming a non-editable e-mail is an attempt worth a security entry.'
        );
        $this->assertSame(
            'security',
            (string) $this->pdo->query(
                "SELECT level FROM event_log WHERE event_type = 'email_template_edit_refused'"
            )->fetchColumn()
        );
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
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return (string) ($row['context'] ?? '');
    }
}
