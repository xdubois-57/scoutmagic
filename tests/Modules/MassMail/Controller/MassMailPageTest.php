<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail\Controller;

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
use Core\Import\FunctionRepository;
use Core\Import\ImportJournalRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberEmailService;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\HtmlSanitizer;
use Core\View\TwigFactory;
use Modules\MassMail\Controller\MassMailController;
use Modules\MassMail\Repository\AudienceRepository;
use Modules\MassMail\Repository\Email;
use Modules\MassMail\Repository\EmailAttachmentRepository;
use Modules\MassMail\Repository\EmailRepository;
use Modules\MassMail\Repository\MailingListRepository;
use Modules\MassMail\Repository\MemberResolutionRepository;
use Modules\MassMail\Repository\RecipientRepository;
use Modules\MassMail\Repository\SuppressedAddressRepository;
use Modules\MassMail\Service\AudienceImportService;
use Modules\MassMail\Service\MailingListService;
use Modules\MassMail\Service\MassMailAccessService;
use Modules\MassMail\Service\MassMailService;
use Modules\MassMail\Service\MergeRenderer;
use Modules\MassMail\Service\SenderAuthorization;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\MassMail\MassMailTestHelper;
use Twig\Environment;

/**
 * The composition screen of ONE email — the page « Écrire aux répondants »
 * has been promising and never had (ARCHITECTURE.md §8.71bis).
 *
 * Rendered through the REAL templates, so a Twig runtime error (an
 * undefined filter, a renamed property, a partial called with the wrong
 * shape) fails here rather than in a browser. The screen is where a whole
 * unit's mail gets written, and until this file existed no test opened it
 * at all: `MassMailController` had a CSRF spot-check and an error-leak
 * check, and nothing that asked what a chief actually sees.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MassMailPageTest extends TestCase
{
    private \PDO $pdo;
    private MassMailController $controller;
    private MassMailService $massMailService;
    private AudienceRepository $audienceRepository;
    private Environment $twig;
    private int $accountId;
    private int $sectionId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        MassMailTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $connection = Connection::withPdo($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES (?, ?)');
        $stmt->execute([
            $encryption->encrypt('chief@test.com', 'user_accounts.email'),
            $encryption->blindIndex('chief@test.com', 'email'),
        ]);
        $this->accountId = (int) $this->pdo->lastInsertId();

        [$label, $yearStart, $yearEnd] = DatabaseTestHelper::scoutYear();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date, is_current) VALUES ('{$label}', '{$yearStart}', '{$yearEnd}', 1)");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('LOU', 'Louveteaux', 1)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Meute A')");
        $this->sectionId = (int) $this->pdo->lastInsertId();

        $sectionService = new SectionService($connection, $encryption, new MemberBadgeRepository($this->pdo));
        $memberService = new MemberService(new MemberYearRepository($this->pdo), $encryption, $connection);
        $listService = new MailingListService(
            new MailingListRepository($this->pdo),
            new MemberResolutionRepository($this->pdo, $encryption),
            $sectionService,
            new FunctionRepository($this->pdo)
        );
        $this->audienceRepository = new AudienceRepository($this->pdo, $encryption);

        $memberEmailService = new MemberEmailService(
            new MemberEmailRepository($this->pdo, $encryption),
            $this->createMock(MailService::class),
            EmailTemplateRendererFactory::overTestDatabase($this->pdo, $this->createMock(Environment::class)),
            new JournalService(new JournalRepository($this->pdo)),
            $sectionService,
            $memberService,
            new ScoutYearService($this->pdo),
            'https://example.test',
            'Test Unité'
        );

        $this->massMailService = new MassMailService(
            new EmailRepository($this->pdo),
            new RecipientRepository($this->pdo, $encryption),
            new EmailAttachmentRepository($this->pdo),
            new FileRepository($this->pdo),
            $listService,
            $memberService,
            $memberEmailService,
            $sectionService,
            $this->createMock(MailService::class),
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new JournalService(new JournalRepository($this->pdo)),
            new HtmlSanitizer(),
            new ScoutYearService($this->pdo),
            new ImportJournalRepository($this->pdo),
            sys_get_temp_dir(),
            $this->audienceRepository,
            new MemberResolutionRepository($this->pdo, $encryption),
            new SuppressedAddressRepository($this->pdo),
            new MergeRenderer()
        );

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $twig = TwigFactory::create($templateDir, false, ['mass_mail' => dirname(__DIR__, 4) . '/modules/mass_mail/views']);
        $twig->addGlobal('site_name', 'Test Unit');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'chief');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/mass-mail');
        $this->twig = $twig;

        $this->controller = new MassMailController(
            $twig,
            $this->massMailService,
            $listService,
            new MassMailAccessService($memberService, $sectionService),
            $memberService,
            $sectionService,
            new ScoutYearService($this->pdo),
            new ImportJournalRepository($this->pdo),
            new SettingService(new SettingRepository($this->pdo)),
            new UploadHandler(new FileRepository($this->pdo), sys_get_temp_dir()),
            new FileRepository($this->pdo),
            $this->createMock(AudienceImportService::class)
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        AuthSession::login($this->accountId, 'chief@test.com', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
    }

    // -----------------------------------------------------------------
    // The page itself
    // -----------------------------------------------------------------

    public function testTheEmailPageRendersItsSubjectAndItsThreeViews(): void
    {
        $email = $this->createDraft('Fête de section');

        $response = $this->controller->show($this->get('/mass-mail/' . $email->id), ['id' => (string) $email->id]);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Fête de section', $body);
        $this->assertStringContainsString('Composition', $body);
        $this->assertStringContainsString('Destinataires', $body);
        // The « Suivi » tab only appears once there is something to
        // follow — a draft has sent nothing.
        $this->assertStringNotContainsString('>Suivi<', $body);
        $this->assertStringContainsString('action="/mass-mail/' . $email->id . '"', $body);
    }

    /**
     * The whole defect in one assertion: this route used to answer a JSON
     * payload, and « Écrire aux répondants » redirected a chief to it.
     */
    public function testTheEmailPageIsHtmlAndNotTheJsonItUsedToBe(): void
    {
        $email = $this->createDraft('Rappel');

        $page = $this->controller->show($this->get('/mass-mail/' . $email->id), ['id' => (string) $email->id]);

        $this->assertStringContainsString('<!DOCTYPE html>', (string) $page->getBody());
        $this->assertStringNotContainsString('"success"', (string) $page->getBody());
    }

    // -----------------------------------------------------------------
    // Creation, as a page
    // -----------------------------------------------------------------

    public function testTheCreationPageOffersAnEmptyComposer(): void
    {
        $response = $this->controller->createForm($this->get('/mass-mail/new'), []);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Nouvel email', $body);
        $this->assertStringContainsString('action="/mass-mail"', $body);
        $this->assertStringContainsString('Créer le brouillon', $body);
        // Nothing to attach to and nothing to move yet — both wait for the
        // draft to exist.
        $this->assertStringNotContainsString('Passer en mode test', $body);
    }

    public function testCreatingADraftLandsOnItsOwnPage(): void
    {
        $response = $this->controller->create(
            $this->post([
                '_csrf_token' => CsrfGuard::generateToken(),
                'section_id' => (string) $this->sectionId,
                'list' => 'default_section:' . $this->sectionId,
                'scout_year_ids' => [(string) $this->scoutYearId],
                'subject' => 'Première réunion',
                'body_html' => '<p>Bienvenue.</p>',
            ]),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $location = (string) ($response->getHeaders()['Location'] ?? '');
        $this->assertMatchesRegularExpression('~^/mass-mail/\d+$~', $location);
        $created = $this->massMailService->findById((int) substr($location, strrpos($location, '/') + 1));
        $this->assertSame('Première réunion', $created?->subject);
    }

    public function testARefusedCreationKeepsWhatWasTyped(): void
    {
        $response = $this->controller->create(
            $this->post([
                '_csrf_token' => CsrfGuard::generateToken(),
                'section_id' => (string) $this->sectionId,
                'list' => 'default_section:' . $this->sectionId,
                'scout_year_ids' => [(string) $this->scoutYearId],
                'subject' => '',
                'body_html' => '<p>Un texte à ne pas perdre.</p>',
            ]),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Un texte à ne pas perdre.', (string) $response->getBody());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_emails')->fetchColumn());
    }

    public function testAnUnknownEmailAnswersTheSiteFourOhFourPage(): void
    {
        $response = $this->controller->show($this->get('/mass-mail/999'), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testTheSuiviTabAppearsOnceTheSendHasStarted(): void
    {
        $email = $this->createDraft('Camp');
        $this->pdo->exec("UPDATE mass_mail_emails SET status = 'sent' WHERE id = {$email->id}");

        $body = (string) $this->controller->show($this->get('/mass-mail/' . $email->id), ['id' => (string) $email->id])->getBody();

        $this->assertStringContainsString('/mass-mail/' . $email->id . '/tracking', $body);
    }

    /**
     * A sent email is not a form any more. The editor is replaced by the
     * message as it went out, and nothing on the page can post a change.
     */
    public function testASentEmailIsShownReadOnly(): void
    {
        $email = $this->createDraft('Déjà parti');
        $this->pdo->exec("UPDATE mass_mail_emails SET status = 'sent' WHERE id = {$email->id}");

        $body = (string) $this->controller->show($this->get('/mass-mail/' . $email->id), ['id' => (string) $email->id])->getBody();

        $this->assertStringContainsString('<fieldset disabled>', $body);
        $this->assertStringNotContainsString('>Enregistrer<', $body);
    }

    // -----------------------------------------------------------------
    // Saving
    // -----------------------------------------------------------------

    public function testSavingTheFormUpdatesTheDraftAndRedirectsBackToItsPage(): void
    {
        $email = $this->createDraft('Avant');

        $response = $this->controller->save(
            $this->post([
                '_csrf_token' => CsrfGuard::generateToken(),
                'section_id' => (string) $this->sectionId,
                'list' => 'default_section:' . $this->sectionId,
                'scout_year_ids' => [(string) $this->scoutYearId],
                'subject' => 'Après',
                'body_html' => '<p>Rendez-vous samedi.</p>',
            ]),
            ['id' => (string) $email->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/mass-mail/' . $email->id, $response->getHeaders()['Location'] ?? null);
        $reloaded = $this->massMailService->findById($email->id);
        $this->assertNotNull($reloaded);
        $this->assertSame('Après', $reloaded->subject);
        $this->assertStringContainsString('Rendez-vous samedi.', $reloaded->bodyHtml);
    }

    /**
     * A refused save must give the body back. A mail-merge message is
     * long, and losing it to a missing subject would be unforgivable.
     */
    public function testARefusedSaveRerendersThePageCarryingWhatWasTyped(): void
    {
        $email = $this->createDraft('Avant');

        $response = $this->controller->save(
            $this->post([
                '_csrf_token' => CsrfGuard::generateToken(),
                'section_id' => (string) $this->sectionId,
                'list' => 'default_section:' . $this->sectionId,
                'scout_year_ids' => [(string) $this->scoutYearId],
                'subject' => '',
                'body_html' => '<p>Un très long message qu\'il ne faut pas perdre.</p>',
            ]),
            ['id' => (string) $email->id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString("qu&#039;il ne faut pas perdre", $body);
        $this->assertSame('Avant', $this->massMailService->findById($email->id)?->subject);
    }

    public function testSavingWithoutAValidCsrfTokenChangesNothing(): void
    {
        $email = $this->createDraft('Intact');

        $response = $this->controller->save(
            $this->post([
                '_csrf_token' => 'nope',
                'section_id' => (string) $this->sectionId,
                'list' => 'default_section:' . $this->sectionId,
                'subject' => 'Modifié',
                'body_html' => '<p>x</p>',
            ]),
            ['id' => (string) $email->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Intact', $this->massMailService->findById($email->id)?->subject);
    }

    // -----------------------------------------------------------------
    // Status transitions, as forms
    // -----------------------------------------------------------------

    public function testTheStatusFormMovesADraftToTestAndBack(): void
    {
        $email = $this->createDraft('Bascule');

        $toTest = $this->controller->changeStatus(
            $this->post(['_csrf_token' => CsrfGuard::generateToken(), 'action' => 'to_test']),
            ['id' => (string) $email->id]
        );
        $this->assertSame(302, $toTest->getStatusCode());
        $this->assertSame(Email::STATUS_TEST, $this->massMailService->findById($email->id)?->status);

        $this->controller->changeStatus(
            $this->post(['_csrf_token' => CsrfGuard::generateToken(), 'action' => 'to_draft']),
            ['id' => (string) $email->id]
        );
        $this->assertSame(Email::STATUS_DRAFT, $this->massMailService->findById($email->id)?->status);
    }

    // -----------------------------------------------------------------
    // The « Destinataires » view
    // -----------------------------------------------------------------

    public function testTheRecipientsPageCountsWhoAListWouldReachRightNow(): void
    {
        $email = $this->createDraft('Qui ?');

        $response = $this->controller->recipients($this->get('/mass-mail/' . $email->id . '/recipients'), ['id' => (string) $email->id]);

        $this->assertSame(200, $response->getStatusCode());
        $body = (string) $response->getBody();
        $this->assertStringContainsString('Meute A', $body);
        $this->assertStringContainsString('ne désigne actuellement personne', $body);
    }

    public function testTheRecipientsPageListsTheMailMergeFileRowByRow(): void
    {
        $audienceId = $this->audienceRepository->createAudience('camp.xlsx', 'Feuille1', ['Email', 'Prénom'], 2, $this->accountId);
        $this->audienceRepository->createRow($audienceId, 2, null, 'kaa@example.test', ['Email' => 'kaa@example.test', 'Prénom' => 'Kaa']);
        $this->audienceRepository->createRow($audienceId, 3, null, 'baloo@example.test', ['Email' => 'baloo@example.test', 'Prénom' => 'Baloo']);
        $email = $this->createMergeDraft('Publipostage', $audienceId);

        $body = (string) $this->controller
            ->recipients($this->get('/mass-mail/' . $email->id . '/recipients'), ['id' => (string) $email->id])
            ->getBody();

        $this->assertStringContainsString('camp.xlsx', $body);
        $this->assertStringContainsString('kaa@example.test', $body);
        $this->assertStringContainsString('Baloo', $body);
    }

    public function testOnceFrozenTheRecipientsPageSendsTheReaderToTheTrackingView(): void
    {
        $email = $this->createDraft('Parti');
        $this->pdo->exec("UPDATE mass_mail_emails SET status = 'sent' WHERE id = {$email->id}");

        $body = (string) $this->controller
            ->recipients($this->get('/mass-mail/' . $email->id . '/recipients'), ['id' => (string) $email->id])
            ->getBody();

        $this->assertStringContainsString('figée', $body);
        $this->assertStringContainsString('/mass-mail/' . $email->id . '/tracking', $body);
    }

    // -----------------------------------------------------------------
    // RBAC, through the real Router + guard
    // -----------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function pageRouteProvider(): array
    {
        return [
            'composition' => ['/mass-mail/{id}', 'show'],
            'destinataires' => ['/mass-mail/{id}/recipients', 'recipients'],
            'création' => ['/mass-mail/new', 'createForm'],
        ];
    }

    /**
     * @dataProvider pageRouteProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pageRouteProvider')]
    public function testAChiefReachesTheEmailRoutes(string $path, string $action): void
    {
        $email = $this->createDraft('RBAC');
        AuthSession::login($this->accountId, 'chief@test.com', 'chief');

        $response = $this->frontController($path, $action)
            ->handle(new Request('GET', str_replace('{id}', (string) $email->id, $path), [], [], [], []));

        $this->assertNotSame(403, $response->getStatusCode());
    }

    /**
     * @dataProvider pageRouteProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('pageRouteProvider')]
    public function testAnIntendantIsRefusedTheEmailRoutes(string $path, string $action): void
    {
        $email = $this->createDraft('RBAC');
        AuthSession::login($this->accountId, 'intendant@test.com', 'intendant');

        $response = $this->frontController($path, $action)
            ->handle(new Request('GET', str_replace('{id}', (string) $email->id, $path), [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function frontController(string $path, string $action): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', $path, MassMailController::class, $action, 'chief');

        $configFile = sys_get_temp_dir() . '/test_mass_mail_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");

        $frontController = new FrontController($router, $this->twig, new AppConfig($configFile));
        $frontController->registerController(MassMailController::class, $this->controller);

        return $frontController;
    }

    private function createDraft(string $subject): Email
    {
        return $this->massMailService->createDraft(
            $subject,
            '<p>Message</p>',
            $this->sectionId,
            Email::LIST_TYPE_DEFAULT_SECTION,
            null,
            $this->sectionId,
            [$this->scoutYearId],
            $this->accountId,
            new SenderAuthorization(true, [], null),
            null
        );
    }

    private function createMergeDraft(string $subject, int $audienceId): Email
    {
        return $this->massMailService->createDraft(
            $subject,
            '<p>Bonjour {{Prénom}}</p>',
            $this->sectionId,
            Email::LIST_TYPE_MAIL_MERGE,
            null,
            null,
            [],
            $this->accountId,
            new SenderAuthorization(true, [], null),
            $audienceId
        );
    }

    private function get(string $path): Request
    {
        return new Request('GET', $path, [], [], [], []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        return new Request('POST', '/mass-mail', [], $body, [], []);
    }

}
