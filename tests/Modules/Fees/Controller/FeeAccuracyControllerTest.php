<?php

declare(strict_types=1);

namespace Tests\Modules\Fees\Controller;

use Core\Http\FlashMessage;
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
use Tests\TestTwig;
use Modules\Fees\FeeTariff;
use Modules\Fees\Support\SuggestedScale;
use Modules\Fees\Value\FederalScaleLookup;
use Modules\Fees\Controller\FeeAccuracyController;
use Modules\Fees\Repository\FeesImportRepository;
use Modules\Fees\Repository\HouseholdDetailRepository;
use Modules\Fees\Repository\HouseholdTariffRepository;
use Modules\Fees\Repository\IgnoredHouseholdRepository;
use Modules\Fees\Service\FeeAccuracyService;
use Modules\Fees\Service\FederalScaleLookupService;
use Modules\Fees\Service\HouseholdTariffService;
use Modules\Fees\Service\ShippedScaleService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Fees\FeesTestHelper;
use Tests\Modules\Fees\Support\FakeLlmConnector;
use Tests\Modules\Fees\Support\StubbedFederalScaleLookupService;
use Twig\Environment;

/**
 * The routes of « Justesse des tarifs », and their RBAC boundary:
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
        $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 4) . '/modules/fees/module.json'), true);
        foreach ($manifest['settings'] as $setting) {
            $settingService->register(
                $setting['key'], $setting['default_value'], $setting['type'], $setting['label'],
                $setting['description'], 'fees', null, null, $setting['editable'] ?? true
            );
        }
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        $scoutYearService = new ScoutYearService($this->pdo);
        $this->scoutYearId = $scoutYearService->ensureYear('2025-2026');
        $settingService->setInternal('fees_scale_year', '2025-2026', 'fees');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->scoutYearId);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $feeCategories = new FeeCategoryRepository($this->pdo);
        $this->normalFeeId = $feeCategories->create('N_N_COTISATION NORMALE', 'Cotisation normale');
        $this->familyFeeId = $feeCategories->create('N_F_COTISATION FAMILLE', 'Cotisation famille');

        $this->households = new HouseholdService(new HouseholdRepository($this->pdo, $this->encryption));
        $this->ignored = new IgnoredHouseholdRepository($this->pdo, $this->encryption);
        $this->tariffs = new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $feeCategories);
        $this->journalService = new JournalService(new JournalRepository($this->pdo));

        $twig = TestTwig::create(['fees']);
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
                $this->feeCategories,
                new \Core\Import\RosterSnapshotRepository($this->pdo),
                new \Modules\Fees\Service\TariffConfigurationService(
                    $this->tariffs, $this->feeCategories, $this->settingService
                ),
                new ScoutYearService($this->pdo)
            ),
            $this->tariffs,
            $this->ignored,
            $this->households,
            new FeesImportRepository($this->pdo),
            $this->feeCategories,
            $this->scoutYearResolver,
            $this->journalService,
            $lookup,
            new ShippedScaleService($this->tariffs),
            new \Modules\Fees\Service\TariffConfigurationService(
                $this->tariffs, $this->feeCategories, $this->settingService
            )
        );
    }

    private function createMember(string $firstName, ?int $feeCategoryId, ?string $startDate = '2025-09-01'): int
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
        $this->pdo->exec(
            "INSERT OR IGNORE INTO functions (id, desk_code, label, role) VALUES (777, 'ZX777_FN', "
            . "'Fonction test', 'identified')"
        );
        $this->pdo->prepare('INSERT INTO member_functions (member_year_id, function_id, start_date) VALUES (?, 777, ?)')
            ->execute([$memberYearId, $startDate]);

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
        return $memberYearId;
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
            'applying a proposal' => ['POST', '/admin/fees/tarifs/bareme/appliquer', 'applyTariffs'],
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

    /**
     * The refusal AND what it protects. The body carries a valid CSRF
     * token and a real blind index, so nothing but the guard stands
     * between a chief and the row `ignore` would insert or `unignore`
     * would remove — and a 403 rendered after the write would read
     * exactly like this one (issue #387).
     *
     * @dataProvider routeProvider
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('routeProvider')]
    public function testOneRoleBelowIsRejectedOnEveryRoute(string $method, string $path, string $action): void
    {
        // `ignore` turns back before writing when the blind index names no
        // household of this year, so without a member here that row would
        // assert the snapshot against a route that could not have written
        // anyway. `unignore` needs nothing: it logs the event whether or
        // not `forget()` found a row.
        if ($action === 'ignore') {
            $this->createMember('Jean', $this->normalFeeId);
        }

        AuthSession::login(1, 'chief@test.be', 'chief');
        $before = DatabaseTestHelper::snapshot($this->pdo);

        $response = $this->dispatch($method, $path, $action, $method === 'POST' ? $this->validBody() : []);

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame(
            $before,
            DatabaseTestHelper::snapshot($this->pdo),
            "{$method} {$path} changed the database before refusing a chief"
        );
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

    // ------------------------------------------------------------------
    // The household card (#1326): readable enough to find the member in
    // Desk and correct the category — nothing about the calculation moves.
    // ------------------------------------------------------------------

    /** A household of three on « normale » where « famille » is expected. */
    private function aHouseholdToCorrect(): string
    {
        $this->tariffs->save(HouseholdFeeCategory::NORMAL, null, 2000);
        $this->tariffs->save(HouseholdFeeCategory::FAMILY, null, 3000);
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->normalFeeId);
        $this->createMember('Léa', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        return (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
    }

    /**
     * @return array<string, array{string, string}> the tab, and what ONLY a household card of that tab says
     */
    public static function tabProvider(): array
    {
        return [
            'à corriger' => ['corriger', 'Ignorer ce foyer'],
            'à prévoir' => ['prevoir', 'Ignorer ce foyer'],
            'ignorés' => ['ignores', 'Remettre dans la vérification'],
        ];
    }

    /** @dataProvider tabProvider */
    #[\PHPUnit\Framework\Attributes\DataProvider('tabProvider')]
    public function testThereIsNoMoreCopyForDeskAnywhere(string $view, string $cardMarker): void
    {
        // A household that lands in each tab: wrong now, about to change
        // (a departure), and set aside — so every view draws a real card.
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->normalFeeId);
        $this->createLeavingMember('Camille', $this->normalFeeId);
        if ($view === 'ignores') {
            $household = $this->households->householdsForYear($this->scoutYearId)[$this->blindIndex()];
            $this->ignored->ignore(
                $this->scoutYearId,
                $this->blindIndex(),
                \Modules\Fees\Service\FeeAccuracyService::compositionHash($household),
                'Colocation',
                null
            );
        }
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs?vue=' . $view, 'index')->getBody();

        $this->assertStringContainsString($cardMarker, $body, 'a household card must be on screen for this to prove anything');
        $this->assertStringNotContainsString('Copier pour Desk', $body);
        $this->assertStringNotContainsString('data-fees-copy', $body);
        $this->assertStringNotContainsString('fees-clipboard-data', $body);
        $this->assertStringNotContainsString('fees-copy.js', $body);
    }

    public function testTheOtherActionsOfTheCardAreKept(): void
    {
        $body = $this->aHouseholdToCorrect();

        $this->assertStringContainsString('Ignorer ce foyer', $body);
    }

    public function testEveryMemberNameLinksToTheirStaffRecord(): void
    {
        $body = $this->aHouseholdToCorrect();

        $ids = $this->pdo->query('SELECT id FROM member_years ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN);
        $this->assertCount(3, $ids);
        foreach ($ids as $id) {
            $this->assertStringContainsString('href="/admin/members/' . $id . '"', $body);
        }
    }

    public function testTheNamesOfTheIgnoredTabLinkToo(): void
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

        $id = (int) $this->pdo->query('SELECT MIN(id) FROM member_years')->fetchColumn();
        $this->assertStringContainsString('href="/admin/members/' . $id . '"', $body);
    }

    public function testNothingIsEverStruckThrough(): void
    {
        // The three states on one screen: wrong, right and outside the tariffs.
        $this->feeCategories->create('L_L_COTISATION IAMA', 'Cotisation iAMA');
        $iamaId = (int) $this->pdo->query("SELECT id FROM fee_categories WHERE desk_code = 'L_L_COTISATION IAMA'")->fetchColumn();
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->familyFeeId);
        $this->createMember('Léa', $iamaId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('N_N_COTISATION NORMALE', $body, 'the wrong tariff must stay readable');
        $this->assertStringNotContainsString('<s>', $body);
        $this->assertStringNotContainsString('<del', $body);
        $this->assertStringNotContainsString('<strike', $body);
        $this->assertStringNotContainsString('line-through', $body);
        $this->assertStringNotContainsString('text-decoration-line-through', $body);
    }

    public function testTheDifferenceIsInTheNormalTextColourWhateverItsSign(): void
    {
        $body = $this->aHouseholdToCorrect();

        // 3 × (3000 − 2000) = +30,00 €.
        $this->assertStringContainsString('à déclarer en plus', $body);
        $this->assertMatchesRegularExpression('/<div class="fw-semibold">\s*\+?30,00/u', $body);
        // The only red left on the page is the required-field asterisk of
        // the « Ignorer » form: never the amount.
        $this->assertDoesNotMatchRegularExpression('/text-(danger|body-secondary)[^>]*>\s*[−+-]?\s*\d+,\d{2}/u', $body);
    }

    public function testANegativeDifferenceKeepsItsSignAndItsSentence(): void
    {
        $this->tariffs->save(HouseholdFeeCategory::NORMAL, null, 2000);
        $this->tariffs->save(HouseholdFeeCategory::FAMILY, null, 3000);
        $this->tariffs->save(HouseholdFeeCategory::COUPLE, null, 2500);
        // Three on « famille » where « normal » would be expected is not
        // possible; two on « famille » where « couple » is expected is.
        $this->createMember('Jean', $this->familyFeeId);
        $this->createMember('Marie', $this->familyFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('déclaré en trop', $body);
        $this->assertMatchesRegularExpression('/<div class="fw-semibold">\s*[−-]\s*\d/u', $body);
        $this->assertDoesNotMatchRegularExpression('/text-(danger|body-secondary)[^>]*>\s*[−+-]?\s*\d+,\d{2}/u', $body);
    }

    public function testTheTwoColumnsSpeakTheSameLanguageTheDeskCodes(): void
    {
        $body = $this->aHouseholdToCorrect();

        $this->assertStringContainsString('N_N_COTISATION NORMALE', $body, 'encoded');
        $this->assertStringContainsString('N_F_COTISATION FAMILLE', $body, 'expected');
        $this->assertStringContainsString('À corriger', $body);
    }

    public function testAMemberAlreadyRightShowsItsCodeAndSaysSo(): void
    {
        $this->createMember('Jean', $this->familyFeeId);
        $this->createMember('Marie', $this->familyFeeId);
        $this->createMember('Léa', $this->familyFeeId);
        $this->createMember('Paul', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('Conforme', $body);
        $this->assertStringContainsString('À corriger', $body);
    }

    public function testAnIamaMemberKeepsItsDeskTariffAndCountsForTheOtherMember(): void
    {
        $this->feeCategories->create('L_L_COTISATION IAMA', 'Cotisation iAMA');
        $iamaId = (int) $this->pdo->query("SELECT id FROM fee_categories WHERE desk_code = 'L_L_COTISATION IAMA'")->fetchColumn();
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $iamaId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('L_L_COTISATION IAMA', $body);
        $this->assertStringContainsString('Tarif IAmA saisi dans Desk', $body);
    }

    /**
     * Two people, so « couple » is expected, and Desk offers no category
     * the site recognises as one: no code is made up and the row is not
     * presented as fine.
     */
    public function testNoCodeIsInventedWhenTheExpectedTariffHasNoDeskCategory(): void
    {
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString("Aucun code Desk n'est associé à Couple", $body);
        $this->assertStringNotContainsString('Conforme', $body);
        $this->assertStringContainsString('À corriger', $body);
    }

    public function testAnExplicitMappingIsWhatTheExpectedColumnShows(): void
    {
        $couple = $this->feeCategories->create('N_C_COTISATION COUPLE', 'Cotisation couple');
        $special = $this->feeCategories->create('X_COUPLE SPÉCIAL', 'Couple spécial');
        $this->tariffs->save(HouseholdFeeCategory::COUPLE, $special, null);
        $this->createMember('Jean', $this->normalFeeId);
        $this->createMember('Marie', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('X_COUPLE SPÉCIAL', $body);
        $this->assertStringNotContainsString('N_C_COTISATION COUPLE', $body, 'the guess it replaced');
        $this->assertGreaterThan(0, $couple);
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

        $this->assertStringContainsString('anticipez pas le mouvement', $body);
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

        $this->dispatch('POST', '/admin/fees/tarifs/bareme', 'saveTariffs', array_replace($this->validBody(), [
            'amount_normal' => '39,50',
            'amount_couple' => '35.00',
            'amount_family' => '',
            'fee_category_normal' => (string) $this->normalFeeId,
            'fee_category_couple' => '',
            'fee_category_family' => (string) $this->familyFeeId,
        ]));

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

        $response = $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', $this->validBody());

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->tariffs->amountCentsFor(HouseholdFeeCategory::NORMAL));
    }

    public function testLookupOffersValuesSeparatelyAndPreservesTheDraftAcrossRenders(): void
    {
        $this->useLookupService($this->lookupAnswering(
            '{"annee":"2025-2026","normale":"57,50","iama":"25","invites":"18,25",'
            . '"debut_invites":"01/06","fin_invites":"31/08"}'
        ));
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->tariffs->save(HouseholdFeeCategory::COUPLE, null, 4600);
        $this->tariffs->save(FeeTariff::GUEST, null, 1825);
        $before = $this->storedScaleState();
        $posted = array_replace($this->validBody(), [
            'amount_normal' => '61,11', 'amount_couple' => '', 'amount_guest' => '0',
            'guest_from' => '02/06', 'guest_until' => '15/09',
            'fee_category_normal' => (string) $this->normalFeeId,
        ]);
        $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', $posted);

        for ($visit = 0; $visit < 2; $visit++) {
            $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
            $this->assertSame('61,11', $this->fieldValue($body, 'amount_normal'));
            $this->assertSame('', $this->fieldValue($body, 'amount_couple'));
            $this->assertSame('0', $this->fieldValue($body, 'amount_guest'));
            $this->assertSame('15/09', $this->fieldValue($body, 'guest_until'));
            $this->assertStringContainsString('57,50 €', $body);
            $this->assertStringContainsString('À compléter : Couple, Familiale', $body);
            $this->assertStringContainsString(FederalScaleLookupService::DEFAULT_URL, $body);
            $this->assertSame($before, $this->storedScaleState());
        }
        $this->assertNotNull(SuggestedScale::peek('2025-2026'));
    }

    /** @return array<string, array{string, string, string}> */
    public static function applicationModes(): array
    {
        return ['amounts only' => ['amounts', '02/06', '15/09'], 'with period' => ['all', '01/06', '31/08']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('applicationModes')]
    public function testApplyingUsesCurrentFieldsAndDoesNotSave(string $mode, string $from, string $until): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->tariffs->save(HouseholdFeeCategory::FAMILY, null, 3900);
        $before = DatabaseTestHelper::snapshot($this->pdo);
        SuggestedScale::rememberDraft('2025-2026', array_replace($this->validBody(), [
            'amount_couple' => '45', 'amount_family' => '39,00', 'amount_guest' => '18,25',
        ]));
        SuggestedScale::set(FederalScaleLookup::found(
            FederalScaleLookupService::DEFAULT_URL, '2025-2026', ['normal' => 5750, 'iama' => 2500], '01/06', '31/08'
        ));
        $token = SuggestedScale::peek('2025-2026')['token'];
        $posted = array_replace($this->validBody(), [
            'proposal_token' => $token, 'apply_scale' => $mode,
            'amount_couple' => '49,12', 'amount_family' => '', 'amount_guest' => '0',
            'fee_category_normal' => (string) $this->normalFeeId,
            'guest_from' => '02/06', 'guest_until' => '15/09',
        ]);
        $this->dispatch('POST', '/admin/fees/tarifs/bareme/appliquer', 'applyTariffs', $posted);
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        foreach (['amount_normal' => '57,50', 'amount_iama' => '25,00', 'amount_couple' => '49,12',
            'amount_family' => '', 'amount_guest' => '0', 'guest_from' => $from, 'guest_until' => $until,
            'fee_category_normal' => (string) $this->normalFeeId] as $name => $value) {
            $this->assertSame($value, $this->fieldValue($body, $name), $name);
        }
        $this->assertSame($before, DatabaseTestHelper::snapshot($this->pdo));
        $this->assertNull(SuggestedScale::peek('2025-2026'));
        // A replay cannot overwrite what was filled by the first application.
        $applied = SuggestedScale::draftFor('2025-2026');
        $this->dispatch('POST', '/admin/fees/tarifs/bareme/appliquer', 'applyTariffs', $posted);
        $this->assertSame(['error', $applied], [
            FlashMessage::get()['type'], SuggestedScale::draftFor('2025-2026'),
        ]);
    }

    public function testAReplacedProposalAndAnotherYearCannotBeApplied(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $lookup = FederalScaleLookup::found(FederalScaleLookupService::DEFAULT_URL, '2025-2026', ['guest' => 1825]);
        SuggestedScale::set($lookup);
        $oldToken = SuggestedScale::peek('2025-2026')['token'];
        SuggestedScale::set($lookup);
        $current = SuggestedScale::peek('2025-2026');
        SuggestedScale::rememberDraft('2025-2026', array_replace($this->validBody(), ['amount_couple' => '44,00']));
        $posted = array_replace($this->validBody(), [
            'proposal_token' => $oldToken, 'apply_scale' => 'all',
            'amount_couple' => '47,23', 'amount_normal' => '61,11',
        ]);
        $this->dispatch('POST', '/admin/fees/tarifs/bareme/appliquer', 'applyTariffs', $posted);
        $draft = SuggestedScale::draftFor('2025-2026');
        $this->assertSame(['error', $current, '47,23', '61,11', 0], [
            FlashMessage::get()['type'], SuggestedScale::peek('2025-2026'),
            $draft['amount_couple'] ?? null, $draft['amount_normal'] ?? null, $this->storedTariffRows(),
        ]);
        $this->switchToTheShippedYear();
        $posted['proposal_token'] = $current['token'];
        $this->dispatch('POST', '/admin/fees/tarifs/bareme/appliquer', 'applyTariffs', $posted);
        $this->assertStringContainsString('année scoute', FlashMessage::get()['message']);
        $this->assertNull(SuggestedScale::draftFor('2026-2027'));
        $this->assertSame(0, $this->storedTariffRows());
    }

    public function testAnInvalidScaleKeepsAllFieldsAndWritesNothing(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $posted = array_replace($this->validBody(), ['amount_iama' => '-2', 'guest_until' => '15/09']);
        $this->dispatch('POST', '/admin/fees/tarifs/bareme', 'saveTariffs', $posted);
        $this->assertSame('error', FlashMessage::get()['type']);
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        $this->assertSame('-2', $this->fieldValue($body, 'amount_iama'));
        $this->assertSame('15/09', $this->fieldValue($body, 'guest_until'));
        $this->assertSame(0, $this->storedTariffRows());
    }

    /** @return array<string, array{string, string}> */
    public static function scalePostRoutes(): array
    {
        return [
            'save' => ['saveTariffs', '/bareme'],
            'lookup' => ['lookupTariffs', '/bareme/chercher'],
            'apply' => ['applyTariffs', '/bareme/appliquer'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('scalePostRoutes')]
    public function testAllScalePostsRequireCsrfIncludingApplication(string $action, string $suffix): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->useLookupService($this->lookupAnswering('{"annee":"2025-2026","normale":"59"}'));
        SuggestedScale::set(FederalScaleLookup::found(
            FederalScaleLookupService::DEFAULT_URL, '2025-2026', ['normal' => 5750]
        ));
        $proposal = SuggestedScale::peek('2025-2026');
        $before = DatabaseTestHelper::snapshot($this->pdo);
        // Every allowed path would change this state: save writes five rows,
        // lookup writes its journal and replaces the proposal, apply consumes
        // it and retains the current form. A refusal therefore has an effect
        // to prevent, including on the two routes that do not save prices.
        $posted = array_replace($this->validBody(), [
            '_csrf_token' => 'wrong', 'proposal_token' => $proposal['token'],
            'apply_scale' => 'all', 'amount_normal' => '61,11',
        ]);
        $this->dispatch('POST', '/admin/fees/tarifs' . $suffix, $action, $posted);
        $this->assertSame([$before, $proposal, null], [
            DatabaseTestHelper::snapshot($this->pdo), SuggestedScale::peek('2025-2026'),
            SuggestedScale::draftFor('2025-2026'),
        ]);
    }

    public function testTheLiveRuleScriptHasAnAbsoluteVersionedUrl(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        $this->assertStringContainsString('src="/assets/js/fees-scale.js?v=', $body);
    }

    public function testTheGuestSelectorRequiresAnActualImportedTariff(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        $dom = \Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $this->assertSame('hidden', $dom->querySelector('[name="fee_category_guest"]')->getAttribute('type'));
        $this->feeCategories->create('COT_INVITE', 'Invités');
        // A fresh service reads the newly imported category just as a new request does.
        $fresh = new HouseholdTariffService(new HouseholdTariffRepository($this->pdo), $this->feeCategories);
        $this->tariffs = $fresh;
        $this->useLookupService(new FederalScaleLookupService(null, $this->settingService, $this->journalService));
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        $dom = \Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $this->assertSame('SELECT', $dom->querySelector('[name="fee_category_guest"]')->tagName);
        $this->assertStringContainsString('Invités (deviné)', $body);
    }

    private function fieldValue(string $body, string $name): string
    {
        $dom = \Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $node = $dom->querySelector('[name="' . $name . '"]');
        $this->assertNotNull($node, $name);
        if ($node->tagName === 'SELECT') {
            return $node->querySelector('option[selected]')?->getAttribute('value') ?? '';
        }
        return $node->getAttribute('value');
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

        $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', $this->validBody());

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringNotContainsString('value="57,50"', $body);
        $this->assertStringContainsString('2026-2027', $body);
        $this->assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM fees_household_tariffs')->fetchColumn()
        );
    }

    // ------------------------------------------------------------------
    // The scale shipped with the site (issue #355, 2026-2027).
    // ------------------------------------------------------------------

    /** Make the shipped file's season the year the screen is about. */
    private function switchToTheShippedYear(): void
    {
        $shippedYearId = (new ScoutYearService($this->pdo))->ensureYear('2026-2027');
        $this->settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $shippedYearId);
    }

    /** @return array<string, list<string>> */
    private function storedScaleState(): array
    {
        $snapshot = DatabaseTestHelper::snapshot($this->pdo);

        return ['tariffs' => $snapshot['fees_household_tariffs'], 'settings' => $snapshot['settings']];
    }

    private function storedTariffRows(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM fees_household_tariffs')->fetchColumn();
    }

    public function testAnEmptyBaremeForTheShippedYearOpensPreFilledAndSaysWhereFrom(): void
    {
        $this->switchToTheShippedYear();
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('value="57,50"', $body);
        $this->assertStringContainsString('value="46,00"', $body);
        $this->assertStringContainsString('value="39,00"', $body);
        $this->assertStringContainsString('Montants fédéraux <strong>2026-2027</strong>', $body);
        $this->assertStringContainsString('24 novembre 2026', $body);
        $this->assertStringContainsString('href="' . FederalScaleLookupService::DEFAULT_URL . '"', $body);
        $this->assertStringContainsString('lesscouts.be</a>', $body);
        $this->assertStringContainsString('vérifiez puis enregistrez', $body);
        // A proposal on a GET: nothing reached the table.
        $this->assertSame(0, $this->storedTariffRows());
    }

    /**
     * A failed lookup says nothing was pre-filled: the page it redirects to
     * must retain the draft rather than opening shipped defaults, on
     * this render and subsequent visits alike.
     */
    public function testAFailedLookupIsNotContradictedByTheShippedScale(): void
    {
        $this->switchToTheShippedYear();
        $this->useLookupService($this->lookupAnswering(
            '{"annee": "2027-2028", "normale": "59", "couple": "47,50", "familiale": "40,25"}'
        ));
        AuthSession::login(1, 'admin@test.be', 'admin');

        $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', $this->validBody());
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        $this->assertStringNotContainsString('value="57,50"', $body);
        $this->assertStringNotContainsString('Montants fédéraux', $body);

        $again = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        $this->assertStringNotContainsString('value="57,50"', $again);
    }

    /** The shipped file is 2026-2027; this unit's screen is about 2025-2026. */
    public function testTheShippedScaleIsNotProposedForAnotherScoutYear(): void
    {
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringNotContainsString('value="57,50"', $body);
        $this->assertStringNotContainsString('Montants fédéraux', $body);
        $this->assertSame(0, $this->storedTariffRows());
    }

    /** Saved rows remain unchanged while missing rows can receive shipped defaults. */
    public function testTheShippedScaleNeverCoversAStoredAmount(): void
    {
        $this->switchToTheShippedYear();
        $this->tariffs->save(HouseholdFeeCategory::NORMAL, null, 3950);
        AuthSession::login(1, 'admin@test.be', 'admin');

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('value="39,50"', $body);
        $this->assertStringNotContainsString('value="57,50"', $body);
        $this->assertStringContainsString('value="25,00"', $body);
        $this->assertStringContainsString('Montants fédéraux', $body);
    }

    /**
     * The only write is still « Enregistrer le barème »: the pre-filled
     * figures are stored when, and as, the chef d'unité submits them — and
     * once stored, the proposal is gone.
     */
    public function testSavingThePreFilledFiguresStoresThemThroughTheUsualPath(): void
    {
        $this->switchToTheShippedYear();
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->dispatch('GET', '/admin/fees/tarifs', 'index');

        $response = $this->dispatch('POST', '/admin/fees/tarifs/bareme', 'saveTariffs', array_replace($this->validBody(), [
            'amount_normal' => '57,50',
            'amount_couple' => '46,00',
            'amount_family' => '39,00',
            'fee_category_normal' => '',
            'fee_category_couple' => '',
            'fee_category_family' => '',
            'amount_iama' => '25',
            'amount_guest' => '18,25',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(5750, $this->tariffs->amountCentsFor(HouseholdFeeCategory::NORMAL));
        $this->assertSame(4600, $this->tariffs->amountCentsFor(HouseholdFeeCategory::COUPLE));
        $this->assertSame(3900, $this->tariffs->amountCentsFor(HouseholdFeeCategory::FAMILY));
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        $this->assertStringNotContainsString('Montants fédéraux', $body);
    }

    /** An explicit « Chercher les montants » wins over the shipped file. */
    public function testAnAiProposalTakesPrecedenceOverTheShippedScale(): void
    {
        $this->switchToTheShippedYear();
        $this->useLookupService($this->lookupAnswering(
            '{"annee": "2026-2027", "normale": "58", "couple": "47", "familiale": "40"}'
        ));
        AuthSession::login(1, 'admin@test.be', 'admin');
        $this->dispatch('POST', '/admin/fees/tarifs/bareme/chercher', 'lookupTariffs', $this->validBody());

        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();

        $this->assertStringContainsString('58,00 €', $body);
        $this->assertSame('', $this->fieldValue($body, 'amount_normal'));
        $this->assertStringContainsString('Proposition fédérale pour 2026-2027', $body);
        $this->assertStringNotContainsString('Montants fédéraux', $body);
    }

    private function lookupAnswering(string $content): FederalScaleLookupService
    {
        return new StubbedFederalScaleLookupService(
            new FakeLlmConnector(tierAvailable: true, content: $content),
            $this->settingService,
            $this->journalService,
            '<h3>Cotisations 2025-2026</h3><p>Barème</p><h3>Cotisations 2026-2027</h3><p>Barème</p>'
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
            'scale_year' => $this->scoutYearResolver->getEffectiveYear(null, \Core\Security\Role::ADMIN)->label,
            'guest_from' => '01/06', 'guest_until' => '31/08',
            'amount_normal' => '', 'amount_couple' => '', 'amount_family' => '',
            'amount_iama' => '', 'amount_guest' => '',
            'fee_category_normal' => '', 'fee_category_couple' => '', 'fee_category_family' => '',
            'fee_category_iama' => '', 'fee_category_guest' => '',
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
    public function testUnknownMembershipIsVisibleInItsOwnTabAndInTheExport(): void
    {
        $this->createMember('A', $this->normalFeeId);
        $this->createMember('B', $this->normalFeeId);
        $this->createMember('C', $this->normalFeeId, null);
        AuthSession::login(1, 'admin@test.be', 'admin');
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs?vue=indeterminables', 'index')->getBody();
        self::assertStringContainsString('Non déterminables (1)', $body);
        self::assertStringContainsString('2 à 3 retenus', $body);
        self::assertStringContainsString('Dates de début de fonction absentes ou incomplètes.', $body);

        $response = $this->dispatch('GET', '/admin/fees/tarifs/export', 'export');
        $path = tempnam(sys_get_temp_dir(), 'fees-export-');
        try {
            file_put_contents($path, (string) $response->getBody());
            $sheet = (new \PhpOffice\PhpSpreadsheet\Reader\Xlsx())->load($path)->getActiveSheet();
            self::assertSame(['Non déterminables', 'Non déterminable', '2 à 3'], [
                $sheet->getCell('A2')->getValue(), $sheet->getCell('G2')->getValue(), $sheet->getCell('J2')->getValue(),
            ]);
            self::assertStringContainsString('Dates de début', (string) $sheet->getCell('K4')->getValue());
        } finally {
            unlink($path);
        }
    }

    public function testADateBasedGuestIsShownWithoutDemandingAnInventedDeskCode(): void
    {
        $this->pdo->exec(
            "INSERT INTO scout_years (label, start_date, end_date) VALUES ('2024-2025', '2024-09-01', "
            . "'2025-08-31')"
        );
        $previous = (int) $this->pdo->lastInsertId();
        $this->pdo->prepare(
            'INSERT INTO import_journal (scout_year_id, line_count, member_count, imported_at) '
            . 'VALUES (?, 0, 0, ?)'
        )
            ->execute([$previous, '2025-08-01']);
        $this->createMember('A', $this->normalFeeId);
        $this->createMember('B', $this->normalFeeId);
        $guestId = $this->createMember('C', $this->normalFeeId, '2026-06-01');
        AuthSession::login(1, 'admin@test.be', 'admin');
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs', 'index')->getBody();
        self::assertStringContainsString('2 retenu(s)', $body);
        self::assertStringContainsString('Invités selon la période', $body);
        self::assertStringContainsString('Ne compte pas pour le tarif Couple/Familial.', $body);
        $document = new \DOMDocument();
        @$document->loadHTML($body);
        $row = (new \DOMXPath($document))->query('//tr[td/a[@href="/admin/members/' . $guestId . '"]]')->item(0);
        $text = trim((string) preg_replace('/\s+/u', ' ', $row?->textContent ?? ''));
        self::assertSame(
            'C Dupont N_N_COTISATION NORMALE Invités selon la période Ne compte pas pour le tarif Couple/Familial.',
            $text
        );
    }

    public function testAMissingDeskTariffIsExplicitInTheMembersOwnRow(): void
    {
        $memberYearId = $this->createMember('Absent', null);
        $this->createMember('Connu', $this->normalFeeId);
        AuthSession::login(1, 'admin@test.be', 'admin');
        $body = (string) $this->dispatch('GET', '/admin/fees/tarifs?vue=indeterminables', 'index')->getBody();
        $document = \Dom\HTMLDocument::createFromString($body, LIBXML_NOERROR);
        $link = $document->querySelector('a[href="/admin/members/' . $memberYearId . '"]');
        self::assertNotNull($link);
        $row = $link->parentElement?->parentElement;
        self::assertNotNull($row);
        self::assertSame('aucun tarif', trim($row->getElementsByTagName('td')->item(1)->textContent));
    }

}
