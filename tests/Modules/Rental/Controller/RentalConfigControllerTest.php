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
}
