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
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Service\RentalAssetService;
use Modules\Rental\Service\RentalManagerService;
use Modules\Rental\Service\RentalSlugGenerator;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Rental\RentalTestHelper;
use Twig\Environment;
use Core\Member\Repository\MemberProfileRepository;

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
    new MemberProfileRepository(Connection::withPdo($this->pdo), $this->encryption)
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
            ['rental' => dirname(__DIR__, 4) . '/modules/rental/views', 'inbound_mail' => dirname(__DIR__, 4) . '/modules/inbound_mail/views']
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
        // filled in, and this page is where a chief has to learn it: a
        // badge on the asset's line, the warning on its own page.
        $assetId = $this->createAsset();
        $controller = $this->controllerWithPricing();

        $this->assertStringContainsString('Tarif manquant', $this->listPage($controller));
        $this->assertStringContainsString('encore aucun tarif', $this->assetPage($assetId, $controller));
    }

    /**
     * An asset's page reads as facts, and offers to change them
     * (design.md §1.9).
     *
     * Five forms once stood open at once — général, gestionnaires, compte,
     * courrier, création — so five `btn-primary` competed on one screen.
     * Every edit is a dialog, and creating has its own page now (#748).
     */
    public function testTheAssetPageEditsThroughDialogsAndHasNoPrimaryOutsideThem(): void
    {
        $assetId = $this->createAsset();

        $body = (string) preg_replace('/\s+/', ' ', $this->assetPage($assetId, $this->controllerWithPricing()));

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

        // No submit outside a dialog is primary, and nothing here creates.
        $pageBody = substr($body, 0, strpos($body, '<div class="modal fade"') ?: strlen($body));
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*type="submit"[^>]*btn-primary/',
            $pageBody
        );
        $this->assertStringNotContainsString('/admin/locations/create', $body);
    }

    public function testArchivingAnAssetSaysWhatItCostsBeforeItHappens(): void
    {
        // « Archiver » sat in `btn-outline-warning` and fired on the first
        // click, right next to « Supprimer définitivement », which asked.
        // The louder-looking of the two was the one that never asked.
        $assetId = $this->createAsset();

        foreach ([$this->listPage(), $this->assetPage($assetId)] as $body) {
            $this->assertMatchesRegularExpression(
                '#<form[^>]*action="/admin/locations/archive"[^>]*data-confirm="[^"]+"#s',
                $body
            );
            $this->assertStringContainsString("Rien n'est supprimé.", $body);
        }
    }

    public function testAPricedAssetIsNotFlagged(): void
    {
        $assetId = $this->createAsset();
        (new \Modules\Rental\Service\RentalPricingService(
            new \Modules\Rental\Repository\RentalPricingRepository($this->pdo),
            new \Modules\Rental\Pricing\RentalPricingEngine(),
            new JournalService(new JournalRepository($this->pdo))
        ))->saveAssetPricing($assetId, 'per_night', 8000, null, null);

        $controller = $this->controllerWithPricing();

        $this->assertStringNotContainsString('Tarif manquant', $this->listPage($controller));
        $this->assertStringNotContainsString('encore aucun tarif', $this->assetPage($assetId, $controller));
    }

    /**
     * @param array<string, string> $body
     */
    private function postCreate(array $body): \Core\Http\Response
    {
        $body['_csrf_token'] = CsrfGuard::generateToken();
        $body['quantity'] ??= '1';
        $_POST = $body;

        return $this->controller->create(new Request('POST', '/admin/locations/create', [], $body, [], []), []);
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

    /**
     * Each manager who cannot be told about a request is flagged with the
     * reason, and the page says the Staff d'U gets them while nobody can
     * (#708, IT-05) — where a unit corrects it.
     */
    public function testAManagerWhoCannotBeToldIsFlaggedOnTheManagersList(): void
    {
        $assetId = $this->createAsset();
        $manager = $this->createMember('D-MGR', ['first' => 'Marie', 'last' => 'Dupont', 'totem' => null, 'birth' => self::yearsAgo(41)]);
        $this->managerRepository->grant($assetId, $manager, false);

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
            recipientResolver: new \Modules\Rental\Service\ManagerRecipientResolver(
                $this->managerRepository,
                new \Core\Import\MemberYearRepository($this->pdo),
                new \Core\Security\UserAccountRepository($this->pdo, $this->encryption),
                new JournalService(new JournalRepository($this->pdo))
            )
        );

        $body = $this->assetPage($assetId, $controller);

        $this->assertStringContainsString(
            "Ne peut pas être prévenu des demandes : n'a pas de compte sur le site",
            html_entity_decode((string) preg_replace('/\s+/', ' ', $body), ENT_QUOTES)
        );
        $this->assertStringContainsString('data-managers-unreachable', $body);
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

    // ── The park as a list, an asset as a page (issue #748) ─────────────

    private function listPage(?RentalConfigController $controller = null): string
    {
        return (string) ($controller ?? $this->controller)
            ->index(new Request('GET', '/admin/locations', [], [], [], []), [])
            ->getBody();
    }

    private function assetPage(int $assetId, ?RentalConfigController $controller = null): string
    {
        return (string) ($controller ?? $this->controller)
            ->show(new Request('GET', '/admin/locations/' . $assetId, [], [], [], []), ['id' => (string) $assetId])
            ->getBody();
    }

    private static function flat(string $html): string
    {
        return html_entity_decode((string) preg_replace('/\s+/', ' ', $html), ENT_QUOTES);
    }

    /**
     * With no asset yet, the page says so, keeps « Ajouter un bien » in its
     * header — the one primary action — and still shows the module's own
     * settings under the empty list.
     */
    public function testAnEmptyParkSaysSoAndStillOffersCreationAndTheModuleSettings(): void
    {
        $body = self::flat($this->listPage());

        $this->assertStringContainsString("Aucun bien n'est encore configuré.", $body);
        $this->assertMatchesRegularExpression('#<a href="/admin/locations/nouveau" class="btn btn-primary[^"]*"#', $body);
        $this->assertStringContainsString('Pour toutes les locations', $body);
        $this->assertStringContainsString('Courrier entrant', $body);
    }

    /**
     * One asset is still a list of one line — no picker, no page that
     * quietly becomes that asset's page, no `?asset_id=`.
     */
    public function testASingleAssetIsAListOfOneLineLeadingToItsOwnPage(): void
    {
        $assetId = $this->createAsset('Local Saint-Georges', 'local-saint-georges');

        $body = $this->listPage();

        $this->assertSame(1, substr_count($body, 'data-asset-row='));
        $this->assertStringContainsString('href="/admin/locations/' . $assetId . '"', $body);
        $this->assertStringNotContainsString('rental-asset-picker', $body);
        $this->assertStringNotContainsString('asset_id=', $body);
        // The asset's sections are on its own page, not here.
        $this->assertStringNotContainsString('id="gestionnaires"', $body);
        // Nor a creation form: « Ajouter un bien » leads to its own page.
        $this->assertStringNotContainsString('action="/admin/locations/create"', $body);
    }

    /**
     * Several assets: each its own line, the archived ones apart and badged,
     * each line offering the life-cycle step it can take — and none of them
     * offering a deletion, which stays on the asset's own page.
     */
    public function testEveryAssetHasALineAndTheArchivedOnesStandApart(): void
    {
        $hall = $this->createAsset('Local', 'local');
        $tents = $this->createAsset('Tentes', 'tentes');
        $old = $this->createAsset('Ancien local', 'ancien-local');
        $this->assetRepository->setArchived($old, true);

        $body = self::flat($this->listPage());

        $this->assertSame(3, substr_count($body, 'data-asset-row='));
        $active = substr($body, (int) strpos($body, 'data-active-assets'), (int) strpos($body, 'data-archived-assets') - (int) strpos($body, 'data-active-assets'));
        $archived = substr($body, (int) strpos($body, 'data-archived-assets'));
        foreach ([$hall, $tents] as $id) {
            $this->assertStringContainsString('data-asset-row="' . $id . '"', $active);
        }
        $this->assertStringContainsString('data-asset-row="' . $old . '"', $archived);
        $this->assertStringContainsString('Archivé', $archived);
        $this->assertSame(2, substr_count($active, 'action="/admin/locations/archive"'));
        $this->assertSame(1, substr_count($archived, 'action="/admin/locations/restore"'));
        $this->assertStringNotContainsString('action="/admin/locations/delete"', $body);
    }

    public function testTheCreationPageHasItsFormAndOnePrimaryAction(): void
    {
        $body = (string) $this->controller
            ->newAsset(new Request('GET', '/admin/locations/nouveau', [], [], [], []), [])
            ->getBody();

        $this->assertStringContainsString('action="/admin/locations/create"', $body);
        $main = substr($body, (int) strpos($body, '<main'), (int) strpos($body, '</main>') - (int) strpos($body, '<main'));
        $this->assertSame(1, preg_match_all('/class="btn btn-primary/', $main));
        $this->assertStringContainsString('Créer le bien', $main);
    }

    /** After creating, the new asset's own page — not the list with a selection. */
    public function testCreatingAnAssetLeadsToItsOwnPage(): void
    {
        $response = $this->postCreate(['name' => 'Remorque', 'asset_type' => 'Local']);

        $stmt = $this->pdo->prepare('SELECT id FROM rental_assets WHERE name = ?');
        $stmt->execute(['Remorque']);
        $this->assertSame('/admin/locations/' . (int) $stmt->fetchColumn(), $response->getHeaders()['Location'] ?? null);
    }

    /** A refused creation comes back to the creation page, where the form is. */
    public function testARefusedCreationComesBackToTheCreationPage(): void
    {
        $response = $this->postCreate(['name' => '   ', 'asset_type' => 'Local']);

        $this->assertSame('/admin/locations/nouveau', $response->getHeaders()['Location'] ?? null);
    }

    /**
     * Archiving from the list comes back to the list; from the asset's page,
     * to that page. `from` is a fixed word, never a URL.
     */
    public function testALifeCycleStepComesBackWhereItWasTaken(): void
    {
        $assetId = $this->createAsset();

        $fromList = $this->postLifecycle('archive', $assetId, 'list');
        $this->assertSame('/admin/locations', $fromList->getHeaders()['Location'] ?? null);
        $this->assertTrue($this->assetRepository->findById($assetId)?->isArchived);

        $restored = $this->postLifecycle('restore', $assetId, 'list');
        $this->assertSame('/admin/locations', $restored->getHeaders()['Location'] ?? null);
        $this->assertFalse($this->assetRepository->findById($assetId)?->isArchived);

        $fromPage = $this->postLifecycle('archive', $assetId, null);
        $this->assertSame('/admin/locations/' . $assetId, $fromPage->getHeaders()['Location'] ?? null);

        $forged = $this->postLifecycle('restore', $assetId, 'https://evil.example');
        $this->assertSame('/admin/locations/' . $assetId, $forged->getHeaders()['Location'] ?? null);
    }

    /**
     * The asset's page carries what belongs to it in the park — général,
     * gestionnaires, compte, cycle de vie with deletion apart — and points
     * to its managed space for what does not.
     */
    public function testTheAssetPageHoldsItsFourSections(): void
    {
        $assetId = $this->createAsset('Local Saint-Georges', 'local-saint-georges');

        $body = self::flat($this->assetPage($assetId));

        foreach (['id="general"', 'id="gestionnaires"', 'id="compte"', 'id="cycle-de-vie"', 'id="suppression"'] as $section) {
            $this->assertStringContainsString($section, $body, $section);
        }
        $this->assertStringContainsString('<title>Local Saint-Georges — Biens à louer', $body);
        $this->assertStringContainsString('href="/mes-locations/local-saint-georges/reglages"', $body);
        // Without Finance, the section says so rather than offering nothing.
        $this->assertStringContainsString("Le module « Finances » n'est pas actif sur ce site.", $body);
        $this->assertStringNotContainsString('action="/admin/locations/compte"', $body);
    }

    public function testAnUnknownAssetIsNotFound(): void
    {
        $response = $this->controller->show(new Request('GET', '/admin/locations/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
        // The site's own 404 page, in French, not a bare "Not Found".
        $this->assertStringContainsString('Page non trouvée', $response->getBody());
    }

    /** With Finance, the account is named on the page and picked in its dialog. */
    public function testWithFinanceTheAssetPageOffersItsAccount(): void
    {
        $assetId = $this->createAsset();
        $accounts = $this->createStub(\Modules\Finance\Api\FinanceAccountInterface::class);
        $accounts->method('getConfiguredAccounts')->willReturn([
            ['id' => 3, 'name' => 'Compte locations', 'iban' => 'BE68539007547034', 'holder_name' => null, 'section_id' => null],
        ]);
        $journal = new JournalService(new JournalRepository($this->pdo));
        $controller = new RentalConfigController(
            $this->twig,
            $this->assetRepository,
            new RentalAssetService($this->assetRepository, new RentalSlugGenerator($this->assetRepository), $journal),
            $this->managerService,
            new ScoutYearService($this->pdo),
            $this->settingService,
            new \Modules\Rental\Service\RentalPaymentService(
                new \Modules\Rental\Repository\RentalPaymentRepository($this->pdo, $this->encryption),
                RentalTestHelper::bookingAudit($this->pdo, $this->encryption),
                $journal,
                $this->createStub(\Modules\Finance\Api\ExpectedReceivableInterface::class),
                $this->createStub(\Modules\Finance\Api\StructuredCommunicationInterface::class),
                null,
                $accounts
            )
        );

        $body = $this->assetPage($assetId, $controller);

        $this->assertStringContainsString('action="/admin/locations/compte"', $body);
        $this->assertStringContainsString('Compte locations', $body);
    }

    /**
     * « Courrier entrant » lists the boxes in this module's scope and no
     * others, asked of `inbound_mail` by consumer — never every box on the
     * site, which would read as « Locations receives this ».
     */
    public function testInboundMailListsOnlyTheBoxesInTheRentalScope(): void
    {
        $inbound = new class implements \Modules\InboundMail\Api\InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            /** @var list<string> */
            public array $askedFor = [];

            public function listMailboxSummaries(): array
            {
                return [9 => ['name' => 'Camps', 'state' => 'OK', 'is_enabled' => true]];
            }

            public function listMailboxSummariesFor(string $consumerId): array
            {
                $this->askedFor[] = $consumerId;

                return [
                    4 => ['name' => 'Locations', 'state' => 'OK', 'is_enabled' => true],
                    5 => ['name' => 'Unité partagée', 'state' => 'OK', 'is_enabled' => false],
                ];
            }
        };

        $body = self::flat(strip_tags($this->listPage($this->controllerWithInbound($inbound))));

        $this->assertSame(['rental'], $inbound->askedFor);
        $this->assertStringContainsString('Locations — OK', $body);
        $this->assertStringContainsString('Unité partagée — OK, désactivée', $body);
        $this->assertStringNotContainsString('Camps', $body);
    }

    /**
     * No box in this module's scope is not « no box on the site »: the
     * sentence says which one it is.
     */
    public function testNoBoxInTheRentalScopeIsSaidAsSuch(): void
    {
        $inbound = new class implements \Modules\InboundMail\Api\InboundMailInterface {
            use \Tests\Modules\InboundMail\InertInboundMail;

            public function listMailboxSummaries(): array
            {
                return [9 => ['name' => 'Camps', 'state' => 'OK', 'is_enabled' => true]];
            }
        };

        $body = self::flat($this->listPage($this->controllerWithInbound($inbound)));

        $this->assertStringContainsString("Aucune boîte n'est reliée au module Locations.", $body);
        $this->assertStringNotContainsString('Camps', $body);
    }

    /** Without the module, the page says Locations works without it. */
    public function testWithoutInboundMailThePageSaysLocationsWorksWithoutIt(): void
    {
        $this->assertStringContainsString(
            "Le module « Courrier entrant » n'est pas actif sur ce site.",
            self::flat($this->listPage())
        );
    }

    private function controllerWithInbound(\Modules\InboundMail\Api\InboundMailInterface $inbound): RentalConfigController
    {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new RentalConfigController(
            $this->twig,
            $this->assetRepository,
            new RentalAssetService($this->assetRepository, new RentalSlugGenerator($this->assetRepository), $journal),
            $this->managerService,
            new ScoutYearService($this->pdo),
            $this->settingService,
            inboundMail: $inbound
        );
    }

    private function postLifecycle(string $action, int $assetId, ?string $from): \Core\Http\Response
    {
        $body = ['asset_id' => (string) $assetId, '_csrf_token' => CsrfGuard::generateToken()];
        if ($from !== null) {
            $body['from'] = $from;
        }
        $_POST = $body;

        $request = new Request('POST', '/admin/locations/' . $action, [], $body, [], []);

        return $action === 'archive'
            ? $this->controller->archive($request, [])
            : $this->controller->restore($request, []);
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
        $this->assertSame('/admin/locations/' . $assetId, $response->getHeaders()['Location'] ?? null);

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
}
