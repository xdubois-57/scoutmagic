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
use Core\Member\MemberService;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Rental\Controller\RentalConfigController;
use Modules\Rental\Mail\MailboxSelection;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAssetService;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalSlugGenerator;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;
use Twig\Environment;

/**
 * The park administration page: who may be designated a manager, and what a
 * save of that section is allowed to destroy.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RentalConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private RentalConfigController $controller;
    private RentalAssetRepository $assetRepository;
    private RentalAssetManagerRepository $managerRepository;
    private RentalManagerService $managerService;
    private SettingService $settingService;
    private EncryptionService $encryption;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RentalTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register(
            'asset_type_suggestions',
            'Local, Terrain',
            'text',
            'Types',
            'Types proposés.',
            'rental'
        );
        $settingService->register(
            RentalManagerService::SETTING_MINIMUM_AGE,
            (string) RentalManagerService::DEFAULT_MINIMUM_AGE,
            'number',
            'Âge minimum',
            'Âge minimum d\'un gestionnaire.',
            'rental'
        );

        $this->settingService = $settingService;

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearId = $scoutYearService->getCurrentYear()['id'];

        $this->assetRepository = new RentalAssetRepository($this->pdo, $this->encryption);
        $this->managerRepository = new RentalAssetManagerRepository($this->pdo);

        $memberService = new MemberService(
            new MemberYearRepository($this->pdo),
            $this->encryption,
            Connection::withPdo($this->pdo)
        );
        $journalService = new JournalService(new JournalRepository($this->pdo));

        $this->managerService = new RentalManagerService(
            $this->managerRepository,
            $memberService,
            $journalService,
            $settingService
        );

        $this->twig = TwigFactory::create(
            dirname(__DIR__, 4) . '/core/View/templates',
            false,
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
        );
        foreach ([
            'site_name' => 'Unité Test',
            'is_authenticated' => true,
            'current_user_role' => 'admin',
            'config_mode' => false,
            'cookie_consent_given' => true,
            'menus' => null,
            'current_path' => '/admin/locations',
            'csp_nonce' => 'test-nonce',
        ] as $key => $value) {
            $this->twig->addGlobal($key, $value);
        }

        $this->controller = new RentalConfigController(
            $this->twig,
            $this->assetRepository,
            new RentalAssetService(
                $this->assetRepository,
                new RentalSlugGenerator($this->assetRepository),
                $journalService
            ),
            $this->managerService,
            $scoutYearService,
            $settingService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        AuthSession::login(1, 'admin@test.be', 'admin');
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_POST = [];
    }

    private function createAsset(string $name = 'Local', string $slug = 'local'): int
    {
        return $this->assetRepository->create('Local', $name, $slug, null, 1, null, null, null, true);
    }

    /**
     * @param array{birth: ?string, first: string, last: string, totem: ?string} $overrides
     */
    private function createMember(string $deskId, array $overrides): int
    {
        $memberId = RentalTestHelper::insertMember($this->pdo, $deskId);
        RentalTestHelper::insertMemberYear(
            $this->pdo,
            $this->encryption,
            $memberId,
            $this->scoutYearId,
            strtolower($deskId) . '@test.be',
            $overrides['first'],
            $overrides['birth'],
            $overrides['last'],
            $overrides['totem']
        );

        return $memberId;
    }

    private static function yearsAgo(int $years): string
    {
        return (new \DateTimeImmutable('today'))->modify('-' . $years . ' years')->format('Y-m-d');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function postManagers(array $body): void
    {
        $body['_csrf_token'] = CsrfGuard::generateToken();
        $_POST = $body;

        $this->controller->saveManagers(new Request('POST', '/admin/locations/managers', [], $body, [], []), []);

        $_POST = [];
    }

    // ── The candidate pool ──────────────────────────────────────────────

    public function testTheSearchOffersAnAdultAndNotAChild(): void
    {
        $this->createMember('D-ADULT', ['first' => 'Marie', 'last' => 'Dupont', 'totem' => null, 'birth' => self::yearsAgo(41)]);
        $this->createMember('D-CHILD', ['first' => 'Lucas', 'last' => 'Dupont', 'totem' => null, 'birth' => self::yearsAgo(8)]);

        $payload = $this->search('dupont');

        $labels = array_map(static fn(array $row) => $row['label'], $payload);
        $this->assertCount(1, $payload);
        $this->assertStringContainsString('Marie', $labels[0]);
    }

    public function testAMemberWithNoBirthDateEncodedStaysSelectable(): void
    {
        // An incomplete Desk record must not silently make an adult
        // volunteer unselectable — the failure would be invisible on screen.
        $this->createMember('D-UNKNOWN', ['first' => 'Camille', 'last' => 'Sansdate', 'totem' => null, 'birth' => null]);

        $this->assertCount(1, $this->search('sansdate'));
    }

    public function testTheAgeBoundaryIsExactlyTheConfiguredValue(): void
    {
        $this->createMember('D-SIXTEEN', ['first' => 'Juste', 'last' => 'Seize', 'totem' => null, 'birth' => self::yearsAgo(16)]);
        $this->createMember('D-FIFTEEN', ['first' => 'Presque', 'last' => 'Quinze', 'totem' => null, 'birth' => self::yearsAgo(15)]);

        $this->assertCount(1, $this->search('seize'));
        $this->assertCount(0, $this->search('quinze'));
    }

    public function testAConfiguredAgeIsObeyed(): void
    {
        $this->settingService->set(RentalManagerService::SETTING_MINIMUM_AGE, '18', 'rental');

        $this->createMember('D-SEVENTEEN', ['first' => 'Dix', 'last' => 'Sept', 'totem' => null, 'birth' => self::yearsAgo(17)]);

        $this->assertCount(0, $this->search('sept'));
    }

    public function testTheSearchMatchesATotemAsWellAsAName(): void
    {
        $this->createMember('D-TOTEM', ['first' => 'Sophie', 'last' => 'Martin', 'totem' => 'Akéla', 'birth' => self::yearsAgo(25)]);

        $this->assertCount(1, $this->search('akéla'));
        $this->assertCount(1, $this->search('martin'));
    }

    public function testAOneLetterQueryIsNotASearch(): void
    {
        // In a unit of three hundred, one letter is the whole roster with
        // extra steps.
        $this->createMember('D-ADULT', ['first' => 'Marie', 'last' => 'Dupont', 'totem' => null, 'birth' => self::yearsAgo(41)]);

        $this->assertSame([], $this->search('d'));
    }

    public function testTheSearchIsCappedAtTenResults(): void
    {
        for ($i = 0; $i < 15; $i++) {
            $this->createMember('D-' . $i, [
                'first' => 'Prénom' . $i,
                'last' => 'Commun',
                'totem' => null,
                'birth' => self::yearsAgo(30),
            ]);
        }

        $this->assertCount(RentalManagerService::SEARCH_RESULT_LIMIT, $this->search('commun'));
    }

    public function testTheSearchPayloadCarriesNoEmailAddress(): void
    {
        // A grant names a member, not a login: there is nothing here to tell
        // two addresses of the same human apart, so there is no reason to
        // show one.
        $this->createMember('D-ADULT', ['first' => 'Marie', 'last' => 'Dupont', 'totem' => null, 'birth' => self::yearsAgo(41)]);

        $raw = json_encode($this->search('dupont'), JSON_UNESCAPED_UNICODE);

        $this->assertIsString($raw);
        $this->assertStringNotContainsString('@', $raw);
        $this->assertStringNotContainsString('d-adult@test.be', strtolower($raw));
    }

    /**
     * @return array<int, array{id: int, label: string, sublabel: string}>
     */
    private function search(string $query): array
    {
        $response = $this->controller->searchManagers(
            new Request('GET', '/admin/locations/gestionnaire-recherche', ['q' => $query], [], [], []),
            []
        );

        $decoded = json_decode((string) $response->getBody(), true);
        $this->assertIsArray($decoded);

        /** @var array<int, array{id: int, label: string, sublabel: string}> $decoded */
        return $decoded;
    }

    // ── Saving the section ──────────────────────────────────────────────

    public function testAManagerDeactivatedByTheLastImportSurvivesASave(): void
    {
        // The regression this whole section was rebuilt around. The form
        // could only ever render members the picker offers, and revoke() is
        // a DELETE — so a manager the last Desk import dropped was silently
        // and permanently deleted by every save, exactly the grant the
        // warning above the section promises is kept.
        $assetId = $this->createAsset();
        $kept = $this->createMember('D-GONE', ['first' => 'Parti', 'last' => 'Dulieu', 'totem' => null, 'birth' => self::yearsAgo(40)]);
        $this->managerRepository->grant($assetId, $kept, false);
        $this->managerRepository->deactivateForMembers([$kept]);

        // The page renders the suspended manager with its own ticked
        // checkbox, so a save that leaves it alone posts it back.
        $this->postManagers([
            'asset_id' => (string) $assetId,
            'manager_member_ids' => [(string) $kept],
        ]);

        $grant = $this->managerRepository->findByAssetAndMember($assetId, $kept);
        $this->assertNotNull($grant, 'The suspended grant must survive a save of this section.');
        $this->assertFalse($grant->isActive, 'And it must stay suspended — the import decides that, not this form.');
    }

    public function testASuspendedManagerIsNotReactivatedByASave(): void
    {
        // grant() flips is_active back on. Re-granting a member the roster
        // no longer lists would hand access back to somebody Desk says is
        // gone; the import reactivates them on its own when they reappear.
        $assetId = $this->createAsset();
        $suspended = $this->createMember('D-GONE', ['first' => 'Parti', 'last' => 'Dulieu', 'totem' => null, 'birth' => self::yearsAgo(40)]);
        $this->managerRepository->grant($assetId, $suspended, false);
        $this->managerRepository->deactivateForMembers([$suspended]);

        $this->postManagers([
            'asset_id' => (string) $assetId,
            'manager_member_ids' => [(string) $suspended],
            'renter_contact_member_ids' => [(string) $suspended],
        ]);

        $grant = $this->managerRepository->findByAssetAndMember($assetId, $suspended);
        $this->assertNotNull($grant);
        $this->assertFalse($grant->isActive);
        // The one thing the form does own for a suspended grant.
        $this->assertTrue($grant->isRenterContact);
    }

    public function testUntickingAManagerRevokesTheGrant(): void
    {
        // The other half: a chief who deliberately removes somebody must
        // still be obeyed, suspended or not.
        $assetId = $this->createAsset();
        $removed = $this->createMember('D-OUT', ['first' => 'Sortant', 'last' => 'Dulieu', 'totem' => null, 'birth' => self::yearsAgo(40)]);
        $this->managerRepository->grant($assetId, $removed, false);

        $this->postManagers(['asset_id' => (string) $assetId, 'manager_member_ids' => []]);

        $this->assertNull($this->managerRepository->findByAssetAndMember($assetId, $removed));
    }

    public function testAForgedMemberIdIsNotHonoured(): void
    {
        // Neither on the roster nor an existing grant: the page could not
        // have offered it, so the save must not create it.
        $assetId = $this->createAsset();
        $child = $this->createMember('D-CHILD', ['first' => 'Lucas', 'last' => 'Petit', 'totem' => null, 'birth' => self::yearsAgo(8)]);

        $this->postManagers([
            'asset_id' => (string) $assetId,
            'manager_member_ids' => [(string) $child, '999999'],
        ]);

        $this->assertNull($this->managerRepository->findByAssetAndMember($assetId, $child));
        $this->assertSame([], $this->managerRepository->findAllByAsset($assetId, false));
    }

    public function testCreationHonoursTheChosenBillingUnit(): void
    {
        // Asked at creation because it decides the calendar, the price and
        // the availability model together (§6.8) — a default nobody chose
        // was the first wrong thing every new asset carried.
        $this->postCreate(['name' => 'Remorque de camp', 'asset_type' => 'Local', 'billing_unit' => 'per_day']);

        $this->assertSame('per_day', $this->billingUnitOf('Remorque de camp'));
    }

    public function testAForgedBillingUnitFallsBackToTheSchemaDefault(): void
    {
        // An unknown value must not fail the whole creation — the unit is a
        // starting point, correctable from the asset's own settings.
        $this->postCreate(['name' => 'Local bis', 'asset_type' => 'Local', 'billing_unit' => 'per_hour']);

        $this->assertSame('flat_stay', $this->billingUnitOf('Local bis'));
    }

    public function testAPublicAssetWithNoRateIsFlaggedOnTheParkPage(): void
    {
        // The setup gap a visitor meets first: public, but priced by nobody.
        // Every estimate answers "Tarif sur demande" until the tariff is
        // filled in, and this page is where a chief has to learn it.
        $this->createAsset();

        $body = (string) $this->controllerWithPricing()
            ->index(new Request('GET', '/admin/locations', [], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('Tarif manquant', $body);
        $this->assertStringContainsString('encore aucun tarif', $body);
    }

    /**
     * The park page reads as facts, and offers to change them
     * (design.md §1.9).
     *
     * Five forms stood open at once — général, gestionnaires, compte,
     * courrier, création — so five `btn-primary` competed on one screen.
     * Only creating a bien is a creation action, which §7.4 keeps
     * primary; every edit is a dialog now.
     */
    public function testTheParkPageEditsThroughDialogsAndKeepsOneCreationPrimary(): void
    {
        $this->createAsset();

        $body = (string) preg_replace(
            '/\s+/',
            ' ',
            $this->controllerWithPricing()
                ->index(new Request('GET', '/admin/locations', [], [], [], []), [])
                ->getBody()
        );

        foreach (['#general-edit', '#gestionnaires-edit'] as $target) {
            $this->assertStringContainsString('data-bs-target="' . $target . '"', $body, $target);
        }

        // The forms are unchanged: same action, reachable from the
        // dialog's own footer.
        foreach ([
            '/admin/locations/general' => 'asset-general-form',
            '/admin/locations/managers' => 'rental-managers-form',
        ] as $action => $formId) {
            $this->assertStringContainsString('action="' . $action . '" id="' . $formId . '"', $body, $action);
            $this->assertStringContainsString('form="' . $formId . '"', $body, $formId);
        }

        // Creating stays inline and stays the screen's one primary: it is
        // the only submit outside a dialog.
        $pageBody = substr($body, 0, strpos($body, '<div class="modal fade"') ?: strlen($body));
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*type="submit"[^>]*btn-primary/',
            $pageBody
        );
        $this->assertStringContainsString('Créer le bien', $body);
    }

    public function testArchivingAnAssetSaysWhatItCostsBeforeItHappens(): void
    {
        // « Archiver » sat in `btn-outline-warning` and fired on the first
        // click, right next to « Supprimer définitivement », which asked.
        // The louder-looking of the two was the one that never asked.
        $this->createAsset();

        $body = (string) $this->controllerWithPricing()
            ->index(new Request('GET', '/admin/locations', [], [], [], []), [])
            ->getBody();

        $this->assertMatchesRegularExpression(
            '#<form[^>]*action="/admin/locations/archive"[^>]*data-confirm="[^"]+"#s',
            $body
        );
        $this->assertStringContainsString("Rien n'est supprimé.", $body);
    }

    public function testAPricedAssetIsNotFlagged(): void
    {
        $assetId = $this->createAsset();
        (new \Modules\Rental\Service\RentalPricingService(
            new \Modules\Rental\Repository\RentalPricingRepository($this->pdo),
            new \Modules\Rental\Pricing\RentalPricingEngine(),
            new JournalService(new JournalRepository($this->pdo))
        ))->saveAssetPricing($assetId, 'per_night', 8000, null, null);

        $body = (string) $this->controllerWithPricing()
            ->index(new Request('GET', '/admin/locations', [], [], [], []), [])
            ->getBody();

        $this->assertStringNotContainsString('Tarif manquant', $body);
        $this->assertStringNotContainsString('encore aucun tarif', $body);
    }

    /**
     * @param array<string, string> $body
     */
    private function postCreate(array $body): void
    {
        $body['_csrf_token'] = CsrfGuard::generateToken();
        $body['quantity'] ??= '1';
        $_POST = $body;

        $this->controller->create(new Request('POST', '/admin/locations/create', [], $body, [], []), []);
    }

    private function billingUnitOf(string $name): string
    {
        $stmt = $this->pdo->prepare('SELECT billing_unit FROM rental_assets WHERE name = ?');
        $stmt->execute([$name]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * The controller as `public/index.php` wires it — pricing service
     * included, which the setUp() instance omits because most tests here
     * have no use for it.
     */
    private function controllerWithPricing(): RentalConfigController
    {
        return new RentalConfigController(
            $this->twig,
            $this->assetRepository,
            new RentalAssetService(
                $this->assetRepository,
                new RentalSlugGenerator($this->assetRepository),
                new JournalService(new JournalRepository($this->pdo))
            ),
            $this->managerService,
            new ScoutYearService($this->pdo),
            $this->settingService,
            null,
            null,
            new \Modules\Rental\Service\RentalPricingService(
                new \Modules\Rental\Repository\RentalPricingRepository($this->pdo),
                new \Modules\Rental\Pricing\RentalPricingEngine(),
                new JournalService(new JournalRepository($this->pdo))
            )
        );
    }

    public function testAnOrdinaryGrantStillWorks(): void
    {
        $assetId = $this->createAsset();
        $manager = $this->createMember('D-MGR', ['first' => 'Marie', 'last' => 'Dupont', 'totem' => null, 'birth' => self::yearsAgo(41)]);

        $this->postManagers([
            'asset_id' => (string) $assetId,
            'manager_member_ids' => [(string) $manager],
            'renter_contact_member_ids' => [(string) $manager],
        ]);

        $grant = $this->managerRepository->findByAssetAndMember($assetId, $manager);
        $this->assertNotNull($grant);
        $this->assertTrue($grant->isActive);
        $this->assertTrue($grant->isRenterContact);
    }

    // ── saveGeneral() (§6.11) ───────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function postGeneral(array $body): \Core\Http\Response
    {
        $body['_csrf_token'] = CsrfGuard::generateToken();
        $_POST = $body;

        return $this->controller->saveGeneral(
            new Request('POST', '/admin/locations/general', [], $body, [], []),
            []
        );
    }

    public function testSavingTheGeneralSectionWritesEveryFieldAndComesBackToTheAsset(): void
    {
        $assetId = $this->createAsset();

        $response = $this->postGeneral([
            'asset_id' => (string) $assetId,
            'name' => 'Local Saint-Georges',
            'asset_type' => 'Local',
            'capacity' => '60',
            'quantity' => '1',
            'arrival_time' => '18:00',
            'departure_time' => '11:00',
            'emergency_phone' => '+32 470 11 22 33',
            'is_public' => '1',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/locations?asset_id=' . $assetId, $response->getHeaders()['Location'] ?? null);

        $asset = $this->assetRepository->findById($assetId);
        $this->assertSame('Local Saint-Georges', $asset?->name);
        $this->assertSame(60, $asset->capacity);
        $this->assertSame('18:00', $asset->arrivalTime);
        $this->assertSame('11:00', $asset->departureTime);
        $this->assertTrue($asset->isPublic);
    }

    /**
     * An unticked checkbox is not submitted at all, which is the whole
     * reason `is_public` is read as "is the key present" rather than as a
     * value: a form that can only ever turn a flag ON is a flag nobody can
     * turn off.
     */
    public function testAnUntickedPublicBoxTakesTheAssetOffThePublicPage(): void
    {
        $assetId = $this->createAsset();

        $this->postGeneral([
            'asset_id' => (string) $assetId,
            'name' => 'Local',
            'asset_type' => 'Local',
            'quantity' => '1',
        ]);

        $this->assertFalse($this->assetRepository->findById($assetId)?->isPublic);
    }

    public function testBlankOptionalFieldsAreStoredAsAbsentRatherThanAsEmptyStrings(): void
    {
        $assetId = $this->createAsset();

        $this->postGeneral([
            'asset_id' => (string) $assetId,
            'name' => 'Local',
            'asset_type' => 'Local',
            'quantity' => '1',
            'capacity' => '',
            'arrival_time' => '   ',
            'departure_time' => '',
            'emergency_phone' => '  ',
        ]);

        $asset = $this->assetRepository->findById($assetId);
        $this->assertNull($asset?->capacity);
        $this->assertNull($asset->arrivalTime);
        $this->assertNull($asset->departureTime);
        $this->assertNull($asset->emergencyPhone);
    }

    public function testAQuantityBelowOneIsRaisedRatherThanStored(): void
    {
        $assetId = $this->createAsset();

        $this->postGeneral([
            'asset_id' => (string) $assetId,
            'name' => 'Local',
            'asset_type' => 'Local',
            'quantity' => '0',
        ]);

        $this->assertSame(1, $this->assetRepository->findById($assetId)?->quantity);
    }

    public function testAnEmptyNameIsRefusedAndChangesNothing(): void
    {
        $assetId = $this->createAsset('Local original', 'local-original');

        $response = $this->postGeneral([
            'asset_id' => (string) $assetId,
            'name' => '   ',
            'asset_type' => 'Local',
            'quantity' => '1',
        ]);

        $this->assertSame(302, $response->getStatusCode(), 'a refusal is a flash, not a crash');
        $this->assertSame('Local original', $this->assetRepository->findById($assetId)?->name);
    }

    public function testSavingWithoutACsrfTokenChangesNothing(): void
    {
        $assetId = $this->createAsset('Local original', 'local-original');
        $_POST = [];

        $this->controller->saveGeneral(
            new Request('POST', '/admin/locations/general', [], ['asset_id' => (string) $assetId, 'name' => 'Volé'], [], []),
            []
        );

        $this->assertSame('Local original', $this->assetRepository->findById($assetId)?->name);
    }

    // ── saveMailboxes() (§7.4) ──────────────────────────────────────────

    /**
     * @param array<int, string> $mailboxIds
     */
    private function postMailboxes(array $mailboxIds, ?MailboxSelection $selection = null): \Core\Http\Response
    {
        $this->settingService->register(
            MailboxSelection::SETTING_KEY,
            '',
            'text',
            'Boîtes surveillées',
            'Les boîtes que ce module écoute.',
            'rental'
        );

        $controller = new RentalConfigController(
            $this->twig,
            $this->assetRepository,
            new RentalAssetService(
                $this->assetRepository,
                new RentalSlugGenerator($this->assetRepository),
                new JournalService(new JournalRepository($this->pdo))
            ),
            $this->managerService,
            new ScoutYearService($this->pdo),
            $this->settingService,
            null,
            $selection
        );

        $body = ['mailbox_ids' => $mailboxIds, '_csrf_token' => CsrfGuard::generateToken()];
        $_POST = $body;

        return $controller->saveMailboxes(
            new Request('POST', '/admin/locations/mailboxes', [], $body, [], []),
            []
        );
    }

    public function testSavingTheWatchedMailboxesStoresTheChosenOnes(): void
    {
        $selection = new MailboxSelection($this->settingService, new TwoMailboxes());

        $response = $this->postMailboxes(['2'], $selection);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/admin/locations#courrier', $response->getHeaders()['Location'] ?? null);
        $this->assertSame([2], $selection->selectedIds());
    }

    /**
     * Filtered against what actually exists: a stale id from a deleted box
     * would silently narrow the selection to something no manager could
     * see on the page, or undo.
     */
    public function testAnIdThatIsNotAKnownMailboxIsDropped(): void
    {
        $selection = new MailboxSelection($this->settingService, new TwoMailboxes());

        $this->postMailboxes(['2', '999'], $selection);

        $this->assertSame([2], $selection->selectedIds());
    }

    public function testSubmittingNothingClearsTheSelection(): void
    {
        $selection = new MailboxSelection($this->settingService, new TwoMailboxes());
        $this->postMailboxes(['2', '3'], $selection);

        $this->postMailboxes([], $selection);

        $this->assertSame([], $selection->selectedIds());
    }

    /**
     * Without `inbound_mail` there is nothing to select from, and the route
     * answers as if it did not exist rather than storing a selection the
     * module could never act on.
     */
    public function testTheRouteIsNotThereWithoutTheInboundMailModule(): void
    {
        $response = $this->postMailboxes(['2'], null);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testSavingMailboxesWithoutACsrfTokenStoresNothing(): void
    {
        $selection = new MailboxSelection($this->settingService, new TwoMailboxes());
        $_POST = [];

        $controller = new RentalConfigController(
            $this->twig,
            $this->assetRepository,
            new RentalAssetService(
                $this->assetRepository,
                new RentalSlugGenerator($this->assetRepository),
                new JournalService(new JournalRepository($this->pdo))
            ),
            $this->managerService,
            new ScoutYearService($this->pdo),
            $this->settingService,
            null,
            $selection
        );
        $controller->saveMailboxes(
            new Request('POST', '/admin/locations/mailboxes', [], ['mailbox_ids' => ['2']], [], []),
            []
        );

        $this->assertSame([], $selection->selectedIds());
    }
}

/**
 * Two configured mailboxes, as `listMailboxSummaries()` exposes them — a
 * name and a state, never the host or the account (§8.58).
 *
 * @internal
 */
class TwoMailboxes implements \Modules\InboundMail\Api\InboundMailInterface
{
    use \Tests\Modules\InboundMail\InertInboundMail;

    /** @return \Modules\InboundMail\Api\InboundMessage[] */
    public function findForReference(string $consumerId, string $businessReference): array
    {
        return [];
    }

    public function findOneForReference(
        string $consumerId,
        string $businessReference,
        int $messageId
    ): ?\Modules\InboundMail\Api\InboundMessage {
        return null;
    }

    /** @param int[] $preserveFileIds */
    public function detach(
        string $consumerId,
        string $businessReference,
        int $messageId,
        array $preserveFileIds = []
    ): bool {
        return false;
    }

    public function move(string $consumerId, string $fromReference, string $toReference, int $messageId): bool
    {
        return false;
    }

    public function purgeReference(string $consumerId, string $businessReference): int
    {
        return 0;
    }

    public function isCollecting(): bool
    {
        return true;
    }

    /** @param string[] $messageIds */
    public function findReferenceByThread(string $consumerId, int $mailboxId, array $messageIds): ?string
    {
        return null;
    }

    /** @return array<int, array{name: string, state: string, is_enabled: bool}> */
    public function listMailboxSummaries(): array
    {
        return [
            2 => ['name' => 'Boîte location', 'state' => 'ok', 'is_enabled' => true],
            3 => ['name' => 'Boîte unité', 'state' => 'ok', 'is_enabled' => true],
        ];
    }
}
