<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Controller;

use Core\Config\AppConfig;
use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Http\FrontController;
use Core\Http\Request;
use Core\Http\Router;
use Core\Import\FeeCategoryRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\AddressNormalizer;
use Core\Member\Household\HouseholdRepository;
use Core\Member\Household\HouseholdService;
use Core\Member\HouseholdFeeCategory;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\AuthSession;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\TwigFactory;
use Modules\Fees\Controller\FeeAccuracyController;
use Modules\Fees\Repository\FeesImportRepository;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Repository\IgnoredHouseholdRepository;
use Modules\Fees\Service\FeeAccuracyService;
use Modules\Fees\Service\FederalScaleLookupService;
use Modules\Fees\Service\HouseholdTariffService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;
use Tests\Modules\Fees\Support\FakeLlmConnector;
use Tests\Modules\Fees\Support\StubbedFederalScaleLookupService;
use Twig\Environment;

/**
 * The five routes of « Justesse des tarifs », and their RBAC boundary:
 * allowed at `admin` (the Espace chefs d'U floor), refused one level below
 * at `chief` — reads, writes and the export alike.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class FeeAccuracyControllerTest extends TestCase
{
    private \PDO $pdo;
    private Environment $twig;
    private FeeAccuracyController $controller;
    private EncryptionService $encryption;
    private HouseholdService $households;
    private IgnoredHouseholdRepository $ignored;
    private HouseholdTariffService $tariffs;
    private SettingService $settingService;
    private JournalService $journalService;
    private int $scoutYearId;
    private int $normalFeeId;
    private int $familyFeeId;
    private FeeCategoryRepository $feeCategories;
    private ScoutYearResolver $scoutYearResolver;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        FeesTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService = $settingService;
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearId = $scoutYearService->ensureYear('2025-2026');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->scoutYearId);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalFeeId = $feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->familyFeeId = $feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');

        $this->households = new HouseholdService(new HouseholdRepository($this->pdo), $this->encryption);
        $this->ignored = new IgnoredHouseholdRepository($this->pdo, $this->encryption);
        $this->tariffs = new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $feeCategories);
        $this->journalService = new JournalService(new JournalRepository($this->pdo));

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/fees/views';
        $twig = TwigFactory::create($templateDir, false, ['fees' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/admin/fees/tarifs');
        $twig->addGlobal('csp_nonce', 'test-nonce');
        $this->twig = $twig;

        $this->feeCategories = $feeCategories;
        $this->scoutYearResolver = $scoutYearResolver;

        // The default: no llm_connector at all, which is what most
        // installations look like and what every pre-existing assertion
        // below was written against.
        $this->useLookupService(new FederalScaleLookupService(null, $settingService, $this->journalService));

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }

    protected function tearDown(): void
    {
        AuthSession::logout();
        $_SESSION = [];
    }

    /** Rebuild the controller around a given lookup service. */
    private function useLookupService(FederalScaleLookupService $lookup): void
    {
        $this->controller = new FeeAccuracyController(
            $this->twig,
            new FeeAccuracyService(
                $this->households,
                new HouseholdDetailRepository($this->pdo, $this->encryption),
                $this->tariffs,
                $this->ignored,
                $this->feeCategories
            ),
            $this->tariffs,
            $this->ignored,
            $this->households,
            new FeesImportRepository($this->pdo),
            $this->feeCategories,
            $this->scoutYearResolver,
            $this->journalService,
            $lookup
        );
    }

    private function createMember(string $firstName, ?int $feeCategoryId): void
    {
        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('" . uniqid('', true) . "')");
        $memberId = (int) $this->pdo->lastInsertId();

        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, fee_category_id)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberId,
            $this->scoutYearId,
            $this->encryption->encrypt($firstName, 'member_years.first_name'),
            $this->encryption->encrypt('Dupont', 'member_years.last_name'),
            $feeCategoryId,
        ]);
        $memberYearId = (int) $this->pdo->lastInsertId();

        $normalized = AddressNormalizer::normalize('Rue de la Station', '5', null, '1000');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_addresses (member_year_id, address_type, street_encrypted, number_encrypted,
                                           postal_code_encrypted, city_encrypted, address_normalized_blind_index)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $memberYearId,
            'Domicile',
            $this->encryption->encrypt('Rue de la Station', 'member_addresses.street'),
            $this->encryption->encrypt('5', 'member_addresses.number'),
            $this->encryption->encrypt('1000', 'member_addresses.postal_code'),
            $this->encryption->encrypt('Bruxelles', 'member_addresses.city'),
            $this->encryption->blindIndex($normalized, 'address'),
        ]);
    }

    private function createLeavingMember(string $firstName, ?int $feeCategoryId): void
    {
        $this->createMember($firstName, $feeCategoryId);
        $this->pdo->exec(
            "UPDATE member_years SET leaving = 1, leaving_marked_at = '2026-01-06 10:00:00'
             WHERE id = (SELECT MAX(id) FROM member_years)"
        );
    }

    private function blindIndex(): string
    {
        return $this->encryption->blindIndex(
            AddressNormalizer::normalize('Rue de la Station', '5', null, '1000'),
            'address'
        );
    }

    /** @return array<string, array{string, string, string}> */
    public static function routeProvider(): array
    {
        return [
            'the screen' => ['GET', '/admin/fees/tarifs', 'index'],
            'the export' => ['GET', '/admin/fees/tarifs/export', 'export'],
            'ignoring a household' => ['POST', '/admin/fees/tarifs/ignorer', 'ignore'],
            'putting one back' => ['POST', '/admin/fees/tarifs/reprendre', 'unignore'],
            'saving the scale' => ['POST', '/admin/fees/tarifs/bareme', 'saveTariffs'],
            'looking the amounts up' => ['POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs'],
        ];
    }

    /** @dataProvider routeProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testAdminReachesEveryRoute(string $method, string $path, string $action): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch($method, $path, $action, $method === 'POST' ? $this->validBody() : []);

        $this->assertContains($response->getStatusCode(), [200, 302], (string) $response->getBody());
    }

    /** @dataProvider routeProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testOneRoleBelowIsRejectedOnEveryRoute(string $method, string $path, string $action): void
    {
        AuthSession::login(1, 'chief@test.be', 'chief');

        $response = $this->dispatch($method, $path, $action, $method === 'POST' ? $this->validBody() : []);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheScreenNamesTheCountAndTheExpectedTariff(): void
    {
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->normalFeeId);
        $this->createMember('Léa', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('membres dans Desk', $body);
        $this->assertStringContainsString('tarif attendu Famille', $body);
        $this->assertStringContainsString('Rue de la Station', $body);
    }

    public function testTheScreenStatesWhichImportItReads(): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, user_account_id, line_count, member_count, new_functions_count, imported_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$this->scoutYearId, null, 10, 8, 0, '2026-01-08 07:15:00']);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('08/01/2026 à 07:15', $body);
    }

    public function testTheUpcomingTabTellsTheReaderNotToTouchAnythingYet(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs?vue=prevoir', 'index')->getBody();

        $this->assertStringContainsString('touchez pas maintenant', $body);
    }

    /**
     * The « À prévoir » card is a different rendering path — the trigger
     * sentence, the "à la bascule" figure — and an empty tab would never
     * exercise it.
     */
    public function testTheUpcomingTabNamesWhatWillMakeAHouseholdChange(): void
    {
        $this->tariffs->save(HouseholdFeeCategory::COUPLE, null, 3500);
        $this->tariffs->save(HouseholdFeeCategory::FAMILY, null, 3000);
        $this->createMember('Jean', $this->familyFeeId);
        $this->createMember('Marie', $this->familyFeeId);
        $this->createLeavingMember('Camille', $this->familyFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs?vue=prevoir', 'index')->getBody();

        $this->assertStringContainsString('Passera à Couple', $body);
        $this->assertStringContainsString('départ annoncé le 06/01/2026', $body);
        $this->assertStringContainsString('à la bascule', $body);
    }

    public function testTheIgnoredTabShowsTheReasonAndOffersToPutTheHouseholdBack(): void
    {
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->normalFeeId);
        $household = $this->households->householdsForYear($this->scoutYearId)[$this->blindIndex()];
        $this->ignored->ignore(
            $this->scoutYearId,
            $this->blindIndex(),
            \Modules\Fees\Service\FeeAccuracyService::compositionHash($household),
            'Colocation',
            null
        );
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs?vue=ignores', 'index')->getBody();

        $this->assertStringContainsString('Colocation', $body);
        $this->assertStringContainsString('Remettre dans la vérification', $body);
    }

    public function testTheAddresslessTabSaysTheyAreNeitherCompliantNorInBreach(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs?vue=sans-adresse', 'index')->getBody();

        $this->assertStringContainsString('ni conformes ni en', $body);
    }

    public function testAnUnknownViewFallsBackToTheCorrectionTabRatherThanFailing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('GET', '/admin/fees/tarifs?vue=n-importe-quoi', 'index');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('pèse sur la', (string) $response->getBody());
    }

    public function testIgnoringAHouseholdStoresTheReasonAndKeepsItOutOfTheJournal(): void
    {
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('POST', '/admin/fees/tarifs/ignorer', 'ignore', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'address_blind_index' => $this->blindIndex(),
            'reason' => 'Garde alternée',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $stored = $this->ignored->findAllForYear($this->scoutYearId);
        $this->assertArrayHasKey($this->blindIndex(), $stored);
        $this->assertSame('Garde alternée', $stored[$this->blindIndex()]->reason);

        // SECURITY.md §11: the journal says a household was set aside, never
        // which one nor why.
        $row = $this->pdo->query("SELECT * FROM event_log WHERE event_type = 'fees_household_ignored'")->fetch(\PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertStringNotContainsString('Garde alternée', (string) $row['description'] . (string) $row['context']);
        $this->assertStringNotContainsString($this->blindIndex(), (string) $row['context']);
    }

    public function testARequestNamingAnAddressThatIsNoLongerAHouseholdChangesNothing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('POST', '/admin/fees/tarifs/ignorer', 'ignore', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'address_blind_index' => str_repeat('f', 64),
            'reason' => 'Peu importe',
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame([], $this->ignored->findAllForYear($this->scoutYearId));
    }

    public function testAnEmptyReasonIsRefused(): void
    {
        $this->createMember('Jean', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->dispatch('POST', '/admin/fees/tarifs/ignorer', 'ignore', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'address_blind_index' => $this->blindIndex(),
            'reason' => '   ',
        ]);

        $this->assertSame([], $this->ignored->findAllForYear($this->scoutYearId));
    }

    public function testPuttingAHouseholdBackRemovesTheDecision(): void
    {
        $this->createMember('Jean', $this->normalFeeId);
        $this->ignored->ignore($this->scoutYearId, $this->blindIndex(), 'whatever', 'Colocation', null);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->dispatch('POST', '/admin/fees/tarifs/reprendre', 'unignore', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'address_blind_index' => $this->blindIndex(),
        ]);

        $this->assertSame([], $this->ignored->findAllForYear($this->scoutYearId));
    }

    public function testTheScaleAcceptsACommaAndTreatsAnEmptyFieldAsNoAmount(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->dispatch('POST', '/admin/fees/tarifs/bareme', 'saveTariffs', [
            '_csrf_token' => CsrfGuard::generateToken(),
            'amount_normal' => '39,50',
            'amount_couple' => '35.00',
            'amount_family' => '',
            'fee_category_normal' => (string) $this->normalFeeId,
            'fee_category_couple' => '',
            'fee_category_family' => (string) $this->familyFeeId,
        ]);

        $this->assertSame(3950, $this->tariffs->amountCentsFor(HouseholdFeeCategory::NORMAL));
        $this->assertSame(3500, $this->tariffs->amountCentsFor(HouseholdFeeCategory::COUPLE));
        $this->assertNull($this->tariffs->amountCentsFor(HouseholdFeeCategory::FAMILY));

        $panel = $this->tariffs->panel();
        $this->assertSame($this->normalFeeId, $panel['normal']['fee_category_id']);
        $this->assertNull($panel['couple']['fee_category_id']);
        $this->assertSame($this->familyFeeId, $panel['family']['fee_category_id']);
    }

    // ------------------------------------------------------------------
    // « Chercher les montants » — the optional llm_connector dependency.
    // ------------------------------------------------------------------

    /**
     * The three ways the connector can be missing all look the same on
     * screen, which is the whole of ARCHITECTURE.md §7.5: the button is
     * absent, not disabled and not broken, and everything else about the
     * barème is untouched.
     *
     * @return array<string, array{0: ?FakeLlmConnector}>
     */
    public static function absentConnectorProvider(): array
    {
        return [
            'llm_connector not in the build, or disabled' => [null],
            'enabled but no model on the cheap tier' => [new FakeLlmConnector(tierAvailable: false)],
        ];
    }

    /** @dataProvider absentConnectorProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('absentConnectorProvider')]
    public function testTheAiButtonIsAbsentWithoutAUsableConnector(?FakeLlmConnector $connector): void
    {
        $this->useLookupService(new FederalScaleLookupService($connector, $this->settingService, $this->journalService));
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringNotContainsString('Chercher les montants', $body);
        // The barème itself is exactly what it always was.
        $this->assertStringContainsString('Enregistrer le barème', $body);
        $this->assertStringContainsString('Montant par personne', $body);
    }

    public function testTheAiButtonAppearsOnlyWithACheapTierModel(): void
    {
        $this->useLookupService(new FederalScaleLookupService(
            new FakeLlmConnector(tierAvailable: true),
            $this->settingService,
            $this->journalService
        ));
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('Chercher les montants', $body);
        $this->assertStringContainsString('/admin/fees/tarifs/bareme/chercher', $body);
    }

    /**
     * The route answers the same way whether it was reached from a stale
     * page or by hand — visibility is a convenience, never a boundary
     * (SECURITY.md §3).
     */
    public function testTheLookupRouteRefusesWhenNoConnectorIsConfigured(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', [
            '_csrf_token' => CsrfGuard::generateToken(),
        ]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->tariffs->amountCentsFor(HouseholdFeeCategory::NORMAL));
    }

    /**
     * The heart of the feature: a successful lookup PRE-FILLS and saves
     * nothing. The three fields come back filled, the block names the page
     * and the year the figures rest on, and `fees_household_tariffs` is
     * still empty until somebody clicks « Enregistrer le barème ».
     */
    public function testASuccessfulLookupPreFillsTheFieldsAndWritesNothing(): void
    {
        $this->useLookupService($this->lookupAnswering(
            '{"annee": "2025-2026", "normale": "57,50", "couple": "46", "familiale": "39"}'
        ));
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', [
            '_csrf_token' => CsrfGuard::generateToken(),
        ]);
        $this->assertSame(302, $response->getStatusCode());

        // Nothing reached the database from the model's answer.
        $this->assertNull($this->tariffs->amountCentsFor(HouseholdFeeCategory::NORMAL));
        $this->assertNull($this->tariffs->amountCentsFor(HouseholdFeeCategory::COUPLE));
        $this->assertNull($this->tariffs->amountCentsFor(HouseholdFeeCategory::FAMILY));
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM fees_household_tariffs')->fetchColumn()
        );

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('value="57,50"', $body);
        $this->assertStringContainsString('value="46,00"', $body);
        $this->assertStringContainsString('value="39,00"', $body);
        // Without these two a treasurer validates a number without knowing
        // what it rests on.
        $this->assertStringContainsString('2025-2026', $body);
        $this->assertStringContainsString(FederalScaleLookupService::DEFAULT_URL, $body);
    }

    /** The suggestion survives exactly one render, then it is gone. */
    public function testThePreFillIsConsumedByTheFirstRenderOnly(): void
    {
        $this->useLookupService($this->lookupAnswering(
            '{"annee": "2025-2026", "normale": "57,50", "couple": "46", "familiale": "39"}'
        ));
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', [
            '_csrf_token' => CsrfGuard::generateToken(),
        ]);

        $this->assertStringContainsString('value="57,50"', (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody());
        $this->assertStringNotContainsString('value="57,50"', (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody());
    }

    /**
     * The federal page carries two seasons at once. A page answering for
     * the other one pre-fills nothing at all — three plausible numbers
     * from the wrong year are worse than an empty form.
     */
    public function testAnAnswerForAnotherScoutYearPreFillsNothing(): void
    {
        $this->useLookupService($this->lookupAnswering(
            '{"annee": "2026-2027", "normale": "57,50", "couple": "46", "familiale": "39"}'
        ));
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', [
            '_csrf_token' => CsrfGuard::generateToken(),
        ]);

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringNotContainsString('value="57,50"', $body);
        $this->assertStringContainsString('2026-2027', $body);
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM fees_household_tariffs')->fetchColumn()
        );
    }

    private function lookupAnswering(string $content): FederalScaleLookupService
    {
        return new StubbedFederalScaleLookupService(
            new FakeLlmConnector(tierAvailable: true, content: $content),
            $this->settingService,
            $this->journalService,
            '<html><body><h2>COTISATIONS</h2></body></html>'
        );
    }

    public function testTheExportIsASpreadsheetNamedAfterTheScreen(): void
    {
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->normalFeeId);
        $this->createMember('Léa', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $response = $this->dispatch('GET', '/admin/fees/tarifs/export', 'export');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('spreadsheetml', (string) $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString('justesse-des-tarifs.xlsx', (string) $response->getHeaders()['Content-Disposition']);
        // A real XLSX is a ZIP.
        $this->assertStringStartsWith('PK', (string) $response->getBody());
    }

    /** @return array<string, string> */
    private function validBody(): array
    {
        return [
            '_csrf_token' => CsrfGuard::generateToken(),
            'address_blind_index' => $this->blindIndex(),
            'reason' => 'Motif',
        ];
    }

    /** @param array<string, string> $body */
    private function dispatch(string $method, string $path, string $action, array $body = []): \Core\Http\Response
    {
        $routePath = strtok($path, '?');
        $query = [];
        $questionMark = strpos($path, '?');
        if ($questionMark !== false) {
            parse_str(substr($path, $questionMark + 1), $query);
        }

        $router = new Router();
        $router->addRoute($method, (string) $routePath, FeeAccuracyController::class, $action, 'admin');

        $configFile = sys_get_temp_dir() . '/test_fees_accuracy_config_' . uniqid() . '.php';
        file_put_contents($configFile, "<?php\nreturn ['site_name' => 'Test', 'debug' => false];");
        $config = new AppConfig($configFile);

        $fc = new FrontController($router, $this->twig, $config);
        $fc->registerController(FeeAccuracyController::class, $this->controller);

        return $fc->handle(new Request($method, (string) $routePath, $query, $body, [], []));
    }
}
