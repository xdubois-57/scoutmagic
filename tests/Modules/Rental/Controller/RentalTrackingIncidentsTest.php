<?php

declare(strict_types=1);

namespace Tests\Modules\Rental\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Http\Response;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\MemberService;
use Core\Member\Repository\MemberProfileRepository;
use Core\Security\EncryptionService;
use Core\Security\HumanCheck\HumanCheckRateLimitRepository;
use Core\Security\HumanCheck\HumanCheckService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Rental\Availability\AvailabilityCalculator;
use Modules\Rental\Booking\RentalBooking;
use Modules\Rental\Controller\RentalRequestController;
use Modules\Rental\Pricing\BillingUnit;
use Modules\Rental\Pricing\PriceLine;
use Modules\Rental\Pricing\PriceQuote;
use Modules\Rental\Pricing\RentalPricingEngine;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingCommentRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalConditionsVersionRepository;
use Modules\Rental\Repository\RentalConstraintsRepository;
use Modules\Rental\Repository\RentalModificationRepository;
use Modules\Rental\Repository\RentalPricingRepository;
use Modules\Rental\Repository\RentalStayRepository;
use Modules\Rental\Service\RentalAvailabilityService;
use Modules\Rental\Service\RentalBookingMailService;
use Modules\Rental\Service\RentalBookingService;
use Modules\Rental\Service\RentalConditionsService;
use Modules\Rental\Service\RentalJourneyService;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalOperationsService;
use Modules\Rental\Service\RentalPricingService;
use Modules\Rental\Service\RentalStayService;
use Modules\Rental\Stay\IncidentDecision;
use Modules\Rental\Stay\SettlementCalculator;
use PHPUnit\Framework\TestCase;
use Tests\Core\Mail\Template\EmailTemplateRendererFactory;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;

/**
 * « Incidents et dégâts » on the renter's tracking page (#980, §22.5): the
 * decided incidents with their date and « Facturé : … » / « Non facturé »;
 * one still being assessed reduced to a single sentence, its description
 * and amount absent from the HTML; a waive reason never shown.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalTrackingIncidentsTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private RentalBookingRepository $bookingRepository;
    private RentalAssetRepository $assetRepository;
    private RentalPricingService $pricing;
    private RentalStayService $stay;
    private int $assetId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $journal = new JournalService(new JournalRepository($this->pdo));

        $this->bookingRepository = new RentalBookingRepository($this->pdo, $this->encryption);
        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->pricing = new RentalPricingService(
            new RentalPricingRepository($this->pdo),
            new RentalPricingEngine(),
            $journal
        );
        $this->stay = new RentalStayService(
            new RentalStayRepository($this->pdo, $this->encryption),
            RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
            $this->pricing,
            new SettlementCalculator(),
            $journal
        );

        $this->assetId = $this->assetRepository->create(
            'Local',
            'Local Saint-Georges',
            'local-saint-georges',
            60,
            1,
            null,
            null,
            null,
            true
        );
    }

    private function controller(): RentalRequestController
    {
        $settingService = new SettingService(new SettingRepository($this->pdo));
        $journal = new JournalService(new JournalRepository($this->pdo));
        $connection = Connection::withPdo($this->pdo);
        $editable = new EditableContentService(new EditableContentRepository($this->pdo));
        $bookingService = new RentalBookingService($this->bookingRepository, $journal);
        $pricing = $this->pricing;
        $availability = new RentalAvailabilityService(
            new AvailabilityCalculator(),
            new RentalConstraintsRepository($this->pdo),
            [$bookingService]
        );
        $modifications = new RentalModificationRepository($this->pdo);

        $twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
        );
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', false);
        $twig->addGlobal('current_user_role', 'public');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/locations');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        return new RentalRequestController(
            $twig,
            $this->assetRepository,
            $bookingService,
            $availability,
            $pricing,
            new RentalBookingMailService(
                $this->createStub(\Core\Mail\MailService::class),
                EmailTemplateRendererFactory::shippedOnlyForModule($twig, 'rental'),
                $settingService,
                $journal,
                journey: new RentalJourneyService($this->bookingRepository)
            ),
            new RentalManagerService(
                new RentalAssetManagerRepository($this->pdo),
                new MemberService(
                    new MemberYearRepository($this->pdo),
                    new MemberProfileRepository($connection, $this->encryption)
                ),
                $journal
            ),
            new ScoutYearService($this->pdo),
            $editable,
            new HumanCheckService(
                $this->encryption,
                new HumanCheckRateLimitRepository($this->pdo),
                $settingService,
                $journal
            ),
            $settingService,
            new RentalOperationsService(
                $this->bookingRepository,
                RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
                new RentalBookingCommentRepository($this->pdo, $this->encryption),
                $modifications,
                $availability,
                $pricing,
                $journal
            ),
            new RentalConditionsService(new RentalConditionsVersionRepository($this->pdo), $editable),
            stayService: $this->stay
        );
    }

    /** @return array{id: int, token: string} */
    private function createBooking(): array
    {
        $created = $this->bookingRepository->create(
            $this->assetId,
            'LOC-A2B3C4',
            '2027-03-12',
            '2027-03-15',
            1,
            32,
            null,
            [
                'name' => 'Jeanne Martin',
                'email' => 'jeanne@example.be',
                'phone' => null,
                'organisation' => 'Patro Sainte-Anne',
                'purpose' => null,
                'comment' => null,
            ],
            new PriceQuote(
                lines: [new PriceLine('Séjour', 1, null, 66800, PriceLine::RULE_BASE)],
                totalCents: 66800,
                nights: 3,
                persons: 32,
                quantity: 1,
                billingUnit: BillingUnit::FLAT_STAY
            ),
            null,
            null,
            'v1',
            str_repeat('0', 64),
            'v1',
            str_repeat('0', 64),
            new \DateTimeImmutable('2027-01-10 10:00:00')
        );

        return ['id' => $created['id'], 'token' => $created['tracking_token']];
    }

    private function booking(array $created): RentalBooking
    {
        $booking = $this->bookingRepository->findById($created['id']);
        $this->assertNotNull($booking);

        return $booking;
    }

    private function track(array $booking): string
    {
        $response = $this->controller()->tracking(
            new Request('GET', '/locations/suivi/' . $booking['id'] . '/' . $booking['token'], [], [], [], []),
            ['id' => (string) $booking['id'], 'token' => $booking['token']]
        );
        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(200, $response->getStatusCode());

        return (string) preg_replace('/\s+/', ' ', (string) $response->getBody());
    }

    public function testTheRenterSeesTheDecidedIncidentsWithTheirDateAndWhetherTheyAreBilled(): void
    {
        $created = $this->createBooking();
        $booking = $this->booking($created);
        $charged = $this->stay->reportIncident($booking, 'Vitre de la cuisine cassée', 9000, null, 1);
        $waived = $this->stay->reportIncident($booking, 'Tache sur le parquet', 3000, null, 1);
        $this->pdo->prepare('UPDATE rental_incidents SET created_at = ? WHERE id IN (?, ?)')
            ->execute(['2027-03-14 18:30:00', $charged, $waived]);
        $this->stay->decideIncident($booking, $charged, IncidentDecision::CHARGE, 12000, 1);
        $this->stay->decideIncident($booking, $waived, IncidentDecision::WAIVE, null, 1);

        $body = $this->track($created);

        $this->assertStringContainsString('id="incidents"', $body);
        $this->assertStringContainsString('Incidents et dégâts', $body);
        $this->assertStringContainsString('Vitre de la cuisine cassée', $body);
        $this->assertStringContainsString('Tache sur le parquet', $body);
        $this->assertStringContainsString('Constaté le 14/03/2027', $body);
        // The decided amount, not the one proposed.
        $this->assertStringContainsString('Facturé : 120,00 €', $body);
        $this->assertStringNotContainsString('90,00 €', $body);
        $this->assertStringContainsString('Non facturé', $body);
        // What a waived damage was estimated at is nobody's business now.
        $this->assertStringNotContainsString('30,00 €', $body);
        $this->assertStringNotContainsString('data-renter-incident-assessment', $body);
    }

    public function testAChargeWithoutADecidedAmountShowsTheProposedOne(): void
    {
        // « Facturer » without retyping the figure means the one proposed
        // (Incident::effectiveAmountCents()).
        $created = $this->createBooking();
        $booking = $this->booking($created);
        $id = $this->stay->reportIncident($booking, 'Chaise brisée', 4500, null, 1);
        $this->stay->decideIncident($booking, $id, IncidentDecision::CHARGE, null, 1);

        $this->assertStringContainsString('Facturé : 45,00 €', $this->track($created));
    }

    public function testAnIncidentUnderAssessmentIsOnlyTheSentence(): void
    {
        $created = $this->createBooking();
        $booking = $this->booking($created);
        $pending = $this->stay->reportIncident($booking, 'Infiltration dans le dortoir', 85000, null, 1);
        $this->stay->decideIncident(
            $booking,
            $pending,
            IncidentDecision::PENDING,
            70000,
            1,
            'Expertise de l\'assurance',
            'SIN-2027-0042'
        );
        $decided = $this->stay->reportIncident($booking, 'Ampoule grillée', 500, null, 1);
        $this->stay->decideIncident($booking, $decided, IncidentDecision::WAIVE, null, 1);

        $body = $this->track($created);

        // This asset takes no deposit (no payment settings here), so the
        // sentence does not claim one is held.
        $this->assertStringContainsString(
            'Un dégât constaté pendant votre séjour est en cours d\'évaluation.',
            $body
        );
        $this->assertStringNotContainsString('caution', $body);
        $this->assertSame(1, substr_count($body, 'data-renter-incident-assessment'));
        // Neither its description, nor an amount, nor what it waits for.
        $this->assertStringNotContainsString('Infiltration', $body);
        $this->assertStringNotContainsString('850,00', $body);
        $this->assertStringNotContainsString('700,00', $body);
        $this->assertStringNotContainsString('Expertise', $body);
        $this->assertStringNotContainsString('SIN-2027-0042', $body);
        // The decided one beside it is still listed.
        $this->assertStringContainsString('Ampoule grillée', $body);
        $this->assertSame(1, substr_count($body, 'data-renter-incident>'));
    }

    public function testTheAssessmentIsNeverBuiltForThePage(): void
    {
        // Not merely hidden by the template: the object the page receives
        // carries nothing of it (ARCHITECTURE.md §7.6, rule 3).
        $created = $this->createBooking();
        $this->stay->reportIncident($this->booking($created), 'Infiltration dans le dortoir', 85000, null, 1);

        $incidents = $this->stay->renterIncidents($created['id']);

        $this->assertTrue($incidents->assessmentInProgress);
        $this->assertSame([], $incidents->decided);
        $this->assertStringNotContainsString('Infiltration', serialize($incidents));
        $this->assertStringNotContainsString('85000', serialize($incidents));
    }

    public function testAWaiveReasonNeverReachesTheRenter(): void
    {
        $created = $this->createBooking();
        $booking = $this->booking($created);
        $id = $this->stay->reportIncident($booking, 'Porte rayée', 2000, null, 1);
        $this->stay->decideIncident(
            $booking,
            $id,
            IncidentDecision::WAIVE,
            null,
            1,
            null,
            null,
            'Groupe fidèle, geste envers Mme Martin'
        );

        $body = $this->track($created);

        $this->assertStringContainsString('Porte rayée', $body);
        $this->assertStringContainsString('Non facturé', $body);
        $this->assertStringNotContainsString('Groupe fidèle', $body);
        $this->assertStringNotContainsString('Motif', $body);
    }

    public function testAWithheldIncidentLeftInTheDatabaseReadsAsBilled(): void
    {
        // The retired value, before IncidentDecisionMigration has run.
        $created = $this->createBooking();
        $id = $this->stay->reportIncident($this->booking($created), 'Matelas taché', 6000, null, 1);
        $this->pdo->prepare('UPDATE rental_incidents SET decision = ? WHERE id = ?')
            ->execute([IncidentDecision::LEGACY_WITHHOLD, $id]);

        $body = $this->track($created);

        $this->assertStringContainsString('Matelas taché', $body);
        $this->assertStringContainsString('Facturé : 60,00 €', $body);
        $this->assertStringNotContainsString('data-renter-incident-assessment', $body);
    }

    public function testWithoutAnIncidentThereIsNoSection(): void
    {
        $this->assertStringNotContainsString('id="incidents"', $this->track($this->createBooking()));
    }

    public function testAWrongTokenIsNotFound(): void
    {
        $created = $this->createBooking();
        $this->stay->reportIncident($this->booking($created), 'Vitre de la cuisine cassée', 9000, null, 1);

        $response = $this->controller()->tracking(
            new Request('GET', '/locations/suivi/' . $created['id'] . '/wrong', [], [], [], []),
            ['id' => (string) $created['id'], 'token' => str_repeat('f', 64)]
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringNotContainsString('Vitre', (string) $response->getBody());
    }
}
