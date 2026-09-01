<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Config\AppConfig;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\Controller\SupportController;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Security\SecretManager;
use Core\Statistics\InstallationDateService;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsPayloadBuilder;
use Core\Statistics\StatisticsSender;
use Core\Support\Ticket\SupportArchiveSender;
use Core\Support\Ticket\SupportTicketSender;
use Core\Support\Ticket\TicketIdentityService;
use Core\Statistics\StatisticsTransportInterface;
use Core\Statistics\StatisticsTransportResponse;
use Core\Support\SupportPackageState;
use Core\Support\Task\GenerateSupportPackageHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * A transport that records the call instead of making it. The page's test
 * send is the one action on this controller that would otherwise reach the
 * network, and what has to be asserted about it — that it went out at all,
 * and to where — is exactly what a fake can answer.
 *
 * Declared here rather than shared with Tests\Core\Statistics: the PSR-4
 * autoloader maps Tests\ to a file per class, so a helper living inside
 * another test file only resolves when that file happens to have been
 * loaded first.
 */
final class SupportRecordingTransport implements StatisticsTransportInterface
{
    /** @var array<int, array{url: string, body: string, token: string}> */
    public array $calls = [];

    public function __construct(private ?StatisticsTransportResponse $response = null)
    {
    }

    public function post(string $url, string $jsonBody, string $bearerToken, string $userAgent): StatisticsTransportResponse
    {
        $this->calls[] = ['url' => $url, 'body' => $jsonBody, 'token' => $bearerToken];

        return $this->response ?? StatisticsTransportResponse::response(200, '');
    }
}

/**
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class SupportControllerTest extends TestCase
{
    private \PDO $pdo;
    private SupportController $controller;
    private SettingService $settings;
    private SettingRepository $settingRepository;
    private Environment $twig;
    private string $projectRoot;
    private SecretManager $secretManager;
    private InstallationIdentityService $identityService;
    private SupportRecordingTransport $ticketTransport;
    private SupportRecordingTransport $transport;
    private int $userId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingRepository = new SettingRepository($this->pdo);
        $this->settings = new SettingService($this->settingRepository);

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-support-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);
        file_put_contents($this->projectRoot . '/VERSION', "1.0.33\n");

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        $this->registerSettings();

        $this->identityService = new InstallationIdentityService($this->settings, $this->secretManager);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $this->twig = new Environment(new FilesystemLoader($templateDir), ['cache' => false, 'autoescape' => 'html']);
        // asset() is what base.html.twig references every static file through
        // (Core\View\TwigFactory); the bare path is enough for a test render.
        $this->twig->addFunction(new \Twig\TwigFunction('asset', static fn (string $path): string => $path));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_field', fn() => '<input type="hidden" name="_csrf_token" value="test">', ['is_safe' => ['html']]));
        $this->twig->addFunction(new \Twig\TwigFunction('get_flash', fn() => null));
        $this->twig->addFunction(new \Twig\TwigFunction('csrf_token', fn() => 'test'));
        $this->twig->addFilter(new \Twig\TwigFilter('french_date', fn($d) => (string) $d));
        $this->twig->addGlobal('site_name', 'Test');
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('csp_nonce', 'n');

        $payloadBuilder = new StatisticsPayloadBuilder(
            $this->settings,
            $this->pdo,
            $this->identityService,
            $this->projectRoot
        );
        $this->transport = new SupportRecordingTransport();
        $this->ticketTransport = new SupportRecordingTransport();
        $ticketIdentity = new TicketIdentityService($this->settings, $this->identityService, $journalService);
        $this->controller = new SupportController(
            $this->twig,
            $this->settings,
            $journalService,
            $payloadBuilder,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new StatisticsSender(
                $this->settings,
                $payloadBuilder,
                $this->identityService,
                $this->transport,
                $journalService,
                $this->pdo,
                '1.0.33'
            ),
            new SupportTicketSender(
                $this->settings,
                $ticketIdentity,
                $this->ticketTransport,
                $journalService,
                '1.0.33'
            ),
            $ticketIdentity
        );

        $stmt = $this->pdo->prepare('INSERT INTO user_accounts (email_encrypted, email_blind_index, is_super_admin) VALUES (?, ?, 1)');
        $stmt->execute(['enc', 'idx']);
        $this->userId = (int) $this->pdo->lastInsertId();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        AuthSession::login($this->userId, 'admin@test.example', 'superadmin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_POST = [];
        self::removeTree($this->projectRoot);
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            if (is_file($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }

    private function registerSettings(): void
    {
        $this->settings->register('base_url', 'https://unite-exemple.be', 'url', 'L', 'D');
        $this->settings->register('statistics_enabled', '1', 'boolean', 'L', 'D');
        $this->settings->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'L', 'D');
        $this->settings->register('support_email', 'support@scoutmagic.be', 'email', 'L', 'D', null, null, null, false);
        $this->settings->register(InstallationIdentityService::INSTALLATION_ID_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportController::LAST_SUCCESS_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportController::LAST_FAILURE_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportController::LAST_FAILURE_REASON_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportPackageState::FILE_ID, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportPackageState::GENERATED_AT, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportTicketSender::LAST_REFERENCE_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportTicketSender::LAST_SENT_AT_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportTicketSender::CATEGORIES_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportArchiveSender::ARCHIVE_SENT_AT_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        $this->settings->register(SupportArchiveSender::ARCHIVE_REFERENCE_SETTING, '', 'text', 'L', 'D', null, null, null, false);
        InstallationDateService::register($this->settings);
        $this->settings->clearCache();
    }

    private function issueCsrfToken(): string
    {
        $token = CsrfGuard::generateToken();
        $_SESSION['_csrf_token'] = $token;
        $_POST['_csrf_token'] = $token;

        return $token;
    }

    // ── The support ticket (roadmap IT-25) ─────────────────────────────

    public function testTheFormOffersTheShippedCategoriesAndSaysWhatTravels(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Contacter le support', $body);
        $this->assertStringContainsString('Import Desk', $body);
        $this->assertStringContainsString("l'identifiant de cette installation", $body);
        $this->assertStringContainsString('la version du site', $body);
        $this->assertStringContainsString('la version de PHP', $body);
        // Pre-filled with the signed-in superadmin's own address.
        $this->assertStringContainsString('admin@test.example', $body);
    }

    public function testASentTicketShowsItsReferenceAndTheLocalStatus(): void
    {
        $this->issueCsrfToken();
        $this->ticketTransport = $this->respondWith(200, (string) json_encode([
            'status' => 'accepted',
            'ticket_reference' => 'SUP-7KQ4F2',
            'categories' => [['value' => 'other', 'label' => 'Autre']],
        ]));

        $response = $this->sendTicket();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            'SUP-7KQ4F2',
            (string) $this->settings->get(SupportTicketSender::LAST_REFERENCE_SETTING)
        );

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('SUP-7KQ4F2', $body);
        $this->assertStringContainsString('Envoyé', $body);
    }

    /**
     * The one thing this page must never lose. A redirect carries a flash
     * message and not a form, so a failed send re-renders.
     */
    public function testAnUnreachableServerKeepsTheAdministratorsInputAndMarksNothingSent(): void
    {
        $this->issueCsrfToken();
        $this->ticketTransport = $this->respondWith(503, 'nope');

        $response = $this->sendTicket();

        $this->assertSame(200, $response->getStatusCode(), 're-rendered, not redirected');
        $this->assertStringContainsString('Mon import Desk ne passe plus', $response->getBody());
        $this->assertSame('', (string) $this->settings->get(SupportTicketSender::LAST_REFERENCE_SETTING));
        $this->assertStringNotContainsString('SUP-', $response->getBody());
    }

    /**
     * A refusal is still an answer, and the list it carries is exactly
     * what the instance needs to correct itself.
     */
    public function testARefusalRemembersTheCategoriesTheReceiverPublished(): void
    {
        $this->issueCsrfToken();
        $this->ticketTransport = $this->respondWith(200, (string) json_encode([
            'status' => 'refused',
            'reason' => 'unknown_category',
            'categories' => [['value' => 'nouvelle', 'label' => 'Nouvelle catégorie']],
        ]));

        $this->sendTicket();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('Nouvelle catégorie', $body);
    }

    /**
     * The description is the one field a person wrote. It is on its way to
     * somebody else's installation; it has no business in this one's
     * journal, on success or on failure.
     */
    public function testTheJournalNeverCarriesTheDescription(): void
    {
        $this->issueCsrfToken();
        $this->ticketTransport = $this->respondWith(200, (string) json_encode([
            'status' => 'accepted',
            'ticket_reference' => 'SUP-7KQ4F2',
        ]));

        $this->sendTicket();

        $rows = $this->pdo->query(
            "SELECT event_type, level, description, context FROM event_log WHERE event_type = 'support_ticket_sent'"
        )->fetchAll(\PDO::FETCH_ASSOC);

        $this->assertCount(1, $rows);
        $this->assertSame('info', $rows[0]['level']);
        $this->assertStringContainsString('SUP-7KQ4F2', (string) $rows[0]['context']);
        $this->assertStringNotContainsString('Mon import Desk', (string) $rows[0]['context']);
        $this->assertStringNotContainsString('Mon import Desk', (string) $rows[0]['description']);
    }

    public function testAnEmptyDescriptionIsRefusedBeforeAnythingLeaves(): void
    {
        $this->issueCsrfToken();

        $response = $this->controller->sendTicket(new Request('POST', '/config/support/ticket', [], [
            '_csrf_token' => (string) $_POST['_csrf_token'],
            'ticket_category' => 'desk_import',
            'ticket_description' => '   ',
            'ticket_contact_email' => 'chef@unite.be',
        ], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->ticketTransport->calls, 'nothing left the site');
    }

    /**
     * The guards are the report's own: a destination that is not HTTPS or
     * not a public name means the form is not offered at all, rather than
     * offered and then failing.
     */
    public function testACleartextDestinationHidesTheFormRatherThanFailingLater(): void
    {
        $this->settings->setInternal('statistics_destination', 'http://scoutmagic.be');

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Contacter le support', $body);
        $this->assertStringContainsString("n'est pas en HTTPS", $body);
        $this->assertStringNotContainsString('name="ticket_description"', $body);
    }

    private function respondWith(int $status, string $body): SupportRecordingTransport
    {
        $transport = new SupportRecordingTransport(StatisticsTransportResponse::response($status, $body));

        $journalService = new JournalService(new JournalRepository($this->pdo));
        $ticketIdentity = new TicketIdentityService($this->settings, $this->identityService, $journalService);
        $payloadBuilder = new StatisticsPayloadBuilder(
            $this->settings,
            $this->pdo,
            $this->identityService,
            $this->projectRoot
        );

        $this->controller = new SupportController(
            $this->twig,
            $this->settings,
            $journalService,
            $payloadBuilder,
            new SchedulerService(new SchedulerRepository($this->pdo)),
            new StatisticsSender(
                $this->settings,
                $payloadBuilder,
                $this->identityService,
                $this->transport,
                $journalService,
                $this->pdo,
                '1.0.33'
            ),
            new SupportTicketSender($this->settings, $ticketIdentity, $transport, $journalService, '1.0.33'),
            $ticketIdentity
        );

        return $transport;
    }

    /**
     * @param array<string, string> $overrides
     */
    private function sendTicket(array $overrides = []): \Core\Http\Response
    {
        return $this->controller->sendTicket(new Request('POST', '/config/support/ticket', [], array_replace([
            '_csrf_token' => (string) $_POST['_csrf_token'],
            'ticket_category' => 'desk_import',
            'ticket_description' => 'Mon import Desk ne passe plus depuis hier.',
            'ticket_contact_email' => 'chef@unite.be',
            // Both boxes: the form cannot be submitted without them, and
            // the server refuses a POST that omits either.
            'archive_acknowledged' => '1',
            'probe_acknowledged' => '1',
        ], $overrides), [], []), []);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function missingAcknowledgementProvider(): array
    {
        return [
            'no archive box' => [['archive_acknowledged' => '']],
            'no probe box' => [['probe_acknowledged' => '']],
            'neither box' => [['archive_acknowledged' => '', 'probe_acknowledged' => '']],
        ];
    }

    /**
     * The two boxes are the whole consent for what leaves beside the
     * message, and `required` in the browser is a decoration a hand-made
     * POST walks past. Verified on the server, which is what posting
     * without them proves.
     *
     * @param array<string, string> $overrides
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingAcknowledgementProvider')]
    public function testNoTicketLeavesWithoutBothAcknowledgements(array $overrides): void
    {
        $this->issueCsrfToken();
        $this->ticketTransport = $this->respondWith(200, (string) json_encode([
            'status' => 'accepted',
            'ticket_reference' => 'SUP-7KQ4F2',
        ]));

        $this->sendTicket($overrides);

        $this->assertSame([], $this->ticketTransport->calls, 'nothing may leave without both boxes');
        $this->assertSame(
            '',
            (string) $this->settings->get(SupportTicketSender::LAST_REFERENCE_SETTING)
        );
    }

    /**
     * Roadmap IT-26: the consent screen names what is in the archive and
     * how big it is, BEFORE the box that agrees to transmit it — and the
     * box is verified on the server, which is what this test proves by
     * posting without it.
     */
    public function testTheArchiveIsNotTransmittedWithoutTheAcknowledgement(): void
    {
        $this->issueCsrfToken();
        $this->settings->setInternal(SupportTicketSender::LAST_REFERENCE_SETTING, 'SUP-7KQ4F2');

        $response = $this->controller->sendArchive(new Request('POST', '/config/support/ticket/archive', [], [
            '_csrf_token' => (string) $_POST['_csrf_token'],
            // No archive_acknowledged at all — exactly what a hand-made
            // POST looks like.
        ], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('', (string) $this->settings->get(SupportArchiveSender::ARCHIVE_REFERENCE_SETTING));
    }

    public function testWithoutATicketThereIsNothingToAttachAnArchiveTo(): void
    {
        $this->issueCsrfToken();

        $response = $this->controller->sendArchive(new Request('POST', '/config/support/ticket/archive', [], [
            '_csrf_token' => (string) $_POST['_csrf_token'],
            'archive_acknowledged' => '1',
        ], [], []), []);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('', (string) $this->settings->get(SupportArchiveSender::ARCHIVE_REFERENCE_SETTING));
    }

    public function testIndexRendersTheThreeBlocksAndTheWarning(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), []);
        $body = $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Statistiques d\'utilisation', $body);
        $this->assertStringContainsString('État des envois', $body);
        $this->assertStringContainsString('Aperçu de ce qui est envoyé', $body);
        $this->assertStringContainsString('Paquet de support', $body);
        $this->assertStringContainsString('alert-warning', $body);
        $this->assertStringContainsString('mais cela ne peut pas être garanti', $body);
        $this->assertStringContainsString('support@scoutmagic.be', $body);
    }

    public function testTheExplanationDoesNotClaimTheReportIsAnonymous(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('n\'est pas anonyme', $body);
    }

    /**
     * The payload is a couple of dozen lines of JSON answering a question
     * most people on this page are not asking. Unfolded, it pushed the
     * support-package block — what most of them came for — below the fold.
     */
    public function testThePayloadPreviewIsCollapsedByDefault(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('data-bs-target="#support-payload"', $body);
        $this->assertStringContainsString('aria-expanded="false"', $body);
        $this->assertStringContainsString('<div class="collapse mt-3" id="support-payload">', $body);
        $this->assertStringNotContainsString('collapse show" id="support-payload"', $body);
    }

    public function testThePreviewIsProducedEvenWhenReportingIsDisabled(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_enabled', '0');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('statistics_schema_version', $body);
        $this->assertStringContainsString('installation_id', $body);
        $this->assertStringContainsString('unite-exemple.be', $body);
    }

    public function testNoSendStateIsShownAsSuchRatherThanAsZero(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('Aucun envoi n\'a encore abouti', $body);
    }

    public function testSendStateIsShownWhenPresent(): void
    {
        $this->settingRepository->updateValue(null, SupportController::LAST_SUCCESS_SETTING, '2026-08-19T03:00:00+00:00');
        $this->settingRepository->updateValue(null, SupportController::LAST_FAILURE_SETTING, '2026-08-18T03:00:00+00:00');
        $this->settingRepository->updateValue(null, SupportController::LAST_FAILURE_REASON_SETTING, 'http_500');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('2026-08-19T03:00:00+00:00', $body);
        $this->assertStringContainsString('http_500', $body);
    }

    /**
     * On the receiver, "no report is ever sent" is the correct, permanent
     * answer — so the panel says it, rather than leaving a first-day
     * failure sitting there dated and unexplained.
     */
    public function testTheReceiverIsToldWhyItNeverSendsAnything(): void
    {
        $this->settingRepository->updateValue(null, 'base_url', 'https://www.scoutmagic.be');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('il ne s\'envoie pas de rapport à lui-même', $body);
        $this->assertStringNotContainsString('Aucun envoi n\'a encore abouti', $body);
    }

    public function testAnOrdinaryInstallationIsNotToldItIsTheReceiver(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('il ne s\'envoie pas de rapport à lui-même', $body);
    }

    public function testNoNextScheduledSendIsAdvertised(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('Prochain envoi', $body);
    }

    public function testNoBugReportFormIsOffered(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('name="contact_email"', $body);
        $this->assertStringNotContainsString('name="description"', $body);
        $this->assertStringNotContainsString('name="contact_name"', $body);
    }

    public function testTheSecretNeverAppearsInTheResponse(): void
    {
        $secret = $this->identityService->getSecret();
        $this->assertNotNull($secret);

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString($secret, $body);
        $this->assertStringNotContainsString('statistics_secret', $body);
    }

    public function testSavingRequiresAValidCsrfToken(): void
    {
        $request = new Request('POST', '/config/support/statistics', [], ['_csrf_token' => 'wrong', 'statistics_enabled' => ''], [], []);
        $response = $this->controller->saveStatistics($request, []);

        $this->assertSame(302, $response->getStatusCode());
        $this->settings->clearCache();
        $this->assertSame('1', $this->settings->get('statistics_enabled'));
    }

    public function testDisablingReportingPersistsAndIsJournaled(): void
    {
        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/config/support/statistics', [], ['_csrf_token' => $token], [], []);

        $this->controller->saveStatistics($request, []);

        $this->settings->clearCache();
        $this->assertSame('0', $this->settings->get('statistics_enabled'));
        $this->assertSame(['statistics_reporting_disabled'], $this->journalEventTypes());
    }

    public function testEnablingReportingIsJournaled(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_enabled', '0');
        $this->settings->clearCache();

        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/config/support/statistics', [], ['_csrf_token' => $token, 'statistics_enabled' => '1'], [], []);

        $this->controller->saveStatistics($request, []);

        $this->settings->clearCache();
        $this->assertSame('1', $this->settings->get('statistics_enabled'));
        $this->assertSame(['statistics_reporting_enabled'], $this->journalEventTypes());
    }

    public function testSavingWithoutAChangeIsNotJournaled(): void
    {
        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/config/support/statistics', [], ['_csrf_token' => $token, 'statistics_enabled' => '1'], [], []);

        $this->controller->saveStatistics($request, []);

        $this->assertSame([], $this->journalEventTypes());
    }

    // --- the destination is not this page's business any more ---

    /**
     * Where the report goes is a project-level fact. The field is gone from
     * the form, and the URL itself is not in the page either — a unit
     * reading it would only wonder whether they were supposed to change it.
     */
    public function testTheDestinationIsNeitherShownNorOffered(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('name="statistics_destination"', $body);
        $this->assertStringNotContainsString('www.scoutmagic.be', $body);
    }

    /**
     * A field no page renders must not stay writable through a hand-made
     * POST: this is the setting that decides where this installation's
     * bearer secret is sent.
     */
    public function testAPostedDestinationIsIgnored(): void
    {
        $token = $this->issueCsrfToken();

        $this->controller->saveStatistics(new Request('POST', '/config/support/statistics', [], [
            '_csrf_token' => $token,
            'statistics_enabled' => '1',
            'statistics_destination' => 'https://ailleurs.exemple.be',
        ], [], []), []);

        $this->settings->clearCache();
        $this->assertSame('https://www.scoutmagic.be', $this->settings->get('statistics_destination'));
    }

    // --- the test send ---

    public function testTheTestSendIsOffered(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('/config/support/statistics/test', $body);
        $this->assertStringContainsString('Envoyer un rapport de test maintenant', $body);
    }

    public function testTheTestSendTransmitsTheReportAndSaysSo(): void
    {
        $token = $this->issueCsrfToken();

        $response = $this->controller->sendTestStatistics(
            new Request('POST', '/config/support/statistics/test', [], ['_csrf_token' => $token], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('https://www.scoutmagic.be/api/statistics', $this->transport->calls[0]['url']);
        $this->assertSame(['statistics_test_sent'], $this->journalEventTypes());
        $this->assertSame('success', (\Core\Http\FlashMessage::get() ?? [])['type'] ?? null);
    }

    /**
     * The whole point of the button on the installation that IS the
     * receiver: the daily task skips itself (`self_destination`), so this is
     * the only way its own report — and the intake endpoint it is sent to —
     * is ever exercised.
     */
    public function testTheTestSendIsAllowedToReachThisInstallationItself(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_destination', 'https://unite-exemple.be');
        $this->settings->clearCache();

        $this->controller->sendTestStatistics(
            new Request('POST', '/config/support/statistics/test', [], ['_csrf_token' => $this->issueCsrfToken()], [], []),
            []
        );

        $this->assertCount(1, $this->transport->calls);
        $this->assertSame('https://unite-exemple.be/api/statistics', $this->transport->calls[0]['url']);
    }

    /**
     * A test send never writes the daily bookkeeping: "État des envois"
     * answers "is automatic reporting healthy?", and a click must not be
     * able to answer it.
     */
    public function testTheTestSendLeavesTheDailySendStateUntouched(): void
    {
        $this->controller->sendTestStatistics(
            new Request('POST', '/config/support/statistics/test', [], ['_csrf_token' => $this->issueCsrfToken()], [], []),
            []
        );

        $this->settings->clearCache();
        $this->assertSame('', (string) $this->settings->get(SupportController::LAST_SUCCESS_SETTING));
        $this->assertSame('', (string) $this->settings->get(SupportController::LAST_FAILURE_SETTING));
    }

    /**
     * One button must not overrule a unit's opt-out — the switch is the
     * decision, and the test send only checks that the decision works.
     */
    public function testTheTestSendRespectsTheOptOut(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_enabled', '0');
        $this->settings->clearCache();

        $this->controller->sendTestStatistics(
            new Request('POST', '/config/support/statistics/test', [], ['_csrf_token' => $this->issueCsrfToken()], [], []),
            []
        );

        $this->assertSame([], $this->transport->calls);
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('warning', $flash['type'] ?? null);
        $this->assertStringContainsString('doit être activé', $flash['message'] ?? '');
    }

    public function testTheTestSendRequiresAValidCsrfToken(): void
    {
        $response = $this->controller->sendTestStatistics(
            new Request('POST', '/config/support/statistics/test', [], ['_csrf_token' => 'wrong'], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->transport->calls);
    }

    // --- "État des envois" ---

    /**
     * StatisticsSender deliberately never clears the failure settings on a
     * later success, so a motif from weeks ago sits under "État des envois"
     * looking like the site's current state. The page now says which of the
     * two came last.
     */
    public function testAFailureOlderThanTheLastSuccessIsShownAsResolved(): void
    {
        $this->settingRepository->updateValue(null, \Core\Statistics\StatisticsStateSettings::LAST_FAILURE_AT, '2026-08-01T03:00:00+00:00');
        $this->settingRepository->updateValue(null, \Core\Statistics\StatisticsStateSettings::LAST_FAILURE_REASON, 'non_public_host');
        $this->settingRepository->updateValue(null, \Core\Statistics\StatisticsStateSettings::LAST_SUCCESS_AT, '2026-08-20T03:00:00+00:00');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('non_public_host', $body);
        $this->assertStringContainsString('cet échec est résolu', $body);
    }

    public function testAFailureNewerThanTheLastSuccessIsNotShownAsResolved(): void
    {
        $this->settingRepository->updateValue(null, \Core\Statistics\StatisticsStateSettings::LAST_SUCCESS_AT, '2026-08-01T03:00:00+00:00');
        $this->settingRepository->updateValue(null, \Core\Statistics\StatisticsStateSettings::LAST_FAILURE_AT, '2026-08-20T03:00:00+00:00');
        $this->settingRepository->updateValue(null, \Core\Statistics\StatisticsStateSettings::LAST_FAILURE_REASON, 'http_502');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('http_502', $body);
        $this->assertStringNotContainsString('cet échec est résolu', $body);
    }

    public function testAFailureWithNoSuccessAtAllIsNeverShownAsResolved(): void
    {
        $this->settingRepository->updateValue(null, \Core\Statistics\StatisticsStateSettings::LAST_FAILURE_AT, '2026-08-20T03:00:00+00:00');
        $this->settingRepository->updateValue(null, \Core\Statistics\StatisticsStateSettings::LAST_FAILURE_REASON, 'http_502');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('cet échec est résolu', $body);
    }

    /**
     * @return array<int, string>
     */
    private function journalEventTypes(): array
    {
        $stmt = $this->pdo->query('SELECT event_type FROM event_log ORDER BY id');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_COLUMN) : [];

        return array_map('strval', $rows);
    }

    // --- support package ---

    public function testTheGenerateButtonIsOfferedEvenWhenReportingIsDisabled(): void
    {
        $this->settingRepository->updateValue(null, 'statistics_enabled', '0');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('support-package-generate', $body);
        $this->assertStringContainsString('Générer un paquet de support', $body);
    }

    public function testGeneratingSchedulesTheBackgroundTaskForTheRequestingSuperadmin(): void
    {
        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/config/support/package', [], ['_csrf_token' => $token], [], []);

        $data = json_decode($this->controller->generatePackage($request, [])->getBody(), true);

        $this->assertTrue($data['success']);
        $this->assertIsInt($data['action_id']);

        $stmt = $this->pdo->query('SELECT * FROM scheduled_actions');
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        $this->assertCount(1, $rows);
        $this->assertSame('core', $rows[0]['module_id']);
        $this->assertSame(GenerateSupportPackageHandler::TASK_KEY, $rows[0]['task_key']);
        $this->assertSame($this->userId, (int) $rows[0]['requested_by_user_account_id']);
    }

    public function testGeneratingRequiresAValidCsrfToken(): void
    {
        $request = new Request('POST', '/config/support/package', [], ['_csrf_token' => 'wrong'], [], []);

        $response = $this->controller->generatePackage($request, []);

        $this->assertSame(403, $response->getStatusCode());
        $decoded = json_decode($response->getBody(), true);
        $this->assertSame(\Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE, $decoded['error'] ?? null);
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions');
        $this->assertSame(0, (int) ($stmt !== false ? $stmt->fetchColumn() : 1));
    }

    public function testPackageStatusReportsProgressThenTheDownloadUrl(): void
    {
        $token = $this->issueCsrfToken();
        $request = new Request('POST', '/config/support/package', [], ['_csrf_token' => $token], [], []);
        $actionId = (int) json_decode($this->controller->generatePackage($request, [])->getBody(), true)['action_id'];

        $pending = json_decode(
            $this->controller->packageStatus(new Request('GET', '/api/support/package-status/' . $actionId, [], [], [], []), ['id' => (string) $actionId])->getBody(),
            true
        );
        $this->assertSame('pending', $pending['status']);
        $this->assertNull($pending['download_url']);

        $this->pdo->prepare('UPDATE scheduled_actions SET status = ? WHERE id = ?')->execute(['done', $actionId]);
        $this->settingRepository->updateValue(null, SupportPackageState::FILE_ID, '42');
        $this->settings->clearCache();

        $done = json_decode(
            $this->controller->packageStatus(new Request('GET', '/api/support/package-status/' . $actionId, [], [], [], []), ['id' => (string) $actionId])->getBody(),
            true
        );
        $this->assertSame('done', $done['status']);
        $this->assertSame('/files/42', $done['download_url']);
    }

    public function testPackageStatusRefusesAnUnrelatedScheduledAction(): void
    {
        $this->pdo->prepare('INSERT INTO scheduled_actions (module_id, task_key, run_at, status) VALUES (?, ?, ?, ?)')
            ->execute(['core', 'auto_backup', '2026-08-20 03:00:00', 'pending']);
        $id = (int) $this->pdo->lastInsertId();

        $response = $this->controller->packageStatus(new Request('GET', '/api/support/package-status/' . $id, [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnExistingPackageIsOfferedForDownloadOnPageLoad(): void
    {
        $this->settingRepository->updateValue(null, SupportPackageState::FILE_ID, '7');
        $this->settingRepository->updateValue(null, SupportPackageState::GENERATED_AT, '2026-08-19T10:00:00+00:00');
        $this->settings->clearCache();

        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringContainsString('/files/7', $body);
        $this->assertStringContainsString('2026-08-19T10:00:00+00:00', $body);
    }

    public function testThePageNeverOffersAnAutomaticTransmission(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/support', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('mailto:?', $body);
        $this->assertStringNotContainsString('attachment', $body);
        $this->assertStringContainsString('Rien n\'est jamais transmis', $body);
    }

    // --- RBAC ---

    private function buildFrontController(): FrontController
    {
        $router = new Router();
        $router->addRoute('GET', '/config/support', SupportController::class, 'index', 'superadmin');
        $router->addRoute('POST', '/config/support/package', SupportController::class, 'generatePackage', 'superadmin');
        $router->addRoute('POST', '/config/support/statistics/test', SupportController::class, 'sendTestStatistics', 'superadmin');
        $router->addRoute('GET', '/api/support/package-status/{id}', SupportController::class, 'packageStatus', 'superadmin');
        $router->addRoute('POST', '/config/support/ticket', SupportController::class, 'sendTicket', 'superadmin');

        $configFile = sys_get_temp_dir() . '/test_support_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(SupportController::class, $this->controller);

        return $fc;
    }

    public function testSuperadminIsAllowedThrough(): void
    {
        $response = $this->buildFrontController()->handle(new Request('GET', '/config/support', [], [], [], []));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminIsDenied(): void
    {
        AuthSession::logout();
        AuthSession::login($this->userId, 'admin@test.example', 'admin');

        $response = $this->buildFrontController()->handle(new Request('GET', '/config/support', [], [], [], []));

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * A ticket carries this installation's identity, so who may speak for
     * the installation is a superadmin question — the same floor as the
     * rest of the page.
     */
    public function testAdminCannotOpenATicket(): void
    {
        AuthSession::logout();
        AuthSession::login($this->userId, 'admin@test.example', 'admin');
        $this->issueCsrfToken();

        $response = $this->buildFrontController()->handle(new Request('POST', '/config/support/ticket', [], [
            '_csrf_token' => (string) $_POST['_csrf_token'],
            'ticket_category' => 'other',
            'ticket_description' => 'Bonjour',
            'ticket_contact_email' => 'chef@unite.be',
        ], [], []));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->ticketTransport->calls);
    }

    public function testAdminCanNeitherTriggerAGenerationNorPollItsStatusNorSendATestReport(): void
    {
        AuthSession::logout();
        AuthSession::login($this->userId, 'admin@test.example', 'admin');

        $frontController = $this->buildFrontController();

        $this->assertSame(
            403,
            $frontController->handle(new Request('POST', '/config/support/statistics/test', [], [], [], []))->getStatusCode()
        );
        $this->assertSame([], $this->transport->calls);

        $this->assertSame(
            403,
            $frontController->handle(new Request('POST', '/config/support/package', [], [], [], []))->getStatusCode()
        );
        $this->assertSame(
            403,
            $frontController->handle(new Request('GET', '/api/support/package-status/1', [], [], [], []))->getStatusCode()
        );
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions');
        $this->assertSame(0, (int) ($stmt !== false ? $stmt->fetchColumn() : 1));
    }
}
