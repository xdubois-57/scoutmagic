<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

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
use Modules\Rental\Controller\RentalRequestController;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingMailService;
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
    private RentalConstraintsRepository $constraintsRepository;
    private RentalPricingService $pricingService;
    private EditableContentService $editableContentService;
    private EncryptionService $encryption;
    private int $scoutYearId;

    /** @var list<array{to: string, subject: string, html: string, text: string, headers: array<string, string>}> */
    private array $sentMail = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settingService = new SettingService(new SettingRepository($this->pdo));
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

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
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
                $this->twig,
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
            $settingService
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
            $isPublic,
            false
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

        $this->assertSame(403, $this->submit($body)->getStatusCode());
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

        $otherAssetId = $this->assetRepository->create('Local', 'Autre', 'autre', null, 1, null, null, null, true, false);
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

    public function testTheTrackingPageShowsThePriceTheRenterWasQuoted(): void
    {
        $this->createAsset();
        [$bookingId, $token] = $this->submitAndTrack();

        $body = (string) $this->track($bookingId, $token)->getBody();

        $this->assertStringContainsString('360,00', $body);
    }
}
