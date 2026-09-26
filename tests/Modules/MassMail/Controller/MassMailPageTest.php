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
use Core\Http\FlashMessage;
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
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

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

        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new MemberBadgeRepository($this->pdo))
);
        $memberService = new MemberService(
    new MemberYearRepository($this->pdo),
    new MemberProfileRepository($connection, $encryption)
);
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

        // The composition page asks the mail service what the site's own
        // From is, to fill the « De : » of a test-mode preview when the
        // sender section has no address of its own. An unconfigured mock
        // answers [] there, which is not a shape this method ever returns.
        $mailService = $this->createMock(MailService::class);
        $mailService->method('getDefaultSender')
            ->willReturn(['address' => 'unite@test.be', 'name' => 'Test Unité']);

        $this->massMailService = new MassMailService(
            new EmailRepository($this->pdo),
            new RecipientRepository($this->pdo, $encryption),
            new EmailAttachmentRepository($this->pdo),
            new FileRepository($this->pdo),
            $listService,
            $memberService,
            $memberEmailService,
            $sectionService,
            $mailService,
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
            // The REAL importer, not a double. The refusal tested below is
            // the importer's own — a spreadsheet it will not accept — and a
            // mock told to throw would assert that the controller forwards
            // an exception somebody wrote by hand (chantier §3). Only
            // CsrfTest keeps a double here, rightly: it asserts the guard
            // runs BEFORE this service is ever reached.
            new AudienceImportService(
                $this->audienceRepository,
                new MemberResolutionRepository($this->pdo, $encryption),
                new JournalService(new JournalRepository($this->pdo))
            )
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
        // $_FILES is global and Request::getFile() reads it directly, so an
        // upload left behind here is an upload the NEXT test believes it
        // received. Cleared with the temporary files it pointed at.
        foreach ($_FILES as $file) {
            if (is_array($file) && is_string($file['tmp_name'] ?? null) && is_file($file['tmp_name'])) {
                @unlink($file['tmp_name']);
            }
        }
        $_FILES = [];
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
    // What a section chief may aim at
    // -----------------------------------------------------------------

    /**
     * A chief who is not a chef d'unité sends from their own section and
     * nowhere else. The select is locked, and a hidden field carries the
     * value — a disabled control is not submitted, so the lock alone
     * would post nothing at all.
     */
    public function testASectionChiefGetsTheSenderSectionLockedAndStillPostsIt(): void
    {
        $email = $this->createDraft('Verrou');
        AuthSession::login($this->accountId, 'chief@test.com', 'chief');

        $body = (string) $this->controller->show($this->get('/mass-mail/' . $email->id), ['id' => (string) $email->id])->getBody();

        $this->assertMatchesRegularExpression('~<select[^>]*id="mm-section"[^>]*disabled~', $body);
        $this->assertStringContainsString('<input type="hidden" name="section_id"', $body);
        $this->assertStringContainsString("seul un chef d&#039;unité peut en choisir une autre", $body);
    }

    /**
     * A list they may not target is rendered DISABLED, never dropped: a
     * draft created by a chef d'unité and reopened here must still say
     * which list it targets, and a select silently omitting the stored
     * value would save a different email than the one on screen. The
     * server re-checks every selection anyway (SECURITY.md §3).
     */
    public function testAListASectionChiefMayNotTargetIsShownDisabledRatherThanHidden(): void
    {
        $email = $this->createDraft('Listes');
        AuthSession::login($this->accountId, 'chief@test.com', 'chief');

        $body = (string) $this->controller->show($this->get('/mass-mail/' . $email->id), ['id' => (string) $email->id])->getBody();

        // « Tous les membres actifs » is a chef d'unité's list.
        $this->assertMatchesRegularExpression(
            '~<option[^>]*value="default_active_members:"[^>]*disabled~',
            $body
        );
        // The mail merge is open to every chief — the file, not a section
        // list, names the recipients.
        $this->assertMatchesRegularExpression('~<option[^>]*value="mail_merge:"(?![^>]*disabled)~', $body);
    }

    public function testAnAdminSeesEveryListEnabled(): void
    {
        $email = $this->createDraft('Listes');

        $body = (string) $this->controller->show($this->get('/mass-mail/' . $email->id), ['id' => (string) $email->id])->getBody();

        $this->assertMatchesRegularExpression(
            '~<option[^>]*value="default_active_members:"(?![^>]*disabled)~',
            $body
        );
    }

    // -----------------------------------------------------------------
    // Attachments
    // -----------------------------------------------------------------

    public function testDeletingAnAttachmentFromThePageRedirectsBackToIt(): void
    {
        $email = $this->createDraft('Pièces jointes');
        $this->pdo->exec("INSERT INTO files (relative_path, original_name, mime_type, size_bytes, module_id, role_min) VALUES ('mass_mail/attachments/plan.pdf', 'plan.pdf', 'application/pdf', 10, 'mass_mail', 'chief')");
        $fileId = (int) $this->pdo->lastInsertId();
        $this->massMailService->addAttachment($email->id, $fileId);
        $attachmentId = $this->massMailService->getAttachments($email->id)[0]->id;

        $response = $this->controller->deleteAttachment(
            $this->post(['_csrf_token' => CsrfGuard::generateToken(), 'email_id' => (string) $email->id]),
            ['id' => (string) $attachmentId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/mass-mail/' . $email->id, $response->getHeaders()['Location'] ?? null);
        $this->assertSame([], $this->massMailService->getAttachments($email->id));
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

    /**
     * #218. `role_min: chief` opens these routes to every animateur of the
     * unit; only the SENDING section of a NEW draft was ever checked. An
     * existing draft of another section was therefore readable, editable,
     * movable to test and sendable by anybody who animates anything.
     */
    public function testASectionChiefCannotTouchADraftOfAnotherSection(): void
    {
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) SELECT 'LOU02', age_branch_id, 'Meute B' FROM sections WHERE id = {$this->sectionId}");
        $otherSectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES ('x', 'blind-other-" . uniqid() . "')");
        $otherAccountId = (int) $this->pdo->lastInsertId();

        $email = $this->massMailService->createDraft(
            'Brouillon de la Meute B',
            '<p>Message</p>',
            $otherSectionId,
            Email::LIST_TYPE_DEFAULT_SECTION,
            null,
            $otherSectionId,
            [$this->scoutYearId],
            $otherAccountId,
            new SenderAuthorization(true, [], null),
            null
        );

        AuthSession::login($this->accountId, 'chief@test.com', 'chief');

        // Not 403: the same answer an unknown id gets, so the ids cannot be
        // walked to map the unit's mailings.
        $this->assertSame(404, $this->controller->show(
            $this->get('/mass-mail/' . $email->id),
            ['id' => (string) $email->id]
        )->getStatusCode());
        $this->assertSame(404, $this->controller->recipients(
            $this->get('/mass-mail/' . $email->id . '/recipients'),
            ['id' => (string) $email->id]
        )->getStatusCode());
        $this->assertSame(404, $this->controller->tracking(
            $this->get('/mass-mail/' . $email->id . '/tracking'),
            ['id' => (string) $email->id]
        )->getStatusCode());

        $status = $this->controller->changeStatus(
            $this->post([
                'action' => 'to_test',
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $email->id]
        );
        $this->assertSame(404, $status->getStatusCode());
        $this->assertSame(
            Email::STATUS_DRAFT,
            $this->massMailService->findById($email->id)?->status,
            "the state changed despite the refusal",
        );

        $saved = $this->controller->save(
            $this->post([
                'subject' => 'Réécrit',
                'body_html' => '<p>Réécrit</p>',
                'section_id' => (string) $otherSectionId,
                'list' => 'default_section:' . $otherSectionId,
                'scout_year_ids' => [(string) $this->scoutYearId],
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $email->id]
        );
        $this->assertSame(404, $saved->getStatusCode());
        $this->assertSame('Brouillon de la Meute B', $this->massMailService->findById($email->id)?->subject);

        // And the list does not even name it.
        $this->assertStringNotContainsString(
            'Brouillon de la Meute B',
            (string) $this->controller->index($this->get('/mass-mail'), [])->getBody(),
        );
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


    // -----------------------------------------------------------------
    // What a chief sees when it FAILS (issue #449)
    //
    // Twelve of this controller's catch bodies had never been executed by
    // the suite. Three of them turned out never to have been reachable at
    // all, and that is the first half of this section: they name
    // MassMailException while the call inside them raises
    // MailingListException, a SIBLING class (both \RuntimeException +
    // Core\Exception\UserFacingException, neither one's parent). The
    // failure they were written for therefore walked past them into a 500.
    //
    // The scenario is an ordinary one and needs no doubles: the « external »
    // list is contributed by the registration module (ARCHITECTURE.md
    // §7.5), and MailingListService's own docblock says the provider is
    // null « whenever that module is disabled ». An email written while it
    // was enabled still points at that list afterwards. The harness here
    // builds MailingListService without a provider, which IS that state.
    // -----------------------------------------------------------------

    public function testTheRecipientsPageStillOpensWhenItsListCannotBeResolved(): void
    {
        $email = $this->createExternalListDraft('Invitation aux inscriptions');

        $response = $this->controller->recipients(
            $this->get('/mass-mail/' . $email->id . '/recipients'),
            ['id' => (string) $email->id]
        );

        // Before the fix this threw MailingListException out of the
        // controller: the page a chief clicks to check who a mail is going
        // to answered a 500 instead of saying it could not count.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Destinataires', (string) $response->getBody());
    }

    public function testTheRecipientCountSaysWhyRatherThanCrashing(): void
    {
        $email = $this->createExternalListDraft('Invitation aux inscriptions');

        $response = $this->controller->recipientCount(
            $this->get('/mass-mail/' . $email->id . '/recipient-count'),
            ['id' => (string) $email->id]
        );

        $this->assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        // The service's own words, carried through — the count dialog is
        // where a chief finds out, and « Liste externe indisponible. » is
        // actionable where a blank number is not.
        $this->assertSame('Liste externe indisponible.', $payload['error']);
    }

    public function testLaunchingASendIsRefusedWhenItsListCannotBeResolved(): void
    {
        $email = $this->createExternalListDraft('Invitation aux inscriptions');
        $this->massMailService->moveToTest($email->id, $this->accountId);

        $response = $this->controller->changeStatus(
            $this->post(['action' => 'start_sending', '_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $email->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Liste externe indisponible.', FlashMessage::get()['message'] ?? null);
        // The state matters more than the message: the freeze must not have
        // half-happened, and the email must still be re-sendable once the
        // module is back.
        $this->assertSame(Email::STATUS_TEST, $this->massMailService->findById($email->id)?->status);
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_recipients')->fetchColumn(),
            'the freeze wrote recipients despite refusing the send'
        );
    }

    public function testAnUnknownStatusActionIsRefusedAndChangesNothing(): void
    {
        $email = $this->createDraft('Fête de section');

        $response = $this->controller->changeStatus(
            $this->post(['action' => 'to_the_moon', '_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $email->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('Action inconnue.', FlashMessage::get()['message'] ?? null);
        $this->assertSame(Email::STATUS_DRAFT, $this->massMailService->findById($email->id)?->status);
    }

    // -----------------------------------------------------------------
    // A mail merge whose audience is gone
    //
    // Genuinely gone: the audience rows are deleted while the email keeps
    // its audience_id, which is what a purge leaves behind.
    // -----------------------------------------------------------------

    public function testThePurgedAudienceIsNamedOnTheRecipientsPage(): void
    {
        $email = $this->createMergeDraftOverPurgedAudience('Convocation');

        $response = $this->controller->recipients(
            $this->get('/mass-mail/' . $email->id . '/recipients'),
            ['id' => (string) $email->id]
        );

        $this->assertSame(200, $response->getStatusCode());
        // Asserted on the rendered page rather than on FlashMessage::get():
        // the base template DISPLAYS the flash, which consumes it, so by the
        // time a test could read it the page has already shown it. Reading
        // the body is also the stronger question — whether the chief sees
        // it, not whether something was set.
        $this->assertStringContainsString(
            'réimportez le fichier Excel',
            (string) $response->getBody(),
            'the page opened without telling the chief the file has to come back'
        );
    }

    public function testTheComposerOffersTheImportZoneAgainWhenTheAudienceIsGone(): void
    {
        $email = $this->createMergeDraftOverPurgedAudience('Convocation');

        $response = $this->controller->show($this->get('/mass-mail/' . $email->id), ['id' => (string) $email->id]);

        // Not an error page: the composer simply has no audience to show,
        // which is what the branch's own comment says it is for.
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Convocation', (string) $response->getBody());
    }

    public function testAnUnknownAudienceIsNotFound(): void
    {
        $response = $this->controller->showAudience($this->get('/mass-mail/audiences/999'), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        $this->assertStringContainsString('introuvable', (string) $payload['error']);
    }

    public function testAMergePreviewOnAnEmailThatIsNotAMergeIsRefused(): void
    {
        $email = $this->createDraft('Fête de section');

        $response = $this->controller->mergePreview(
            $this->get('/mass-mail/' . $email->id . '/merge-preview'),
            ['id' => (string) $email->id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertSame("Cet email n'est pas un publipostage.", $payload['error']);
    }

    // -----------------------------------------------------------------
    // Attachments, once the email has left the draft
    // -----------------------------------------------------------------

    public function testAnAttachmentCannotBeAddedOnceTheEmailLeftDraft(): void
    {
        $email = $this->createDraft('Fête de section');
        $this->massMailService->moveToTest($email->id, $this->accountId);

        $_FILES['file'] = $this->uploadablePdf();
        $response = $this->controller->uploadAttachment(
            $this->post(['_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $email->id]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString(
            'brouillon',
            (string) (FlashMessage::get()['message'] ?? '')
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_attachments')->fetchColumn()
        );
        // And the upload itself SURVIVES — pinned rather than wished away.
        // UploadHandler::handle() writes the bytes and inserts the `files`
        // row before MassMailService::addAttachment() refuses, and this
        // controller's catch does no cleanup. Keeping the row is the
        // project-wide convention, stated in
        // Modules\Gallery\Service\StoredFileCleaner: `files` rows are an
        // audit trail, and only derived/staging assets are dropped. So this
        // asserts what the convention implies rather than a deletion that
        // would quietly contradict it.
        //
        // What no convention covers is that the upload was ACCEPTED at all
        // for an email that cannot take attachments, leaving bytes no screen
        // will ever show. That is #578, not this test's business.
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(),
            'the upload no longer survives the refusal — if that is deliberate, '
            . 'the convention in StoredFileCleaner and issue #578 both need revisiting'
        );
    }

    public function testAnAttachmentCannotBeRemovedOnceTheEmailLeftDraft(): void
    {
        $email = $this->createDraft('Fête de section');
        $attachmentId = $this->attachPdf($email->id);
        $this->massMailService->moveToTest($email->id, $this->accountId);

        $response = $this->controller->deleteAttachment(
            $this->post([
                'email_id' => (string) $email->id,
                '_csrf_token' => CsrfGuard::generateToken(),
            ]),
            ['id' => (string) $attachmentId]
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString(
            'brouillon',
            (string) (FlashMessage::get()['message'] ?? '')
        );
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_attachments')->fetchColumn(),
            'the attachment went away despite the refusal'
        );
    }

    // -----------------------------------------------------------------
    // Helpers for the failure paths above
    // -----------------------------------------------------------------

    /**
     * An email on the « external » list — the one the registration module
     * contributes. Nothing here configures a provider, which is exactly
     * the state MailingListService documents for that module being
     * disabled, and creation does not require one.
     */
    private function createExternalListDraft(string $subject): Email
    {
        return $this->massMailService->createDraft(
            $subject,
            '<p>Message</p>',
            $this->sectionId,
            Email::LIST_TYPE_EXTERNAL,
            null,
            null,
            [$this->scoutYearId],
            $this->accountId,
            new SenderAuthorization(true, [], null),
            null
        );
    }

    /**
     * A mail merge whose audience is genuinely gone: the audience row is
     * deleted after the email was attached to it, which is what a purge
     * leaves behind — an email still carrying an audience_id that resolves
     * to nothing.
     */
    private function createMergeDraftOverPurgedAudience(string $subject): Email
    {
        $audienceId = $this->audienceRepository->createAudience(
            'invites.xlsx',
            'Feuille1',
            ['Prénom', 'Email'],
            1,
            $this->accountId
        );
        $email = $this->createMergeDraft($subject, $audienceId);

        $this->pdo->prepare('DELETE FROM mass_mail_audiences WHERE id = ?')->execute([$audienceId]);

        return $email;
    }

    /**
     * A real PDF on disk, in the shape PHP hands an upload over. Real
     * because UploadHandler reads the file with finfo and refuses anything
     * whose bytes do not match the allowed types — a fabricated array with
     * type: application/pdf would be refused for the wrong reason and prove
     * nothing about the branch under test.
     *
     * @return array{name: string, tmp_name: string, error: int, size: int, type: string}
     */
    private function uploadablePdf(): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mm_attach_') ?: sys_get_temp_dir() . '/mm_attach';
        file_put_contents(
            $path,
            "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            . "2 0 obj<</Type/Pages/Kids[]/Count 0>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n"
        );

        return [
            'name' => 'programme.pdf',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
            'type' => 'application/pdf',
        ];
    }

    /**
     * Attaches a PDF through the controller's own success path, so the
     * refusal tested afterwards is refusing something that really exists.
     */
    private function attachPdf(int $emailId): int
    {
        $_FILES['file'] = $this->uploadablePdf();
        $response = $this->controller->uploadAttachment(
            $this->post(['_csrf_token' => CsrfGuard::generateToken()]),
            ['id' => (string) $emailId]
        );
        $_FILES = [];
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(
            'Pièce jointe ajoutée.',
            FlashMessage::get()['message'] ?? null,
            'the fixture could not attach anything, so the refusal below would prove nothing'
        );

        $stmt = $this->pdo->prepare('SELECT id FROM mass_mail_attachments WHERE email_id = ?');
        $stmt->execute([$emailId]);
        $id = $stmt->fetchColumn();
        // fetchColumn() answers false with no row, and (int) false is 0 — an
        // id no attachment has. Asserted rather than cast, so a fixture that
        // silently attached nothing fails here instead of making the refusal
        // test below refuse something that never existed.
        self::assertIsNumeric($id, 'no attachment row was created for this email');

        return (int) $id;
    }


    // -----------------------------------------------------------------
    // A spreadsheet the importer refuses
    // -----------------------------------------------------------------

    /**
     * The import contract is all-or-nothing and says so
     * (`AudienceImportService`: « Validation is all-or-nothing: any error
     * refuses the WHOLE file, and the AudienceImportException lists every
     * offending line at once »). Both halves are asserted here, because
     * the interesting failure is the one that refuses the file and keeps
     * half of it.
     */
    public function testARefusedSpreadsheetIsReportedLineByLineAndStoresNothing(): void
    {
        $path = $this->xlsxWithTwoBadAddresses();

        // getFile() reads $_FILES, so the upload is placed there rather
        // than in the Request — the same route PHP itself uses.
        $_FILES['file'] = [
            'name' => 'invites.xlsx',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
            'type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ];
        $response = $this->controller->importAudience(
            new Request('POST', '/mass-mail/audiences', [], [
                '_csrf_token' => CsrfGuard::generateToken(),
            ], [], []),
            []
        );

        $this->assertSame(422, $response->getStatusCode());
        $payload = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($payload);
        $this->assertFalse($payload['success']);
        // Every offending line at once, not the first one: a chief fixing
        // a file one error per upload is the failure this contract exists
        // to prevent.
        $this->assertCount(2, $payload['errors']);
        $this->assertStringContainsString('Ligne 2', (string) $payload['errors'][0]);
        $this->assertStringContainsString('Ligne 4', (string) $payload['errors'][1]);

        // Nothing stored — neither the audience nor the good rows.
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_audiences')->fetchColumn()
        );
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM mass_mail_audience_rows')->fetchColumn()
        );
    }

    /**
     * A genuine .xlsx — written with the library that reads it — holding
     * two rows the importer must refuse and one it would accept.
     */
    private function xlsxWithTwoBadAddresses(): string
    {
        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->fromArray([
            ['Prénom', 'Email'],
            ['Alice', 'pas-une-adresse'],
            ['Bob', 'bob@test.be'],
            ['Chloé', 'chloe@@test.be'],
        ], null, 'A1');

        $path = tempnam(sys_get_temp_dir(), 'mm_audience_') ?: sys_get_temp_dir() . '/mm_audience';
        $path .= '.xlsx';
        (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save($path);

        return $path;
    }

}
