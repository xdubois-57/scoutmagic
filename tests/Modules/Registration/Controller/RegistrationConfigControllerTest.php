<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\Database\Connection;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Core\Http\Request;
use Modules\Registration\Controller\RegistrationConfigController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * Full-stack tests through the real controller/templates for the "page de
 * gestion" (module spec Deliverable 1): year selection (target year
 * default, past years read-only, never beyond target), the request list
 * with its filters, the capacity table, the two encarts, and the bulk
 * actions' explicit-confirmation/CSRF/read-only-year guards.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationConfigControllerTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationConfigController $controller;
    private RegistrationRequestRepository $requestRepository;
    private ScoutYearService $scoutYearService;
    private SettingService $settingService;
    private int $baladinsId;
    private int $currentYearId;
    private int $targetYearId;
    private int $pastYearId;
    private JournalRepository $journalRepository;
    private SlotCapacityRepository $slotCapacityRepository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
        $ageBracketRepository = new AgeBracketRepository($this->pdo);
        $slotCapacityRepository = new SlotCapacityRepository($this->pdo);
        $slotCapacityRepository->upsert($this->baladinsId, 1, 10);
        $slotCapacityRepository->upsert($this->baladinsId, 2, 10);
        $this->slotCapacityRepository = $slotCapacityRepository;

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_reference_date', '12-31', 'text', 'Réf', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_available', '0.5', 'text', 'Dispo', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_limited', '0.1', 'text', 'Limité', 'desc', 'registration');
        $settingService->register('registration_waitlist_enabled', '1', 'boolean', 'Gérer les listes d\'attente', 'desc', 'registration');
        $settingService->register('registration_form_open', '0', 'boolean', 'Ouvert', 'desc', 'registration');
        $settingService->register('registration_scheduled_open_at', '09-30', 'text', 'Ouverture', 'desc', 'registration');
        $settingService->register('registration_scheduled_close_at', '08-31', 'text', 'Fermeture', 'desc', 'registration');
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);
        $this->settingService = $settingService;

        $this->scoutYearService = new ScoutYearService($this->pdo);
        $this->currentYearId = $this->scoutYearService->ensureYear('2026-2027');
        $this->targetYearId = $this->scoutYearService->ensureYear('2027-2028');
        $this->pastYearId = $this->scoutYearService->ensureYear('2024-2025');
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->currentYearId);

        $scoutYearResolver = new ScoutYearResolver($this->scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $yearCodeRepository = new RegistrationYearCodeRepository($this->pdo);
        $slotService = new SlotService(
            $this->pdo, $encryption, $settingService, $ageBracketRepository, $slotCapacityRepository, $this->requestRepository
        );
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService($connection, $encryption, new \Core\Badge\MemberBadgeRepository($this->pdo));
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $statusService = new RequestStatusService($this->requestRepository, $journalService);

        $templateDir = dirname(__DIR__, 4) . '/core/View/templates';
        $moduleViews = dirname(__DIR__, 4) . '/modules/registration/views';
        $twig = TwigFactory::create($templateDir, false, ['registration' => $moduleViews]);
        $twig->addGlobal('site_name', 'Unité Test');
        $twig->addGlobal('is_authenticated', true);
        $twig->addGlobal('current_user_role', 'admin');
        $twig->addGlobal('config_mode', false);
        $twig->addGlobal('cookie_consent_given', true);
        $twig->addGlobal('menus', null);
        $twig->addGlobal('current_path', '/config/inscriptions');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new RegistrationConfigController(
            $twig, $ageBracketRepository, $slotCapacityRepository, $yearCodeRepository,
            $scoutYearResolver, $this->scoutYearService, $this->requestRepository, $slotService, $sectionService,
            $editableContentService, $statusService, $journalService, $settingService,
            new \Modules\Registration\Service\RequestExportService()
        );
        $this->journalRepository = new JournalRepository($this->pdo);

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    private function createRequest(int $scoutYearId, string $childFirstName = 'Léa', string $birthDate = '2020-06-01'): int
    {
        $created = $this->requestRepository->create($scoutYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => $childFirstName,
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => strtolower($childFirstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        return $created['id'];
    }

    public function testExportReturnsAnXlsxNamedAfterTheSelectedYear(): void
    {
        $this->createRequest($this->targetYearId, 'Noa');

        $response = $this->controller->export(
            new Request('GET', '/config/inscriptions/export', [], [], [], []),
            []
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $response->getHeaders()['Content-Type'] ?? null
        );
        $this->assertStringContainsString('demandes-inscription-2027-2028.xlsx', $response->getHeaders()['Content-Disposition'] ?? '');
    }

    /**
     * The whole point of the export living on this page: it reproduces
     * what the page shows, filters included. Exporting every request
     * while looking at the pending ones would be a surprise.
     */
    public function testExportReflectsTheStatusFilterTheScreenIsShowing(): void
    {
        $pendingId = $this->createRequest($this->targetYearId, 'Noa');
        $refusedId = $this->createRequest($this->targetYearId, 'Ilan');
        $this->requestRepository->updateStatus($refusedId, 'refused', new \DateTimeImmutable());

        $all = $this->readExportedRows([]);
        $pendingOnly = $this->readExportedRows(['status' => 'pending']);

        $this->assertCount(2, $all);
        $this->assertCount(1, $pendingOnly);
        $this->assertContains('Noa', $pendingOnly[0]);
        $this->assertNotSame($pendingId, $refusedId);
    }

    public function testExportReflectsTheSearchTheScreenIsShowing(): void
    {
        $this->createRequest($this->targetYearId, 'Noa');
        $this->createRequest($this->targetYearId, 'Ilan');

        $rows = $this->readExportedRows(['q' => 'Ilan']);

        $this->assertCount(1, $rows);
        $this->assertContains('Ilan', $rows[0]);
    }

    public function testExportReflectsTheSelectedYear(): void
    {
        $this->createRequest($this->targetYearId, 'Noa');
        $this->createRequest($this->pastYearId, 'Ancienne');

        $rows = $this->readExportedRows(['year' => (string) $this->pastYearId]);

        $this->assertCount(1, $rows);
        $this->assertContains('Ancienne', $rows[0]);
    }

    /**
     * Decided: internal notes never leave the site in an export. They are
     * staff remarks about a family, and an exported file outlives every
     * protection the site has — it travels by email and lands in a shared
     * folder.
     */
    public function testExportNeverCarriesTheStaffsInternalNotes(): void
    {
        $id = $this->createRequest($this->targetYearId, 'Noa');
        $this->requestRepository->updateInternalNotes($id, 'Parents séparés, ne pas appeler le père.');

        $response = $this->controller->export(new Request('GET', '/config/inscriptions/export', [], [], [], []), []);
        $rows = $this->rowsFromResponse($response);

        $this->assertCount(1, $rows);
        $this->assertNotContains('Parents séparés, ne pas appeler le père.', $rows[0]);
        $this->assertStringNotContainsString('Parents séparés', implode('|', $rows[0]));
        $this->assertNotContains('Notes internes', $this->headersFromResponse($response));
    }

    /**
     * A registration request is filled in by anyone on a public form, so
     * a remark starting with `=` reaches this file. It must arrive as
     * text, never as a live formula in the chief's spreadsheet
     * (SECURITY.md §23).
     */
    public function testExportedFreeTextIsWrittenAsTextNotAsAFormula(): void
    {
        $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Noa',
            'gender' => 'F', 'birth_date' => '2020-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'noa@example.com',
            'phone1' => '000', 'phone2' => null,
            'remarks' => '=HYPERLINK("http://evil.test","cliquez")',
        ], null, []);

        $sheet = $this->loadExportedSheet($this->controller->export(
            new Request('GET', '/config/inscriptions/export', [], [], [], []),
            []
        ));

        $remarksColumn = array_search('Remarques', \Modules\Registration\Service\RequestExportService::headers(), true);
        $this->assertIsInt($remarksColumn);
        $cell = $sheet->getCell([$remarksColumn + 1, 2]);
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING, $cell->getDataType());
        $this->assertStringStartsWith('=HYPERLINK', (string) $cell->getValue());
    }

    /**
     * AGENTS.md § Security checklist §4: counters only. A search term on
     * this page is typically a child's or a parent's name.
     */
    public function testExportJournalsCountersAndNeverTheSearchText(): void
    {
        $this->createRequest($this->targetYearId, 'Noa');

        $this->controller->export(
            new Request('GET', '/config/inscriptions/export', ['q' => 'Noa'], [], [], []),
            []
        );

        $entries = $this->journalRepository->search('registration');
        $exported = array_values(array_filter($entries, fn(array $e) => $e['event_type'] === 'registration_requests_exported'));
        $this->assertCount(1, $exported);

        $serialized = json_encode($exported[0], JSON_UNESCAPED_UNICODE);
        $this->assertIsString($serialized);
        $this->assertStringNotContainsString('Noa', $serialized);
        $this->assertStringNotContainsString('Dupont', $serialized);

        $context = json_decode((string) $exported[0]['context'], true);
        $this->assertSame(1, $context['request_count']);
        $this->assertSame('all', $context['status_filter']);
        // The fact that a search was used is a counter; the text is not.
        $this->assertTrue($context['search_used']);
        $this->assertArrayNotHasKey('search', $context);
    }

    public function testTheExportButtonCarriesTheCountAndTheCurrentFilters(): void
    {
        $this->createRequest($this->targetYearId, 'Noa');
        $this->createRequest($this->targetYearId, 'Ilan');

        $body = $this->controller->index(
            new Request('GET', '/config/inscriptions', ['status' => 'pending'], [], [], []),
            []
        )->getBody();

        $this->assertStringContainsString('Exporter (2)', $body);
        $this->assertStringContainsString('/config/inscriptions/export?year=' . $this->targetYearId, $body);
        $this->assertStringContainsString('status=pending', $body);
    }

    // ------------------------------------------------------------------
    // Point 15 — encarts repliés, listes d'attente, capacités par défaut
    // ------------------------------------------------------------------

    /**
     * The three configuration boxes open on demand: what this page serves
     * first is the request list, not its settings. Asserted on the collapse
     * contract itself (`aria-expanded="false"` on the toggle, no `show`
     * class on the panel) rather than on rendered pixels.
     */
    public function testTheThreeConfigurationBoxesAreCollapsedByDefault(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), [])->getBody();

        foreach (['registration-form-box', 'registration-states-box', 'registration-capacities-box'] as $panelId) {
            $this->assertMatchesRegularExpression(
                '/data-bs-target="#' . preg_quote($panelId, '/') . '"[^>]*aria-expanded="false"/',
                $body,
                "the « {$panelId} » box must open on demand, not on load"
            );
            $this->assertMatchesRegularExpression(
                '/class="collapse[^"]*" id="' . preg_quote($panelId, '/') . '"/',
                $body,
                "the « {$panelId} » panel must not carry the `show` class"
            );
        }
    }

    /**
     * 15d: the default is WRITTEN, not merely displayed. Opening the page
     * is what puts it there — a branch imported from Desk after the module
     * was switched on has no row until then.
     */
    public function testOpeningThePageWritesTheDefaultCapacityForSlotsThatHaveNone(): void
    {
        $louveteauxId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $this->assertNull($this->slotCapacityRepository->capacityFor($louveteauxId, 1));

        $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), []);

        for ($yearInBranch = 1; $yearInBranch <= 4; $yearInBranch++) {
            $this->assertSame(
                \Modules\Registration\Service\SlotService::DEFAULT_CAPACITY,
                $this->slotCapacityRepository->capacityFor($louveteauxId, $yearInBranch)
            );
        }
        // Slots that already had a capacity keep theirs untouched.
        $this->assertSame(10, $this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
    }

    /**
     * The whole NULL/0 distinction, seen from the form a chief actually
     * submits: an emptied box stores "pas de limite", a typed 0 stores a
     * closed branch. `(int) ''` — what this used to do — collapsed both
     * into the second one.
     */
    public function testAnEmptyCapacityBoxStoresNoLimitAndATypedZeroStoresAClosedBranch(): void
    {
        $this->postCapacities([1 => '', 2 => '0']);

        $this->assertNull($this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
        $this->assertSame(0, $this->slotCapacityRepository->capacityFor($this->baladinsId, 2));
    }

    /**
     * And a cleared capacity survives the round trip through the page: the
     * box comes back empty, not filled with a 0, and it is not re-seeded
     * with the default either.
     */
    public function testAClearedCapacityComesBackEmptyAndIsNotReSeeded(): void
    {
        $this->postCapacities([1 => '', 2 => '20']);

        $body = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), [])->getBody();

        $this->assertNull($this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
        $this->assertStringContainsString(
            'name="capacity[' . $this->baladinsId . '][1]" value=""',
            $body
        );
        $this->assertStringContainsString('Sans limite', $body);
    }

    public function testTheCapacityTableNeverShowsAnUnlimitedBranchAsFull(): void
    {
        $this->postCapacities([1 => '', 2 => '20']);

        $body = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), [])->getBody();

        // The unlimited slot's row carries "Sans limite" and no
        // "Attente importante" badge is rendered for it. Nothing else on
        // the page is full either, so the badge must be absent entirely.
        $this->assertStringContainsString('Sans limite', $body);
        $this->assertStringNotContainsString('Attente importante', $body);
    }

    public function testTheWaitlistSwitchLivesInTheCapacityBoxAndSaves(): void
    {
        $body = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('name="waitlist_enabled"', $body);
        $this->assertStringContainsString("Gérer les listes d'attente", $body);

        $this->postCapacities([1 => '10', 2 => '10'], ['waitlist_enabled' => null]);
        $this->assertSame('0', $this->settingService->get('registration_waitlist_enabled', 'registration', '1'));

        $this->postCapacities([1 => '10', 2 => '10']);
        $this->assertSame('1', $this->settingService->get('registration_waitlist_enabled', 'registration', '0'));
    }

    /**
     * 15c: with the waitlist off, nothing on this page talks about one —
     * neither the two thresholds nor the columns that read them.
     */
    public function testNothingWaitlistRelatedIsRenderedWhenTheSwitchIsOff(): void
    {
        $this->settingService->set('registration_waitlist_enabled', '0', 'registration');

        $body = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), [])->getBody();

        $this->assertStringNotContainsString('name="threshold_available"', $body);
        $this->assertStringNotContainsString('name="threshold_limited"', $body);
        $this->assertStringNotContainsString('<th>Restant</th>', $body);
        $this->assertStringNotContainsString('<th>Niveau public</th>', $body);

        $this->settingService->set('registration_waitlist_enabled', '1', 'registration');
        $backOn = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), [])->getBody();
        $this->assertStringContainsString('name="threshold_available"', $backOn);
        $this->assertStringContainsString('<th>Niveau public</th>', $backOn);
    }

    /**
     * The thresholds are not on screen while the waitlist is off, so a
     * save made in that state must leave them exactly as stored — they
     * come back unchanged the day it is switched on again.
     */
    public function testSavingWithTheWaitlistOffKeepsTheStoredThresholds(): void
    {
        $this->settingService->set('registration_waitlist_threshold_available', '0.7', 'registration');
        $this->settingService->set('registration_waitlist_threshold_limited', '0.2', 'registration');

        $this->postCapacities([1 => '10', 2 => '10'], ['waitlist_enabled' => null]);

        $this->assertSame('0.7', $this->settingService->get('registration_waitlist_threshold_available', 'registration', ''));
        $this->assertSame('0.2', $this->settingService->get('registration_waitlist_threshold_limited', 'registration', ''));
    }

    /**
     * The switch is turned back ON from a page that, by definition, was
     * not rendering the threshold fields — so the submission carries
     * neither. An absent field is not an emptied one: the stored values
     * come back exactly as they were, and the save succeeds.
     */
    public function testTurningTheWaitlistBackOnWithoutThresholdFieldsKeepsThem(): void
    {
        $this->settingService->set('registration_waitlist_enabled', '0', 'registration');
        $this->settingService->set('registration_waitlist_threshold_available', '0.8', 'registration');
        $this->settingService->set('registration_waitlist_threshold_limited', '0.3', 'registration');

        $this->postCapacities([1 => '12', 2 => '12'], [
            'threshold_available' => null,
            'threshold_limited' => null,
        ]);

        $this->assertSame('1', $this->settingService->get('registration_waitlist_enabled', 'registration', '0'));
        $this->assertSame('0.8', $this->settingService->get('registration_waitlist_threshold_available', 'registration', ''));
        $this->assertSame('0.3', $this->settingService->get('registration_waitlist_threshold_limited', 'registration', ''));
        $this->assertSame(12, $this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
    }

    public function testThresholdsAreSavedFromTheCapacityBoxWhenTheWaitlistIsOn(): void
    {
        $this->postCapacities([1 => '10', 2 => '10'], [
            'threshold_available' => '0,6',
            'threshold_limited' => '0.15',
        ]);

        $this->assertSame('0.6', $this->settingService->get('registration_waitlist_threshold_available', 'registration', ''));
        $this->assertSame('0.15', $this->settingService->get('registration_waitlist_threshold_limited', 'registration', ''));
    }

    /**
     * An inconsistent pair changes nothing at all — not the thresholds,
     * and not the capacities either: a half-applied form is worse than a
     * refused one.
     */
    public function testInconsistentThresholdsSaveNothing(): void
    {
        $this->postCapacities([1 => '99', 2 => '99'], [
            'threshold_available' => '0.1',
            'threshold_limited' => '0.5',
        ]);

        $this->assertSame(10, $this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
        $this->assertSame('0.5', $this->settingService->get('registration_waitlist_threshold_available', 'registration', ''));
        $this->assertSame('0.1', $this->settingService->get('registration_waitlist_threshold_limited', 'registration', ''));
    }

    /**
     * POSTs the capacity box for the Baladins branch. $extra replaces the
     * default waitlist fields; a null value drops the field entirely,
     * which is what an unchecked switch does in a real submission.
     *
     * @param array<int, string> $capacities year-in-branch => raw field value
     * @param array<string, ?string> $extra
     */
    private function postCapacities(array $capacities, array $extra = []): void
    {
        $fields = array_merge([
            'waitlist_enabled' => '1',
            'threshold_available' => '0.5',
            'threshold_limited' => '0.1',
        ], $extra);

        $body = ['capacity' => [$this->baladinsId => $capacities]];
        foreach ($fields as $key => $value) {
            if ($value !== null) {
                $body[$key] = $value;
            }
        }
        $body['_csrf_token'] = CsrfGuard::generateToken();

        $this->controller->save(new Request('POST', '/config/inscriptions', [], $body, [], []), []);
    }

    /**
     * @param array<string, string> $query
     * @return array<int, array<int, string>>
     */
    private function readExportedRows(array $query): array
    {
        return $this->rowsFromResponse($this->controller->export(
            new Request('GET', '/config/inscriptions/export', $query, [], [], []),
            []
        ));
    }

    /**
     * @return array<int, array<int, string>>
     */
    private function rowsFromResponse(\Core\Http\Response $response): array
    {
        $rows = $this->loadExportedSheet($response)->toArray(null, true, true, false);
        array_shift($rows);

        return array_values(array_map(
            static fn(array $row) => array_map(static fn($v) => (string) $v, $row),
            $rows
        ));
    }

    /**
     * @return array<int, string>
     */
    private function headersFromResponse(\Core\Http\Response $response): array
    {
        $rows = $this->loadExportedSheet($response)->toArray(null, true, true, false);

        return array_map(static fn($v) => (string) $v, $rows[0] ?? []);
    }

    private function loadExportedSheet(\Core\Http\Response $response): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $path = $response->getBodyFilePath();
        $this->assertIsString($path);
        $this->assertFileExists($path);

        return \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
    }

    public function testIndexDefaultsToTargetYear(): void
    {
        $response = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), []);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('2027-2028', $response->getBody());
    }

    public function testIndexHonorsRequestedYear(): void
    {
        $this->createRequest($this->pastYearId);

        $response = $this->controller->index(
            new Request('GET', '/config/inscriptions', ['year' => (string) $this->pastYearId], [], [], []),
            []
        );

        $this->assertStringContainsString('Consultation seule', $response->getBody());
        $this->assertStringContainsString('Léa', $response->getBody());
    }

    public function testIndexIgnoresYearBeyondTarget(): void
    {
        $beyondId = $this->scoutYearService->ensureYear('2028-2029');

        $response = $this->controller->index(
            new Request('GET', '/config/inscriptions', ['year' => (string) $beyondId], [], [], []),
            []
        );

        // Falls back to the target year (2027-2028) rather than accepting 2028-2029.
        $this->assertStringContainsString('2027-2028', $response->getBody());
        $this->assertStringNotContainsString('Consultation seule', $response->getBody());
    }

    public function testRequestListShowsSlotAndStatus(): void
    {
        $this->createRequest($this->targetYearId, 'Noa', '2020-06-01');

        $response = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), []);

        $this->assertStringContainsString('Noa', $response->getBody());
        $this->assertStringContainsString('Baladins', $response->getBody());
        $this->assertStringContainsString('En attente', $response->getBody());
    }

    public function testStatusFilterExcludesOtherStatuses(): void
    {
        $pendingId = $this->createRequest($this->targetYearId, 'Pending');
        $acceptedId = $this->createRequest($this->targetYearId, 'Accepted');
        $this->requestRepository->updateStatus($acceptedId, 'accepted', null);

        $response = $this->controller->index(
            new Request('GET', '/config/inscriptions', ['status' => 'accepted'], [], [], []),
            []
        );

        $this->assertStringContainsString('Accepted', $response->getBody());
        $this->assertStringNotContainsString('Pending', $response->getBody());
    }

    public function testUnreconciledEncartListsAcceptedRequestsWithoutLinkedMember(): void
    {
        $id = $this->createRequest($this->targetYearId, 'Sana');
        $this->requestRepository->updateStatus($id, 'accepted', null);

        $response = $this->controller->index(new Request('GET', '/config/inscriptions', [], [], [], []), []);

        $this->assertStringContainsString('Sana', $response->getBody());
        $this->assertStringContainsString('non rapprochées', mb_strtolower($response->getBody()));
    }

    public function testBulkRefuseRejectsInvalidCsrf(): void
    {
        $this->createRequest($this->targetYearId);

        $this->controller->bulkRefuse(new Request('POST', '/config/inscriptions/bulk/refuse', [], [
            '_csrf_token' => 'invalid', 'scout_year_id' => (string) $this->targetYearId,
        ], [], []), []);

        $ids = array_map(static fn($r) => $r->status, $this->requestRepository->findAllForYear($this->targetYearId));
        $this->assertSame(['pending'], $ids);
    }

    public function testBulkRefuseTransitionsAllNonFinalRequestsForTheYear(): void
    {
        $id1 = $this->createRequest($this->targetYearId, 'A');
        $id2 = $this->createRequest($this->targetYearId, 'B');
        $otherYearId = $this->createRequest($this->currentYearId, 'C');

        $token = CsrfGuard::generateToken();
        $this->controller->bulkRefuse(new Request('POST', '/config/inscriptions/bulk/refuse', [], [
            '_csrf_token' => $token, 'scout_year_id' => (string) $this->targetYearId,
        ], [], []), []);

        $this->assertSame('refused', $this->requestRepository->findById($id1)->status);
        $this->assertSame('refused', $this->requestRepository->findById($id2)->status);
        $this->assertSame('pending', $this->requestRepository->findById($otherYearId)->status);
    }

    public function testBulkActionsRefusedForPastYear(): void
    {
        $id = $this->createRequest($this->pastYearId);

        $token = CsrfGuard::generateToken();
        $this->controller->bulkRefuse(new Request('POST', '/config/inscriptions/bulk/refuse', [], [
            '_csrf_token' => $token, 'scout_year_id' => (string) $this->pastYearId,
        ], [], []), []);

        $this->assertSame('pending', $this->requestRepository->findById($id)->status);
    }

    public function testBulkWithdrawTransitionsAllNonFinalRequests(): void
    {
        $id = $this->createRequest($this->targetYearId);

        $token = CsrfGuard::generateToken();
        $this->controller->bulkWithdraw(new Request('POST', '/config/inscriptions/bulk/withdraw', [], [
            '_csrf_token' => $token, 'scout_year_id' => (string) $this->targetYearId,
        ], [], []), []);

        $this->assertSame('withdrawn', $this->requestRepository->findById($id)->status);
    }

    public function testToggleOpenRejectsInvalidCsrf(): void
    {
        $this->controller->toggleOpen(new Request('POST', '/config/inscriptions/toggle-open', [], [
            '_csrf_token' => 'invalid',
        ], [], []), []);

        $this->assertSame('0', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testToggleOpenFlipsClosedToOpen(): void
    {
        $token = CsrfGuard::generateToken();
        $this->controller->toggleOpen(new Request('POST', '/config/inscriptions/toggle-open', [], [
            '_csrf_token' => $token,
        ], [], []), []);

        $this->assertSame('1', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testToggleOpenFlipsOpenToClosed(): void
    {
        $this->settingService->set('registration_form_open', '1', 'registration');

        $token = CsrfGuard::generateToken();
        $this->controller->toggleOpen(new Request('POST', '/config/inscriptions/toggle-open', [], [
            '_csrf_token' => $token,
        ], [], []), []);

        $this->assertSame('0', $this->settingService->get('registration_form_open', 'registration'));
    }

    public function testSaveScheduleRejectsInvalidCsrf(): void
    {
        $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => 'invalid', 'scheduled_open_at' => '10-01', 'scheduled_close_at' => '07-01',
        ], [], []), []);

        $this->assertSame('09-30', $this->settingService->get('registration_scheduled_open_at', 'registration'));
    }

    public function testSaveScheduleAcceptsValidMonthDays(): void
    {
        $token = CsrfGuard::generateToken();
        $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => $token, 'scheduled_open_at' => '10-01', 'scheduled_close_at' => '07-15',
        ], [], []), []);

        $this->assertSame('10-01', $this->settingService->get('registration_scheduled_open_at', 'registration'));
        $this->assertSame('07-15', $this->settingService->get('registration_scheduled_close_at', 'registration'));
    }

    public function testSaveScheduleAcceptsEmptyToDisableAutomation(): void
    {
        $token = CsrfGuard::generateToken();
        $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => $token, 'scheduled_open_at' => '', 'scheduled_close_at' => '',
        ], [], []), []);

        $this->assertSame('', $this->settingService->get('registration_scheduled_open_at', 'registration'));
        $this->assertSame('', $this->settingService->get('registration_scheduled_close_at', 'registration'));
    }

    public function testSaveScheduleRejectsMalformedMonthDay(): void
    {
        $token = CsrfGuard::generateToken();
        $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => $token, 'scheduled_open_at' => '2026-09-30', 'scheduled_close_at' => '08-31',
        ], [], []), []);

        // Rejected wholesale — neither field is saved on a bad request.
        $this->assertSame('09-30', $this->settingService->get('registration_scheduled_open_at', 'registration'));
    }

    public function testSaveScheduleRejectsImpossibleCalendarDate(): void
    {
        $token = CsrfGuard::generateToken();
        $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => $token, 'scheduled_open_at' => '02-30', 'scheduled_close_at' => '08-31',
        ], [], []), []);

        $this->assertSame('09-30', $this->settingService->get('registration_scheduled_open_at', 'registration'));
    }
}
