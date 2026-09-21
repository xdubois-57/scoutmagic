<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Tests\Core\Mail\Template\EmailTemplateRendererFactory;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\HumanCheck\HumanCheckService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Availability\BookingConstraints;
use Modules\Rental\Booking\BookingStatus;
use Modules\Rental\Booking\ChangeRequestKind;
use Modules\Rental\Controller\RentalRequestController;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAsset;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Document\StandardTemplates;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalPricingService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;
use Twig\Environment;

/**
 * The two public surfaces of a booking, end to end through the real
 * controller: the request form and the renter's tracking page.
 *
 * Tests\Modules\Rental\Service\RentalBookingServiceTest already covers what
 * the service decides — references, encryption, hold expiry, occupancy. What
 * only a controller test can prove is the part a service never sees: that
 * HumanCheck and CSRF actually gate the form, that availability is re-checked
 * server-side rather than trusted from the submitted fields, that the two
 * tick-boxes are enforced where they are read, that the tracking token is the
 * *only* thing standing between a stranger and a renter's identity, and that
 * the managers' notification carries none of it.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalRequestControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private RentalRequestController $controller;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private RentalBookingRepository $bookingRepository;
    private \Modules\Rental\Repository\RentalChangeRequestRepository $changeRequestRepository;
    private \Modules\Rental\Repository\RentalBookingCommentRepository $commentRepository;
    private \Modules\Rental\Service\RentalOperationsService $operationsService;
    private RentalConstraintsRepository $constraintsRepository;
    private RentalPricingService $pricingService;
    private EditableContentService $editableContentService;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private int $scoutYearId;

    /** @var list<array{to: string, subject: string, html: string, text: string, headers: array<string, string>}> */
    private array $sentMail = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        // Kept on the instance because one test raises the human-check
        // threshold for itself (see testABotSubmittingInstantlyIsRefused).
        // It must be THIS object: SettingService caches per instance and
        // clears its own cache on write, so a second one would update the
        // database while the controller went on reading the old value.
        $this->settingService = $settingService = new SettingService(new SettingRepository($this->pdo));
        // One second: the shortest delay that still makes the "submitted
        // instantly" barrier real, and the test suite pays it once per
        // submission.
        $settingService->register('human_check_min_delay_seconds', '1', 'number', 'Délai minimum', 'Description de test.');
        $settingService->register('human_check_form_validity_seconds', '600', 'number', 'Validité', 'Description de test.');
        $settingService->register('base_url', 'https://unite.test', 'text', 'URL', 'Description de test.');
        $settingService->register('site_name', 'Unité Test', 'text', 'Nom', 'Description de test.');

        $scoutYearService = new ScoutYearService($this->pdo);
        // Derived, never hardcoded: the controller resolves the year from
        // today's date, and a hardcoded label would put the test's managers
        // in a different year from the one the code looks in.
        $this->scoutYearId = $scoutYearService->getCurrentYear()['id'];

        $connection = Connection::withPdo($this->pdo);
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);
        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->changeRequestRepository = new \Modules\Rental\Repository\RentalChangeRequestRepository(
            $this->pdo,
            $this->encryption
        );
        $this->constraintsRepository = new RentalConstraintsRepository($this->pdo);
        $this->editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));

        $memberService = new MemberService(new MemberYearRepository($this->pdo), $this->encryption, $connection);
        $bookingService = new RentalBookingService($this->bookingRepository, $journalService);

        $availabilityService = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            $this->constraintsRepository,
            // The booking service is its own occupancy source — the same
            // wiring public/index.php does, so a submission genuinely blocks
            // the next one here rather than only in production.
            [$bookingService]
        );
        $this->pricingService = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journalService
        );

        $this->commentRepository = new \Modules\Rental\Repository\RentalBookingCommentRepository(
            $this->pdo,
            $this->encryption
        );
        $this->operationsService = new \Modules\Rental\Service\RentalOperationsService(
            $this->bookingRepository,
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $this->commentRepository,
            $this->changeRequestRepository,
            $availabilityService,
            $this->pricingService,
            new \Modules\Rental\Pricing\QuoteEditor(),
            $journalService
        );

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views', 'inbound_mail' => dirname(__DIR__, 4) . '/modules/inbound_mail/views']
        );
        $this->twig->addGlobal('site_name', 'Unité Test');
        $this->twig->addGlobal('is_authenticated', false);
        $this->twig->addGlobal('current_user_role', 'public');
        $this->twig->addGlobal('config_mode', false);
        $this->twig->addGlobal('cookie_consent_given', true);
        $this->twig->addGlobal('menus', null);
        $this->twig->addGlobal('current_path', '/locations');
        $this->twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new RentalRequestController(
            $this->twig,
            $this->assetRepository,
            $bookingService,
            $availabilityService,
            $this->pricingService,
            new RentalBookingMailService(
                $this->recordingMailService(),
                EmailTemplateRendererFactory::shippedOnlyForModule($this->twig, 'rental'),
                $settingService,
                $journalService
            ),
            new RentalManagerService($this->managerRepository, $memberService, $journalService),
            $memberService,
            $scoutYearService,
            $this->editableContentService,
            new HumanCheckService(
                $this->encryption,
                new HumanCheckRateLimitRepository($this->pdo),
                $settingService,
                $journalService
            ),
            $settingService,
            $this->operationsService,
            $this->changeRequestRepository,
            // §6.32: the renter's own ICS feed. Both handles are nullable
            // and null without the `calendar` module — only the generator
            // is borrowed, no calendar row is ever involved.
            new \Modules\Calendar\Service\IcsBuilder(),
            new \Modules\Rental\Calendar\RenterFeedBuilder('https://unite.test')
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
        $_POST = [];
        $this->sentMail = [];
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
        $_POST = [];
    }

    /**
     * A MailService that records instead of sending. Recording rather than
     * expecting a call count is deliberate: several tests need to read what
     * went out — above all to assert what is *not* in it.
     */
    private function recordingMailService(): MailService
    {
        $mock = $this->createMock(MailService::class);
        $mock->method('send')->willReturnCallback(
            function (
                string $to,
                string $subject,
                string $bodyHtml,
                string $bodyText,
                ?string $replyTo = null,
                array $attachments = [],
                ?string $fromAddressOverride = null,
                ?string $fromNameOverride = null,
                array $extraHeaders = []
            ): void {
                $this->sentMail[] = [
                    'to' => $to,
                    'subject' => $subject,
                    'html' => $bodyHtml,
                    'text' => $bodyText,
                    'headers' => $extraHeaders,
                ];
            }
        );

        return $mock;
    }

    // ── Fixtures ────────────────────────────────────────────────────────

    private function createAsset(bool $isPublic = true, ?int $capacity = null): int
    {
        $assetId = $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            $capacity,
            1,
            null,
            null,
            '+32 470 00 00 00',
            $isPublic
        );
        $this->pricingService->saveAssetPricing($assetId, 'per_night', 12000, null, null);

        if ($capacity !== null) {
            $this->constraintsRepository->save($assetId, new BookingConstraints(maxPersons: $capacity));
        }

        return $assetId;
    }

    private function addManager(int $assetId, string $email, bool $isRenterContact = false): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, 'D-' . strtoupper(substr(md5($email), 0, 8)));
        RentalTestHelper::insertMemberYear($this->pdo, $this->encryption, $memberId, $this->scoutYearId, $email);
        $this->managerRepository->grant($assetId, $memberId, $isRenterContact);

        return $memberId;
    }

    private function arrival(int $daysAhead = 30): string
    {
        return (new \DateTimeImmutable('today'))->modify('+' . $daysAhead . ' days')->format('Y-m-d');
    }

    private function departure(int $daysAhead = 33): string
    {
        return $this->arrival($daysAhead);
    }

    /**
     * The HumanCheck fields a real browser would submit, harvested from the
     * form the controller actually rendered.
     *
     * @return array<string, string>
     */
    private function humanCheckFields(string $formBody): array
    {
        preg_match('/name="human_check_token" value="([^"]+)"/', $formBody, $tokenMatch);
        preg_match('/class="hc-trap"[^>]*>\s*<label[^>]*>[^<]*<\/label>\s*<input[^>]*id="([^"]+)"/', $formBody, $trapMatch);

        $this->assertNotEmpty($tokenMatch, 'The request form must carry a human_check_token for an anonymous visitor');
        $this->assertNotEmpty($trapMatch, 'The request form must carry the honeypot field');

        return ['human_check_token' => $tokenMatch[1], $trapMatch[1] => ''];
    }

    private function renderForm(): string
    {
        $response = $this->controller->form(
            new Request('GET', '/locations/local-saint-georges/demande', [], [], [], []),
            ['slug' => 'local-saint-georges']
        );

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());

        return (string) $response->getBody();
    }

    /**
     * A complete, valid submission.
     *
     * The HumanCheck challenge is harvested from the form the controller
     * really rendered, and the minimum delay is waited out *after* that —
     * the barrier measures time since the challenge was issued, so sleeping
     * before harvesting would prove nothing.
     *
     * @param array<string, string> $overrides
     * @return array<string, string>
     */
    private function validBody(array $overrides = [], bool $withHumanCheck = true, bool $waitForDelay = true): array
    {
        $body = [
            '_csrf_token' => CsrfGuard::generateToken(),
            'arrival' => $this->arrival(),
            'departure' => $this->departure(),
            'persons' => '20',
            'units' => '1',
            'category' => '',
            'name' => 'Jeanne Martin',
            'email' => 'jeanne.martin@example.be',
            'phone' => '+32 495 11 22 33',
            'organisation' => 'Les Scouts de Nulle Part',
            'purpose' => 'Week-end de section',
            'comment' => 'Nous arriverons vers 18h.',
            'accept_conditions' => '1',
            'accept_privacy' => '1',
        ];

        if ($withHumanCheck) {
            $body += $this->humanCheckFields($this->renderForm());
            if ($waitForDelay) {
                sleep(1);
            }
        }

        return array_merge($body, $overrides);
    }

    /**
     * @param array<string, string> $body
     */
    private function submit(array $body): \Core\Http\Response
    {
        // CsrfGuard::validateRequest() reads the superglobal directly, as it
        // does behind a real POST.
        $_POST = $body;

        return $this->controller->submit(
            new Request('POST', '/locations/local-saint-georges/demande', [], $body, [], ['REMOTE_ADDR' => '203.0.113.7']),
            ['slug' => 'local-saint-georges']
        );
    }

    private function bookingCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM rental_bookings')->fetchColumn();
    }

    // ── The form itself ─────────────────────────────────────────────────

    public function testTheFormIsReachableByAnAnonymousVisitor(): void
    {
        $this->createAsset();

        $this->assertStringContainsString('Envoyer ma demande', $this->renderForm());
    }

    public function testANonPublicAssetHasNoRequestFormAtAll(): void
    {
        // A 404, never a 403: "this exists but is not for you" is itself a
        // disclosure about the unit's assets.
        $this->createAsset(isPublic: false);

        $response = $this->controller->form(
            new Request('GET', '/locations/local-saint-georges/demande', [], [], [], []),
            ['slug' => 'local-saint-georges']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnUnknownSlugIsA404(): void
    {
        $response = $this->controller->form(
            new Request('GET', '/locations/inexistant/demande', [], [], [], []),
            ['slug' => 'inexistant']
        );

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testANonPublicAssetRefusesASubmissionToo(): void
    {
        // The guard is on the action, not only on the page that links to it.
        $this->createAsset(isPublic: false);

        $this->assertSame(404, $this->submit([
            '_csrf_token' => CsrfGuard::generateToken(),
            'arrival' => $this->arrival(),
            'departure' => $this->departure(),
            'name' => 'Jeanne',
            'email' => 'jeanne@example.be',
        ])->getStatusCode());
        $this->assertSame(0, $this->bookingCount());
    }

    // ── CSRF ────────────────────────────────────────────────────────────

    public function testASubmissionWithoutAValidCsrfTokenIsRefused(): void
    {
        $this->createAsset();
        $body = $this->validBody();
        $body['_csrf_token'] = 'forged';

        $this->assertSame(302, $this->submit($body)->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame(0, $this->bookingCount());
    }

    // ── HumanCheck ──────────────────────────────────────────────────────

    public function testAHumanSubmittingAfterTheDelayIsAccepted(): void
    {
        $this->createAsset();
        $body = $this->validBody();


        $response = $this->submit($body);

        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $this->bookingCount());
    }

    public function testABotFillingTheHoneypotIsRefusedAndNoBookingIsStored(): void
    {
        $this->createAsset();
        $body = $this->validBody();
        $trapField = array_key_last($body);
        $body[$trapField] = 'http://spam.example';

        $response = $this->submit($body);

        $this->assertSame(0, $this->bookingCount());
        // The form comes back with the visitor's input, never a dead-end
        // error page — and the message never says which barrier tripped.
        $this->assertStringContainsString('Envoyer ma demande', (string) $response->getBody());
        $this->assertStringNotContainsString('honeypot', strtolower((string) $response->getBody()));
    }

    public function testABotSubmittingInstantlyIsRefused(): void
    {
        $this->createAsset();

        // A threshold no rounding can reach, and no wait at all.
        //
        // HumanCheckService measures `time() - $timestamp` in WHOLE
        // seconds, so against the suite's ordinary one-second threshold a
        // render at X.999 and a submission at X+1.001 elapse "one second"
        // without anybody having waited — and this test then admits the
        // bot it exists to refuse. Not hypothetical: it failed on CI
        // exactly that way, on a pull request that touched no PHP at all.
        //
        // Ten seconds cannot be crossed by a boundary, and costs nothing
        // here because this is the one test that deliberately does NOT
        // wait. The other submissions keep the one-second threshold the
        // suite pays for on every one of them.
        $this->settingService->setInternal('human_check_min_delay_seconds', '10');

        // Submitted the same instant the challenge was rendered, under the
        // minimum delay.
        $this->submit($this->validBody(waitForDelay: false));

        $this->assertSame(0, $this->bookingCount());
    }

    public function testAnIdentifiedVisitorSkipsHumanCheckEntirely(): void
    {
        // A logged-in member filling the form for an outside group must not
        // be made to wait two seconds, and the partial renders nothing for
        // them — so there is no token to submit either.
        $this->createAsset();
        AuthSession::login(1, 'chief@test.be', 'chief');
        $this->twig->addGlobal('is_authenticated', true);

        $response = $this->submit($this->validBody(withHumanCheck: false));

        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $this->assertSame(1, $this->bookingCount());
    }

    // ── Server-side validation ──────────────────────────────────────────

    public function testAMalformedDateIsRefused(): void
    {
        $this->createAsset();

        $response = $this->submit($this->validBody(['arrival' => '31/12/2027']));

        $this->assertSame(0, $this->bookingCount());
        $this->assertStringContainsString('Les dates ne sont pas valides.', (string) $response->getBody());
    }

    public function testACapacityOverrunIsRefusedEvenThoughTheFormNeverOfferedIt(): void
    {
        // The number of people is re-checked against the asset's own limit
        // server-side: a hand-crafted POST must not book 400 people into a
        // hall for 40.
        $this->createAsset(capacity: 40);

        $response = $this->submit($this->validBody(['persons' => '400']));

        $this->assertSame(0, $this->bookingCount());
        $this->assertStringContainsString('40', (string) $response->getBody());
    }

    public function testDatesAlreadyTakenAreRefusedOnTheSecondSubmission(): void
    {
        // The whole point of registering the booking service as an occupancy
        // provider: the calendar the second visitor saw may be minutes old.
        $this->createAsset();

        $this->assertSame(302, $this->submit($this->validBody())->getStatusCode());
        $response = $this->submit($this->validBody(['email' => 'second@example.be']));

        $this->assertSame(1, $this->bookingCount());
        $this->assertStringContainsString('alert-danger', (string) $response->getBody());
    }

    public function testAnUntickedConditionsBoxIsRefused(): void
    {
        $this->createAsset();
        $body = $this->validBody();
        unset($body['accept_conditions']);

        $response = $this->submit($body);

        $this->assertSame(0, $this->bookingCount());
        $this->assertStringContainsString('conditions de location', (string) $response->getBody());
    }

    public function testAnUntickedPrivacyBoxIsRefused(): void
    {
        $this->createAsset();
        $body = $this->validBody();
        unset($body['accept_privacy']);

        $response = $this->submit($body);

        $this->assertSame(0, $this->bookingCount());
        $this->assertStringContainsString('confidentialité', (string) $response->getBody());
    }

    public function testARejectedSubmissionGivesTheVisitorTheirInputBack(): void
    {
        $this->createAsset();
        $body = $this->validBody();
        unset($body['accept_conditions']);

        $body = (string) $this->submit($body)->getBody();

        $this->assertStringContainsString('Jeanne Martin', $body);
        $this->assertStringContainsString('jeanne.martin@example.be', $body);
        $this->assertStringContainsString('Nous arriverons vers 18h.', $body);
    }

    // ── What a submission stores ────────────────────────────────────────

    public function testTheAcceptedConditionsTextIsRecordedAsItWasShown(): void
    {
        $assetId = $this->createAsset();
        $this->editableContentService->set(
            'rental_asset_' . $assetId . '_conditions',
            '<p>Le local est rendu balayé.</p>',
            'rich_text',
            1
        );

        $this->submit($this->validBody());

        $booking = $this->bookingRepository->findById(1);
        $this->assertNotNull($booking);
        $this->assertNotNull($booking->conditionsVersion);
        $this->assertSame(
            RentalBookingService::hashAcceptedText('<p>Le local est rendu balayé.</p>'),
            $booking->conditionsHash
        );
    }

    /**
     * The whole reason §22.5 gave the conditions a shipped default: while
     * nobody had written any, the form showed no conditions at all and
     * still made the visitor tick « J'accepte les conditions de location ».
     * They accepted an empty string, and the hash attested to it — a proof
     * mechanism working perfectly over nothing.
     */
    public function testAnAssetWithNoWrittenConditionsStillShowsCompleteOnes(): void
    {
        $this->createAsset();

        $form = $this->renderForm();

        $this->assertStringContainsString('Conditions de location', $form);
        $this->assertStringContainsString('Ces conditions s\'appliquent à toute demande', $form);
    }

    public function testTheAcceptedTextIsTheStandardOneWhenTheUnitWroteNone(): void
    {
        $this->createAsset();

        $this->submit($this->validBody());

        $booking = $this->bookingRepository->findById(1);
        $this->assertNotNull($booking);
        $this->assertSame(
            RentalBookingService::hashAcceptedText(StandardTemplates::conditions()),
            $booking->conditionsHash,
            'The hash must attest to the text that was actually on screen.'
        );
    }

    // ── Required fields (§22.5) ─────────────────────────────────────────

    public function testTheFormAsksForAPhoneAndAPurposeAsRequired(): void
    {
        $this->createAsset();

        $form = (string) preg_replace('/\s+/', ' ', $this->renderForm());

        foreach (['phone', 'purpose'] as $field) {
            $this->assertMatchesRegularExpression(
                '/<input[^>]*id="' . $field . '"[^>]*required/',
                $form,
                $field . ' must be required in the browser too.'
            );
        }
    }

    public function testTheFormCarriesTheTwoExamplesTheChantierAsksFor(): void
    {
        $this->createAsset();

        $form = $this->renderForm();

        $this->assertStringContainsString('Week-end de section', $form);
        $this->assertStringContainsString('Unité du Petit Ry SV025', $form);
    }

    /**
     * Server-side, because a `required` attribute is a convenience for the
     * visitor and never a guarantee to the unit.
     */
    public function testASubmissionWithoutAPhoneIsRefusedAndStoresNothing(): void
    {
        $this->createAsset();

        $response = $this->submit($this->validBody(['phone' => '  ']));

        $this->assertStringContainsString('téléphone est obligatoire', (string) $response->getBody());
        $this->assertSame(0, $this->bookingCount());
    }

    public function testASubmissionWithoutAPurposeIsRefusedAndStoresNothing(): void
    {
        $this->createAsset();

        $response = $this->submit($this->validBody(['purpose' => '']));

        $this->assertStringContainsString("objet de la location est obligatoire", (string) $response->getBody());
        $this->assertSame(0, $this->bookingCount());
    }

    /**
     * And the organisation stays optional, deliberately: a family letting
     * the hall for a communion has none, and making it mandatory only makes
     * them invent an answer.
     */
    public function testASubmissionWithoutAnOrganisationIsAccepted(): void
    {
        $this->createAsset();

        $this->submit($this->validBody(['organisation' => '']));

        $this->assertSame(1, $this->bookingCount());
        $this->assertNull($this->bookingRepository->findById(1)?->renterOrganisation);
    }

    public function testTheOrganisationFieldIsNotMarkedRequired(): void
    {
        $this->createAsset();

        $form = (string) preg_replace('/\s+/', ' ', $this->renderForm());

        $this->assertDoesNotMatchRegularExpression(
            '/<input[^>]*id="organisation"[^>]*required/',
            $form
        );
    }

    public function testTheQuotedPriceIsSnapshottedOntoTheBooking(): void
    {
        // Three nights at 120,00 €: what the renter was shown is what is
        // stored, so a later tariff change never rewrites history.
        $this->createAsset();

        $this->submit($this->validBody());

        $booking = $this->bookingRepository->findById(1);
        $this->assertNotNull($booking);
        $this->assertNotNull($booking->estimatedPrice);
        $this->assertSame(36000, $booking->estimatedPrice->totalCents);
    }

    public function testAnAssetWithNoTariffSnapshotsNoPriceAtAll(): void
    {
        // The public estimate block already refuses to show a 0,00 € table
        // (RentalPublicController's `has_tariff`). Snapshotting it at
        // submission froze the same figure onto the booking, where the
        // renter reads it as the price agreed.
        $this->createAssetWithoutTariff();

        $this->submit($this->validBody());

        $booking = $this->bookingRepository->findById(1);
        $this->assertNotNull($booking);
        $this->assertNull($booking->estimatedPrice);
    }

    public function testTheTrackingPageOffersATariffOnRequestRatherThanZeroEuros(): void
    {
        $this->createAssetWithoutTariff();
        [$bookingId, $token] = $this->submitAndTrack();

        $body = (string) $this->track($bookingId, $token)->getBody();

        $this->assertStringContainsString('Tarif sur demande', $body);
        $this->assertStringContainsString('proposition de prix', $body);
        $this->assertStringNotContainsString('0,00', $body);
    }

    public function testTheTrackingPageStillShowsARealPriceWhenThereIsOne(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $body = (string) $this->track($bookingId, $token)->getBody();

        $this->assertStringContainsString('360,00', $body);
        $this->assertStringNotContainsString('Tarif sur demande', $body);
    }

    /**
     * Public, bookable, and with no rate configured at all — the state a
     * unit is in between publishing an asset and filling in its tariffs.
     */
    private function createAssetWithoutTariff(): int
    {
        return $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            null,
            1,
            null,
            null,
            '+32 470 00 00 00',
            true
        );
    }

    public function testNoRenterIdentityIsStoredInClear(): void
    {
        $this->createAsset();
        $this->submit($this->validBody());

        $raw = (string) json_encode(
            $this->pdo->query('SELECT * FROM rental_bookings')->fetch(\PDO::FETCH_ASSOC)
        );

        foreach (['Jeanne Martin', 'jeanne.martin@example.be', '+32 495 11 22 33', 'Les Scouts de Nulle Part', 'Week-end de section', 'Nous arriverons vers 18h.'] as $secret) {
            $this->assertStringNotContainsString($secret, $raw);
        }
    }

    public function testNoRenterIdentityReachesTheJournal(): void
    {
        $this->createAsset();
        $this->submit($this->validBody());

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringContainsString('LOC-', $journal);
        foreach (['Jeanne Martin', 'jeanne.martin@example.be', '+32 495 11 22 33', 'Nous arriverons vers 18h.'] as $secret) {
            $this->assertStringNotContainsString($secret, $journal);
        }
    }

    public function testTheTrackingTokenNeverReachesTheJournal(): void
    {
        $this->createAsset();
        $token = $this->tokenFromRedirect((string) $this->submit($this->validBody())->getHeaders()['Location']);

        $journal = (string) json_encode($this->pdo->query('SELECT * FROM event_log')->fetchAll(\PDO::FETCH_ASSOC));

        $this->assertStringNotContainsString($token, $journal);
    }

    // ── The emails ──────────────────────────────────────────────────────

    public function testTheRenterGetsAnAcknowledgementCarryingTheirTrackingLink(): void
    {
        $this->createAsset();
        $this->submit($this->validBody());

        $renterMail = $this->mailTo('jeanne.martin@example.be');
        $this->assertNotNull($renterMail);
        $this->assertStringStartsWith('[LOC-', $renterMail['subject']);
        $this->assertStringContainsString('/locations/suivi/1/', $renterMail['html']);
        $this->assertStringContainsString('/locations/suivi/1/', $renterMail['text']);
        $this->assertArrayHasKey('Message-ID', $renterMail['headers']);
    }

    public function testEveryManagerOfTheAssetIsNotifiedAndNobodyElseIs(): void
    {
        $assetId = $this->createAsset();
        $this->addManager($assetId, 'gestionnaire1@test.be');
        $this->addManager($assetId, 'gestionnaire2@test.be');

        $otherAssetId = $this->assetRepository->create('Local', 'Autre', 'autre', null, 1, null, null, null, true);
        $this->addManager($otherAssetId, 'pas-concerne@test.be');

        $this->submit($this->validBody());

        $recipients = array_column($this->sentMail, 'to');
        $this->assertContains('gestionnaire1@test.be', $recipients);
        $this->assertContains('gestionnaire2@test.be', $recipients);
        $this->assertNotContains('pas-concerne@test.be', $recipients);
    }

    public function testTheManagersNotificationCarriesNoRenterIdentity(): void
    {
        // §15 of the conventions: an inbox is not a place to scatter copies
        // of personal data. A manager is one click from the page that shows
        // it behind a real permission check.
        $assetId = $this->createAsset();
        $this->addManager($assetId, 'gestionnaire@test.be');
        $this->submit($this->validBody());

        $managerMail = $this->mailTo('gestionnaire@test.be');
        $this->assertNotNull($managerMail);

        foreach (['Jeanne Martin', 'jeanne.martin@example.be', '+32 495 11 22 33', 'Nous arriverons vers 18h.'] as $secret) {
            $this->assertStringNotContainsString($secret, $managerMail['html']);
            $this->assertStringNotContainsString($secret, $managerMail['text']);
            $this->assertStringNotContainsString($secret, $managerMail['subject']);
        }
    }

    public function testTheManagersNotificationNeverCarriesTheTrackingLink(): void
    {
        $assetId = $this->createAsset();
        $this->addManager($assetId, 'gestionnaire@test.be');
        $this->submit($this->validBody());

        $managerMail = $this->mailTo('gestionnaire@test.be');
        $this->assertNotNull($managerMail);
        // Possession of the link IS the authorisation — forwarding it around
        // by email would hand a renter's page to whoever the mail reaches.
        $this->assertStringNotContainsString('/locations/suivi/', $managerMail['html']);
        $this->assertStringNotContainsString('/locations/suivi/', $managerMail['text']);
    }

    public function testTheManagersNotificationLinksToTheBookingItself(): void
    {
        // The module's front door made a manager opening this on a phone
        // land on a list and hunt for the request again. Deep-linking is
        // safe precisely because the page behind it is behind a real
        // permission check — which is also why the mail carries no renter
        // identity of its own.
        $assetId = $this->createAsset();
        $this->addManager($assetId, 'gestionnaire@test.be');
        $this->submit($this->validBody());

        $managerMail = $this->mailTo('gestionnaire@test.be');
        $this->assertNotNull($managerMail);
        $this->assertStringContainsString('/mes-locations/local-saint-georges/reservations/1', $managerMail['html']);
        $this->assertStringContainsString('/mes-locations/local-saint-georges/reservations/1', $managerMail['text']);
    }

    public function testEachManagerIsMailedSeparatelySoTheirAddressesStayPrivate(): void
    {
        $assetId = $this->createAsset();
        $this->addManager($assetId, 'gestionnaire1@test.be');
        $this->addManager($assetId, 'gestionnaire2@test.be');
        $this->submit($this->validBody());

        $first = $this->mailTo('gestionnaire1@test.be');
        $this->assertNotNull($first);
        $this->assertStringNotContainsString('gestionnaire2@test.be', (string) json_encode($first));
    }

    public function testAnAssetWithNoManagerStillAcceptsTheRequest(): void
    {
        // A misconfigured asset must not lose a booking; the request lands
        // and shows up on the managed page all the same.
        $this->createAsset();

        $this->assertSame(302, $this->submit($this->validBody())->getStatusCode());
        $this->assertSame(1, $this->bookingCount());
    }

    // ── The tracking page ───────────────────────────────────────────────

    private function tokenFromRedirect(string $location): string
    {
        $parts = explode('/', trim($location, '/'));

        return (string) end($parts);
    }

    /**
     * @return array{to: string, subject: string, html: string, text: string, headers: array<string, string>}|null
     */
    private function mailTo(string $address): ?array
    {
        foreach ($this->sentMail as $mail) {
            if ($mail['to'] === $address) {
                return $mail;
            }
        }

        return null;
    }

    private function track(int $bookingId, string $token): \Core\Http\Response
    {
        return $this->controller->tracking(
            new Request('GET', '/locations/suivi/' . $bookingId . '/' . $token, [], [], [], []),
            ['id' => (string) $bookingId, 'token' => $token]
        );
    }

    /** @return array{0: int, 1: string} */
    /**
     * The asset the scenarios book, as the object
     * `RentalOperationsService::requestChange()` now takes — it validates a
     * renter's new dates against the asset's own rules rather than only
     * parsing them (IT-03).
     */
    private function trackedAsset(): RentalAsset
    {
        $asset = $this->assetRepository->findBySlug('local-saint-georges');
        $this->assertNotNull($asset);

        return $asset;
    }

    private function submitAndTrack(): array
    {
        $response = $this->submit($this->validBody());
        $this->assertSame(302, $response->getStatusCode(), (string) $response->getBody());
        $location = (string) $response->getHeaders()['Location'];

        return [1, $this->tokenFromRedirect($location)];
    }

    public function testAValidTokenOpensTheRentersOwnPage(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $response = $this->track($bookingId, $token);

        $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('LOC-', (string) $response->getBody());
    }

    public function testTheRentersPageShowsTheAgreedPriceOnceThereIsOne(): void
    {
        // Booking\RentalBooking::effectivePrice() exists so the renter's
        // page and the manager's panel can never show different figures.
        // Reading `estimatedPrice` here left this page on the frozen
        // estimate for ever, while the manager was being told "le locataire
        // le voit immédiatement sur sa page de suivi".
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);
        $this->assertNotNull($booking->estimatedPrice);

        $this->bookingRepository->setAgreedPrice($bookingId, new PriceQuote(
            lines: [new PriceLine('Forfait négocié', 1, 40000, 40000, PriceLine::RULE_MANUAL, isManual: true)],
            totalCents: 40000,
            nights: $booking->estimatedPrice->nights,
            persons: $booking->estimatedPrice->persons,
            quantity: 1,
            billingUnit: $booking->estimatedPrice->billingUnit
        ));

        $body = (string) $this->track($bookingId, $token)->getBody();

        $this->assertStringContainsString('Forfait négocié', $body);
        $this->assertStringContainsString('400,00', $body);
    }

    public function testTheRenterReadsTheirDatesInFrenchNotAsStoredRows(): void
    {
        // The one page a renter ever sees was printing `arrivalDate` raw,
        // so their acknowledgement email and their tracking page disagreed
        // about what the same stay looks like.
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $body = (string) $this->track($bookingId, $token)->getBody();

        $arrival = new \DateTimeImmutable($this->arrival());
        $departure = new \DateTimeImmutable($this->departure());

        $this->assertStringContainsString($arrival->format('d/m/Y'), $body);
        $this->assertStringContainsString($departure->format('d/m/Y'), $body);

        // The stored form may appear ONCE each, and only as the `value` of
        // the pre-filled date inputs « Modifier votre demande » now carries
        // (IT-03): `<input type="date">` takes ISO and renders it in the
        // reader's own locale, so that is not a date shown as a stored row.
        // Anywhere else it still is, which is what this test was written
        // for — hence counting rather than dropping the assertion.
        foreach ([$arrival, $departure] as $date) {
            $iso = $date->format('Y-m-d');
            $this->assertSame(
                substr_count($body, 'value="' . $iso . '"'),
                substr_count($body, $iso),
                'the stored form of ' . $iso . ' appears somewhere other than a date input'
            );
        }
    }

    public function testAWrongTokenIsA404(): void
    {
        $this->createAsset();
        [$bookingId] = $this->submitAndTrack();

        $this->assertSame(404, $this->track($bookingId, str_repeat('f', 64))->getStatusCode());
    }

    public function testAnEmptyTokenIsA404(): void
    {
        $this->createAsset();
        [$bookingId] = $this->submitAndTrack();

        $this->assertSame(404, $this->track($bookingId, '')->getStatusCode());
    }

    public function testAnUnknownBookingIsRefusedTheSameWayAsAWrongToken(): void
    {
        // Identical answers, or the page becomes an oracle for which
        // references exist.
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $this->assertSame($this->track($bookingId, 'wrong')->getStatusCode(), $this->track(9999, $token)->getStatusCode());
    }

    public function testOneRentersTokenNeverOpensAnotherRentersBooking(): void
    {
        // The IDOR that matters: bookings are numbered 1, 2, 3… and the id
        // is right there in the URL. Only the token may decide.
        $this->createAsset();
        [, $firstToken] = $this->submitAndTrack();

        $second = $this->submit($this->validBody([
            'arrival' => $this->arrival(60),
            'departure' => $this->departure(63),
            'name' => 'Marc Dupont',
            'email' => 'marc.dupont@example.be',
        ]));
        $this->assertSame(302, $second->getStatusCode(), (string) $second->getBody());

        $this->assertSame(404, $this->track(2, $firstToken)->getStatusCode());
    }

    public function testTheTrackingPageShowsTheEmergencyPhoneAndRenterContactsOnly(): void
    {
        $assetId = $this->createAsset();
        $this->addManager($assetId, 'contact@test.be', isRenterContact: true);
        $this->addManager($assetId, 'interne@test.be', isRenterContact: false);
        [$bookingId, $token] = $this->submitAndTrack();

        $body = (string) $this->track($bookingId, $token)->getBody();

        // The emergency phone is for the renter of the asset and nobody else
        // — it is on this page and on no public one.
        $this->assertStringContainsString('+32 470 00 00 00', $body);
        $this->assertStringContainsString('contact@test.be', $body);
        $this->assertStringNotContainsString('interne@test.be', $body);
    }

    // ── The renter's own ICS feed (§6.32) ───────────────────────────────

    /**
     * @param array<string, string> $params
     */
    private function feed(int $bookingId, string $token): \Core\Http\Response
    {
        return $this->controller->renterFeed(
            new Request(
                'GET',
                '/locations/suivi/' . $bookingId . '/' . $token . '/calendrier.ics',
                [],
                [],
                [],
                []
            ),
            ['id' => (string) $bookingId, 'token' => $token]
        );
    }

    public function testAValidTokenGetsAnIcsCarryingTheStay(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $response = $this->feed($bookingId, $token);
        $body = (string) $response->getBody();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('text/calendar; charset=utf-8', $response->getHeaders()['Content-Type'] ?? null);
        $this->assertStringContainsString('attachment; filename="location.ics"', (string) ($response->getHeaders()['Content-Disposition'] ?? ''));

        $this->assertStringContainsString('BEGIN:VCALENDAR', $body);
        $this->assertStringContainsString('END:VCALENDAR', $body);
        $this->assertStringContainsString('BEGIN:VEVENT', $body);
        $this->assertStringContainsString('DTSTART', $body);
        // The stay itself, not an empty calendar.
        $this->assertStringContainsString(str_replace('-', '', $this->arrival()), $body);
    }

    /**
     * The same refusal as everywhere else on this controller, and for the
     * same reason: a distinct answer for "no such booking" would map out
     * which references exist.
     */
    public function testAWrongTokenGetsA404AndNoCalendarAtAll(): void
    {
        $this->createAsset();
        [$bookingId] = $this->submitAndTrack();

        $response = $this->feed($bookingId, str_repeat('f', 64));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('BEGIN:VCALENDAR', (string) $response->getBody());
    }

    public function testAnUnknownBookingGetsTheSame404(): void
    {
        $this->createAsset();
        [, $token] = $this->submitAndTrack();

        $response = $this->feed(999999, $token);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAnEmptyTokenOpensNothing(): void
    {
        $this->createAsset();
        [$bookingId] = $this->submitAndTrack();

        $this->assertSame(404, $this->feed($bookingId, '')->getStatusCode());
    }

    /**
     * The failure that would matter most: one renter's token handing back
     * another renter's stay. The feed is keyed on the booking id AND the
     * token, and a valid token for booking A must not open booking B.
     */
    public function testOneRentersTokenNeverOpensAnotherRentersFeed(): void
    {
        $this->createAsset();
        [$firstId, $firstToken] = $this->submitAndTrack();

        $second = $this->submit($this->validBody([
            'arrival' => $this->arrival(90),
            'departure' => $this->departure(93),
            'name' => 'Autre Locataire',
            'email' => 'autre@example.org',
        ]));
        $this->assertSame(302, $second->getStatusCode(), (string) $second->getBody());
        $secondId = $firstId + 1;
        $secondToken = $this->tokenFromRedirect((string) $second->getHeaders()['Location']);

        $this->assertSame(404, $this->feed($secondId, $firstToken)->getStatusCode());
        $this->assertSame(404, $this->feed($firstId, $secondToken)->getStatusCode());

        // And each one still opens its own.
        $this->assertSame(200, $this->feed($firstId, $firstToken)->getStatusCode());
        $this->assertSame(200, $this->feed($secondId, $secondToken)->getStatusCode());
    }

    /**
     * One booking, one event — never the other renter's dates, name or
     * reference alongside it.
     */
    public function testTheFeedCarriesThisBookingAndNothingElse(): void
    {
        $this->createAsset();
        [$firstId, $firstToken] = $this->submitAndTrack();
        $this->submit($this->validBody([
            'arrival' => $this->arrival(90),
            'departure' => $this->departure(93),
            'name' => 'Autre Locataire',
            'email' => 'autre@example.org',
        ]));

        $body = (string) $this->feed($firstId, $firstToken)->getBody();

        $this->assertSame(1, substr_count($body, 'BEGIN:VEVENT'));
        $this->assertStringNotContainsString('Autre Locataire', $body);
        $this->assertStringNotContainsString('autre@example.org', $body);
        $this->assertStringNotContainsString(str_replace('-', '', $this->arrival(90)), $body);
    }

    // ── Change requests from the renter's side (§6.16, §6.17) ───────────

    /**
     * @param array<string, string> $body
     */
    private function postToTracking(string $action, int $bookingId, string $token, array $body): \Core\Http\Response
    {
        $body['_csrf_token'] ??= CsrfGuard::generateToken();
        $_POST = $body;

        $path = '/locations/suivi/' . $bookingId . '/' . $token . '/' . match ($action) {
            'requestChange' => 'demande',
            'saveBillingIdentity' => 'facturation',
            default => 'reponse',
        };

        return $this->controller->{$action}(
            new Request('POST', $path, [], $body, [], []),
            ['id' => (string) $bookingId, 'token' => $token]
        );
    }

    public function testARentersChangeRequestIsRecordedAndChangesNothing(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $response = $this->postToTracking('requestChange', $bookingId, $token, [
            'kind' => 'dates',
            'arrival' => $this->arrival(60),
            'departure' => $this->departure(63),
            'message' => 'Nous préférons la semaine suivante.',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $requests = $this->changeRequestRepository->findForBooking($bookingId);
        $this->assertCount(1, $requests);
        $this->assertTrue($requests[0]->isPending());
        // The booking itself is untouched: that is the whole rule.
        $this->assertSame($this->arrival(), $this->bookingRepository->findById($bookingId)?->arrivalDate);
    }

    // ── The type of request is derived, never chosen (IT-03) ────────────

    public function testChangingOnlyTheDatesIsADateRequest(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $this->postToTracking('requestChange', $bookingId, $token, [
            'arrival' => $this->arrival(60),
            'departure' => $this->departure(63),
            'persons' => (string) $this->bookingRepository->findById($bookingId)?->estimatedPersons,
            'message' => 'Nous préférons la semaine suivante.',
        ]);

        $this->assertSame(
            ChangeRequestKind::DATES,
            $this->changeRequestRepository->findForBooking($bookingId)[0]->kind
        );
    }

    /**
     * **And it carries no head count at all.**
     *
     * The form pre-fills the participants box with the booking's own
     * figure, so a dates-only request submits it whether or not the renter
     * touched it. Stored as `proposedPersons`, it comes back out of
     * `acceptChange()` through `proposedPersons ?? current` and is written
     * by `setStay()` — so a request made before a head-count change was
     * accepted would revert it, weeks later, with nothing to warn the
     * manager: `summary()` renders a DATES request as dates alone.
     */
    public function testADatesOnlyRequestCarriesNoHeadCount(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $this->postToTracking('requestChange', $bookingId, $token, [
            'arrival' => $this->arrival(60),
            'departure' => $this->departure(63),
            // Exactly what the pre-filled box posts back.
            'persons' => (string) $this->bookingRepository->findById($bookingId)?->estimatedPersons,
            'message' => 'Nous préférons la semaine suivante.',
        ]);

        $this->assertNull($this->changeRequestRepository->findForBooking($bookingId)[0]->proposedPersons);
    }

    public function testChangingOnlyTheHeadCountIsAParticipantsRequest(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();
        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);

        $this->postToTracking('requestChange', $bookingId, $token, [
            'arrival' => $booking->arrivalDate,
            'departure' => $booking->departureDate,
            'persons' => (string) (((int) $booking->estimatedPersons) + 4),
            'message' => 'Nous serons quatre de plus.',
        ]);

        $this->assertSame(
            ChangeRequestKind::PERSONS,
            $this->changeRequestRepository->findForBooking($bookingId)[0]->kind
        );
    }

    /**
     * The case the old form could not express at all: `kind` was a single
     * choice, so "other dates AND a smaller group" was two requests a
     * manager had to answer separately, each of them valid only if the
     * other was accepted too. The row always had room for both.
     */
    public function testChangingBothIsOneRequestAndNotTwo(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $this->postToTracking('requestChange', $bookingId, $token, [
            'arrival' => $this->arrival(60),
            'departure' => $this->departure(63),
            'persons' => '9',
            'message' => "D'autres dates, et nous serons moins nombreux.",
        ]);

        $requests = $this->changeRequestRepository->findForBooking($bookingId);
        $this->assertCount(1, $requests);
        $this->assertSame(ChangeRequestKind::DATES_AND_PERSONS, $requests[0]->kind);
        $this->assertSame(9, $requests[0]->proposedPersons);
        $this->assertNotNull($requests[0]->proposedArrivalDate);
    }

    /**
     * A form somebody opened, read and submitted without touching is not a
     * request. It used to become one, and a manager had to open it to find
     * that out.
     */
    public function testAFormThatChangesNothingIsRefused(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();
        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);

        $this->postToTracking('requestChange', $bookingId, $token, [
            'arrival' => $booking->arrivalDate,
            'departure' => $booking->departureDate,
            'persons' => (string) $booking->estimatedPersons,
            'message' => 'Bonjour !',
        ]);

        $this->assertSame([], $this->changeRequestRepository->findForBooking($bookingId));
    }

    public function testAChangeWithoutAWordIsRefused(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $this->postToTracking('requestChange', $bookingId, $token, [
            'arrival' => $this->arrival(60),
            'departure' => $this->departure(63),
            'message' => '   ',
        ]);

        $this->assertSame([], $this->changeRequestRepository->findForBooking($bookingId));
    }

    /**
     * Cancelling is its own button, and its word is optional: somebody who
     * has decided must not be held up by a text field.
     */
    public function testCancellingIsItsOwnButtonAndNeedsNoMessage(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $this->postToTracking('requestChange', $bookingId, $token, [
            'action' => 'cancel',
        ]);

        $requests = $this->changeRequestRepository->findForBooking($bookingId);
        $this->assertCount(1, $requests);
        $this->assertSame(ChangeRequestKind::CANCELLATION, $requests[0]->kind);
        // And it changes nothing by itself — the dates stay held until a
        // manager accepts.
        $this->assertSame($this->arrival(), $this->bookingRepository->findById($bookingId)?->arrivalDate);
    }

    // ── The renter's own billing coordinates (IT-03, §22.6) ─────────────

    public function testTheTrackingPageAsksForBillingCoordinatesOnlyAsATask(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $body = (string) $this->track($bookingId, $token)->getBody();
        $this->assertStringContainsString('Vos coordonnées de facturation', $body);
        $this->assertStringContainsString('À compléter', $body);

        $this->postToTracking('saveBillingIdentity', $bookingId, $token, [
            'billing_name' => 'Les Amis du Sart ASBL',
            'billing_vat_number' => 'BE0123456789',
            'billing_country' => 'be',
        ]);

        $stored = $this->bookingRepository->findBillingIdentity($bookingId);
        $this->assertSame('Les Amis du Sart ASBL', $stored['name']);
        $this->assertSame('BE0123456789', $stored['vat_number']);
        // Upper-cased on the way in by the repository both sides share.
        $this->assertSame('BE', $stored['country']);

        $filled = (string) $this->track($bookingId, $token)->getBody();
        $this->assertStringContainsString('Enregistrées', $filled);
        $this->assertStringNotContainsString('À compléter', $filled);
    }

    /**
     * **Nothing is invoiced for a letting that never happened.** The page
     * hides the block on a refused, cancelled or expired booking, and a
     * hidden form is not a rule: the token still reaches the route.
     */
    public function testBillingCoordinatesAreRefusedOnAnAbandonedBooking(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $this->bookingRepository->compareAndSetStatus(
            $bookingId,
            BookingStatus::RECEIVED,
            BookingStatus::REFUSED,
            new \DateTimeImmutable('2027-02-01 10:00:00')
        );

        $this->postToTracking('saveBillingIdentity', $bookingId, $token, [
            'billing_name' => 'Trop tard ASBL',
        ]);

        $this->assertNull($this->bookingRepository->findBillingIdentity($bookingId)['name']);
    }

    /**
     * And a CLOSED booking is exactly the one being invoiced, which is why
     * the guard reads `isAbandoned()` and not `isFinal()`.
     */
    public function testBillingCoordinatesAreStillAcceptedOnAClosedBooking(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $now = new \DateTimeImmutable('2027-02-01 10:00:00');
        $this->bookingRepository->compareAndSetStatus($bookingId, BookingStatus::RECEIVED, BookingStatus::CONFIRMED, $now);
        $this->bookingRepository->compareAndSetStatus($bookingId, BookingStatus::CONFIRMED, BookingStatus::CLOSED, $now);

        $this->postToTracking('saveBillingIdentity', $bookingId, $token, [
            'billing_name' => 'Les Amis du Sart ASBL',
        ]);

        $this->assertSame(
            'Les Amis du Sart ASBL',
            $this->bookingRepository->findBillingIdentity($bookingId)['name']
        );
    }

    public function testBillingCoordinatesNeedTheRightToken(): void
    {
        $this->createAsset();
        [$bookingId] = $this->submitAndTrack();

        $response = $this->postToTracking('saveBillingIdentity', $bookingId, str_repeat('f', 64), [
            'billing_name' => 'Quelqu\'un d\'autre',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertNull($this->bookingRepository->findBillingIdentity($bookingId)['name']);
    }

    /**
     * Stored encrypted, like every other identity the module holds — and
     * the point of asking the renter rather than a manager is that nobody
     * has to retype it.
     */
    public function testTheRentersBillingCoordinatesAreEncryptedAtRest(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $this->postToTracking('saveBillingIdentity', $bookingId, $token, [
            'billing_name' => 'Les Amis du Sart ASBL',
        ]);

        $stmt = $this->pdo->prepare('SELECT billing_name_encrypted FROM rental_bookings WHERE id = ?');
        $stmt->execute([$bookingId]);
        $raw = (string) $stmt->fetchColumn();

        $this->assertNotSame('', $raw);
        $this->assertStringNotContainsString('Amis du Sart', $raw);
    }

    public function testARentersChangeRequestNeedsTheRightToken(): void
    {
        // A POST is not less of an entry point than a GET.
        $this->createAsset();
        [$bookingId] = $this->submitAndTrack();

        $response = $this->postToTracking('requestChange', $bookingId, str_repeat('f', 64), [
            'kind' => 'cancellation',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame([], $this->changeRequestRepository->findForBooking($bookingId));
    }

    public function testARentersChangeRequestNeedsAValidCsrfToken(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $response = $this->postToTracking('requestChange', $bookingId, $token, [
            '_csrf_token' => 'forged',
            'kind' => 'cancellation',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(
            \Core\Http\Controller\AbstractController::SESSION_EXPIRED_MESSAGE,
            \Core\Http\FlashMessage::get()['message'] ?? null
        );
        $this->assertSame([], $this->changeRequestRepository->findForBooking($bookingId));
    }

    public function testTheRenterAcceptsAManagersProposalFromTheirOwnPage(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();
        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);

        $requestId = $this->operationsService->requestChange(
            $booking,
            $this->trackedAsset(),
            \Modules\Rental\Booking\ChangeRequestOrigin::MANAGER,
            \Modules\Rental\Booking\ChangeRequestKind::DATES,
            $this->arrival(90),
            $this->departure(93),
            null,
            null,
            null,
            'Ces dates nous arrangeraient mieux.',
            1
        );

        $this->postToTracking('decideProposal', $bookingId, $token, [
            'request_id' => (string) $requestId,
            'decision' => 'accept',
        ]);

        $this->assertSame($this->arrival(90), $this->bookingRepository->findById($bookingId)?->arrivalDate);
    }

    public function testRefusingAProposalAsksFirstAndAcceptingIsThePagesPrimary(): void
    {
        // Two identically-weighted small buttons, one of which closes the
        // unit's proposal for good. §7.5: every POST that refuses asks
        // first, and the question names the consequence.
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();
        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);

        $this->operationsService->requestChange(
            $booking,
            $this->trackedAsset(),
            \Modules\Rental\Booking\ChangeRequestOrigin::MANAGER,
            \Modules\Rental\Booking\ChangeRequestKind::DATES,
            $this->arrival(90),
            $this->departure(93),
            null,
            null,
            null,
            'Ces dates nous arrangeraient mieux.',
            1
        );

        $body = (string) $this->track($bookingId, $token)->getBody();

        $this->assertMatchesRegularExpression(
            '#<form[^>]*data-confirm="Refuser cette proposition \?[^"]+"#s',
            $body
        );
        $this->assertStringContainsString('<button type="submit" class="btn btn-sm btn-primary">Accepter</button>', $body);
        // And the refusal is not disguised as the neutral one of the pair.
        $this->assertStringContainsString('<button type="submit" class="btn btn-sm btn-outline-secondary">Refuser</button>', $body);
    }

    public function testARenterCannotDecideTheirOwnRequest(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();
        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);

        $requestId = $this->operationsService->requestChange(
            $booking,
            $this->trackedAsset(),
            \Modules\Rental\Booking\ChangeRequestOrigin::RENTER,
            \Modules\Rental\Booking\ChangeRequestKind::DATES,
            $this->arrival(90),
            $this->departure(93),
            null,
            null,
            null,
            null
        );

        $this->postToTracking('decideProposal', $bookingId, $token, [
            'request_id' => (string) $requestId,
            'decision' => 'accept',
        ]);

        $this->assertSame($this->arrival(), $this->bookingRepository->findById($bookingId)?->arrivalDate);
        $this->assertTrue($this->changeRequestRepository->findById($requestId)?->isPending());
    }

    public function testARenterCannotDecideAnotherRentersProposal(): void
    {
        // The booking check is the guard: a change-request id alone must not
        // be enough.
        $this->createAsset();
        [$firstId, $firstToken] = $this->submitAndTrack();

        $second = $this->submit($this->validBody([
            'arrival' => $this->arrival(120),
            'departure' => $this->departure(123),
            'email' => 'marc@example.be',
        ]));
        $this->assertSame(302, $second->getStatusCode(), (string) $second->getBody());
        $secondBooking = $this->bookingRepository->findById(2);
        $this->assertNotNull($secondBooking);

        $foreignRequestId = $this->operationsService->requestChange(
            $secondBooking,
            $this->trackedAsset(),
            \Modules\Rental\Booking\ChangeRequestOrigin::MANAGER,
            \Modules\Rental\Booking\ChangeRequestKind::CANCELLATION,
            null,
            null,
            null,
            null,
            null,
            null,
            1
        );

        $response = $this->postToTracking('decideProposal', $firstId, $firstToken, [
            'request_id' => (string) $foreignRequestId,
            'decision' => 'accept',
        ]);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertTrue($this->changeRequestRepository->findById($foreignRequestId)?->isPending());
    }

    public function testTheTrackingPageNeverShowsAnInternalComment(): void
    {
        // §6.6: the page does not even load them, which is stronger than a
        // template remembering to hide them.
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();
        $booking = $this->bookingRepository->findById($bookingId);
        $this->assertNotNull($booking);

        $this->operationsService->addComment($booking, 1, 'Groupe difficile, surveiller la cuisine.');

        $body = (string) $this->track($bookingId, $token)->getBody();

        $this->assertStringNotContainsString('Groupe difficile', $body);
        $this->assertStringNotContainsString('surveiller la cuisine', $body);
    }

    public function testTheTrackingPageOffersNoDownloadAtAll(): void
    {
        // §6.24/§6.26: an external renter downloads nothing from this site.
        // Their contract and invoice reach them by email and only by email,
        // and the tracking token is a capability for THIS page, never a
        // file credential. If a download link ever appears here, it is a
        // new exception to SECURITY.md §6 that the spec says must not
        // exist.
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $body = (string) $this->track($bookingId, $token)->getBody();

        $this->assertStringNotContainsString('/fichier/', $body);
        $this->assertStringNotContainsString('/file/', $body);
        $this->assertStringNotContainsString('download', strtolower($body));
        $this->assertStringNotContainsString('.pdf', strtolower($body));
    }

    public function testTheTrackingPageShowsThePriceTheRenterWasQuoted(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $body = (string) $this->track($bookingId, $token)->getBody();

        $this->assertStringContainsString('360,00', $body);
    }
}
