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
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * Full-stack tests through the real controller/templates for the "page de
 * gestion" (module spec Deliverable 1), split into four sub-pages by
 * issue #843 (IT-01): the dashboard (four figures, capacity table), the
 * requests (year selection — target year default, past years read-only,
 * never beyond target —, the list with its filters, the bulk actions'
 * explicit-confirmation/CSRF/read-only-year guards), the templates
 * placeholder and the settings (schedule, code, capacity box), plus where
 * every existing POST sends the visitor back.
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
    private \Twig\Environment $twig;

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
        $settingService->register('registration_scheduled_open_applied_on', '', 'text', 'Appliqué', 'desc', 'registration');
        $settingService->register('registration_scheduled_close_applied_on', '', 'text', 'Appliqué', 'desc', 'registration');
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
            new \Modules\Registration\Repository\PassageRosterRepository($this->pdo, $encryption), new \Core\Config\ScoutYearService($this->pdo), $settingService, $ageBracketRepository, $slotCapacityRepository, $this->requestRepository
        );
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
);
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
        $this->twig = $twig;

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

    /**
     * Renders one sub-page the way the front controller would: with the
     * `current_path` global set to its address, which is what selects its
     * tab in the rail.
     *
     * @param array<string, string> $query
     */
    private function renderPage(string $action, array $query = []): string
    {
        $paths = [
            'index' => '/config/inscriptions',
            'requests' => '/config/inscriptions/demandes',
            'templates' => '/config/inscriptions/gabarits',
            'settings' => '/config/inscriptions/reglages',
        ];
        $this->twig->addGlobal('current_path', $paths[$action]);

        $response = $this->controller->$action(new Request('GET', $paths[$action], $query, [], [], []), []);
        $this->assertSame(200, $response->getStatusCode());

        return (string) $response->getBody();
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

        $body = $this->renderPage('requests', ['status' => 'pending']);

        $this->assertStringContainsString('Exporter (2)', $body);
        $this->assertStringContainsString('/config/inscriptions/export?year=' . $this->targetYearId, $body);
        $this->assertStringContainsString('status=pending', $body);
    }

    // ------------------------------------------------------------------
    // Issue #843, IT-01 — four sub-pages, nothing folded away
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string}>
     */
    public static function subPageProvider(): array
    {
        return [
            'tableau de bord' => ['index', 'Tableau de bord'],
            'demandes' => ['requests', 'Demandes'],
            'gabarits' => ['templates', 'Gabarits'],
            'réglages' => ['settings', 'Réglages'],
        ];
    }

    /**
     * Every sub-page carries the module's one title and the same rail of
     * four tabs, with itself selected — the rail is the shared nav_rail,
     * whose selected tab says so with `aria-current="page"`.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('subPageProvider')]
    public function testEachSubPageCarriesTheTitleAndTheRailWithItselfSelected(string $action, string $tab): void
    {
        $body = $this->renderPage($action);

        // Twig escapes the apostrophe of the title.
        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Formulaire d&#039;inscription\s*</', $body);
        foreach ([
            '/config/inscriptions' => 'Tableau de bord',
            '/config/inscriptions/demandes' => 'Demandes',
            '/config/inscriptions/gabarits' => 'Gabarits',
            '/config/inscriptions/reglages' => 'Réglages',
        ] as $url => $label) {
            $this->assertStringContainsString('href="' . $url . '"', $body, "the rail links « {$label} »");
        }
        $this->assertSame(1, substr_count($body, 'aria-current="page"'), 'exactly one tab is selected');
        $this->assertMatchesRegularExpression(
            '/<a[^>]*aria-current="page"[^>]*>\s*<span>' . preg_quote($tab, '/') . '<\/span>/u',
            $body,
            "« {$tab} » is the selected tab on its own page"
        );
    }

    /**
     * Nothing on these pages folds away any more: no collapse, no chevron,
     * no remembered state (issue #843, D1).
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('subPageProvider')]
    public function testNoSubPageFoldsAnythingAway(string $action, string $tab): void
    {
        $body = $this->renderPage($action);

        $this->assertStringNotContainsString('data-bs-toggle="collapse"', $body, "« {$tab} »");
        $this->assertDoesNotMatchRegularExpression('/class="collapse[ "]/', $body, "« {$tab} »");
        $this->assertStringNotContainsString('bi-chevron-down', $body, "« {$tab} »");
    }

    /**
     * Neither a year banner nor a year switch on the dashboard, and no list
     * of accepted requests waiting for Desk anywhere — the word that used
     * to name it is gone from these pages too.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('subPageProvider')]
    public function testNoSubPageListsTheAcceptedRequestsMissingFromDesk(string $action, string $tab): void
    {
        $id = $this->createRequest($this->targetYearId, 'Sana');
        $this->requestRepository->updateStatus($id, 'accepted', null);

        $body = mb_strtolower($this->renderPage($action));

        $this->assertStringNotContainsString('rapproch', $body, "« {$tab} »");
        $this->assertStringNotContainsString("changer d'année", $body, "« {$tab} »");
    }

    public function testTheDashboardShowsTheFourFiguresWithTheirLabelsFourThenTwoPerRow(): void
    {
        // Target year: one pending, one accepted whose e-mail has not left,
        // one encoded whose e-mail has not left either, one refused whose
        // e-mail has, one withdrawn.
        $this->createRequest($this->targetYearId, 'Attente');
        $accepted = $this->createRequest($this->targetYearId, 'Acceptee');
        $this->requestRepository->updateStatus($accepted, 'accepted', null);
        $encoded = $this->createRequest($this->targetYearId, 'Encodee');
        $this->requestRepository->updateStatus($encoded, 'encoded', new \DateTimeImmutable());
        $refused = $this->createRequest($this->targetYearId, 'Refusee');
        $this->requestRepository->updateStatus($refused, 'refused', new \DateTimeImmutable());
        $this->requestRepository->markRefusedEmailSent($refused);
        $withdrawn = $this->createRequest($this->targetYearId, 'Retiree');
        $this->requestRepository->updateStatus($withdrawn, 'withdrawn', new \DateTimeImmutable());
        // Current year (an in-year registration made with the code): one
        // pending, one refused whose e-mail has not left.
        $this->createRequest($this->currentYearId, 'EnCours');
        $refusedNow = $this->createRequest($this->currentYearId, 'RefuseeEnCours');
        $this->requestRepository->updateStatus($refusedNow, 'refused', new \DateTimeImmutable());
        // A past year is consultation-only and counts for nothing.
        $this->createRequest($this->pastYearId, 'Ancienne');
        $pastAccepted = $this->createRequest($this->pastYearId, 'AncienneAcceptee');
        $this->requestRepository->updateStatus($pastAccepted, 'accepted', null);

        $body = $this->renderPage('index');

        $this->assertStringContainsString('row row-cols-2 row-cols-md-4', $body);
        $this->assertFigure($body, 'Formulaire', 'Fermé');
        $this->assertFigure($body, 'Demandes en attente', '2');
        $this->assertFigure($body, 'E-mails de décision à envoyer', '3');
        $this->assertFigure($body, 'Acceptées, pas encore dans Desk', '1');

        $this->settingService->set('registration_form_open', '1', 'registration');
        $this->assertFigure($this->renderPage('index'), 'Formulaire', 'Ouvert');
    }

    public function testTheDashboardShowsTheCapacityCheckOfTheTargetYearAndNoYearControl(): void
    {
        $body = $this->renderPage('index');

        $this->assertStringContainsString('Vérification des capacités — année 2027-2028', $body);
        $this->assertStringContainsString('<th>Effectif projeté</th>', $body);
        $this->assertStringNotContainsString('name="year"', $body);
        $this->assertStringNotContainsString('Consultation seule', $body);
        // The request list and the capacity grid live on their own pages.
        $this->assertStringNotContainsString('Exporter (', $body);
        $this->assertStringNotContainsString('name="capacity[', $body);
    }

    public function testTheRequestsPageCarriesTheNotesTheStatesTheListAndTheBulkActions(): void
    {
        $this->createRequest($this->targetYearId, 'Noa');

        $body = $this->renderPage('requests');

        $this->assertStringContainsString('Notes internes', $body);
        $this->assertStringContainsString("États d'une demande", $body);
        $this->assertStringContainsString('acceptée, pas encore encodée dans Desk', $body);
        $this->assertStringContainsString('inscription reliée à un membre', $body);
        $this->assertStringContainsString('Demandes — année 2027-2028', $body);
        $this->assertStringContainsString('action="/config/inscriptions/demandes"', $body);
        $this->assertStringContainsString('Noa', $body);
        $this->assertStringContainsString('Demandes non clôturées', $body);
        $this->assertStringContainsString('Tout refuser (1)', $body);
        $this->assertStringNotContainsString('Vérification des capacités', $body);
        $this->assertStringNotContainsString('name="capacity[', $body);
    }

    /**
     * A waiting page until the templates arrive (IT-07): its title, and
     * nothing that promises what is not there.
     */
    public function testTheTemplatesPageIsAWaitingPageWithItsTitle(): void
    {
        $body = $this->renderPage('templates');

        $this->assertStringContainsString('Gabarits des e-mails de demande', $body);
        $this->assertStringContainsString('Aucun gabarit à afficher.', $body);
        $this->assertDoesNotMatchRegularExpression('/bientôt|prochainement|à venir/iu', $body);
    }

    public function testTheSettingsPageCarriesTheFormTheCodeAndTheCapacities(): void
    {
        $body = $this->renderPage('settings');

        $this->assertStringContainsString('Programmation annuelle récurrente', $body);
        $this->assertStringContainsString('Ouvrir maintenant', $body);
        $this->assertStringContainsString(
            "Ce code permet à une famille d'accéder au formulaire même lorsque les inscriptions sont fermées.",
            $body
        );
        $this->assertStringContainsString('Capacités par branche', $body);
        $this->assertStringContainsString('name="capacity[' . $this->baladinsId . '][1]"', $body);
        // The catch-up window keeps working, and its setting stays on the
        // generic settings page; it is no longer explained here.
        $this->assertStringNotContainsString('rattrapage', $body);
        $this->assertStringNotContainsString('Vérification des capacités —', $body);
    }

    /**
     * One « Enregistrer » per box (issue #843): the schedule form and the
     * capacity form each carry exactly one submit button, and no other form
     * of the page saves anything.
     */
    public function testEachSettingsBoxHasExactlyOneSaveButton(): void
    {
        $body = $this->renderPage('settings');

        foreach (['/config/inscriptions/schedule', '/config/inscriptions'] as $action) {
            $form = $this->formHtml($body, $action);
            $this->assertSame(1, substr_count($form, 'type="submit"'), $action);
            $this->assertMatchesRegularExpression('/type="submit"[^>]*>\s*Enregistrer\s*</', $form, $action);
        }
        $this->assertSame(2, preg_match_all('/>\s*Enregistrer\s*</', $body));
    }

    /**
     * The schedule is edited with native date pickers showing the next
     * occurrence of the stored day; nothing stored, an empty picker.
     */
    public function testTheScheduleIsShownInNativeDatePickersAtItsNextOccurrence(): void
    {
        // The last 30 September is marked applied, as the scheduled task
        // leaves it, so the next one is the date to show whatever today is
        // (a 30 September a few days back would otherwise still be due,
        // see the test below).
        $today = new \DateTimeImmutable('today');
        $year = (int) $today->format('Y');
        $thisYears = sprintf('%04d-09-30', $year);
        $lastApplied = $thisYears <= $today->format('Y-m-d') ? $thisYears : sprintf('%04d-09-30', $year - 1);
        if ($thisYears <= $today->format('Y-m-d')) {
            $year++;
        }
        $this->settingService->set('registration_scheduled_open_at', '09-30', 'registration');
        $this->settingService->set('registration_scheduled_open_applied_on', $lastApplied, 'registration');
        $this->settingService->set('registration_scheduled_close_at', '', 'registration');

        $body = $this->renderPage('settings');
        $this->assertMatchesRegularExpression(
            '/<input[^>]*type="date"[^>]*id="scheduled_open_at"|<input[^>]*id="scheduled_open_at"[^>]*type="date"/',
            $body
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="scheduled_open_at"[^>]*value="' . $year . '-09-30"/',
            $body
        );
        $this->assertStringContainsString('Prochaine ouverture : 30/09/' . $year . '.', $body);
        $this->assertMatchesRegularExpression('/<input[^>]*id="scheduled_close_at"[^>]*value=""/', $body);
        $this->assertStringContainsString('Aucune fermeture programmée', $body);
    }

    /**
     * A date picked a few days back is acted on by the scheduled task at
     * its next run (catch-up window): the page shows that date and says
     * so, rather than announcing next year's.
     */
    public function testADateThatHasJustPassedIsShownAsStillToBeApplied(): void
    {
        $justPassed = (new \DateTimeImmutable('today'))->modify('-2 days');
        if ($justPassed->format('m-d') === '02-29') {
            // A 29th of February has a page state of its own (below).
            $justPassed = $justPassed->modify('-1 day');
        }
        $this->settingService->set('registration_scheduled_open_at', $justPassed->format('m-d'), 'registration');
        $this->settingService->set('registration_scheduled_open_applied_on', '', 'registration');

        $body = $this->renderPage('settings');

        $this->assertMatchesRegularExpression(
            '/<input[^>]*id="scheduled_open_at"[^>]*value="' . $justPassed->format('Y-m-d') . '"/',
            $body
        );
        $this->assertStringContainsString("Cette date vient de passer : l&#039;ouverture aura lieu", $body);

        // Once the task has applied it, the next one is next year's.
        $this->settingService->set('registration_scheduled_open_applied_on', $justPassed->format('Y-m-d'), 'registration');
        $this->assertStringContainsString(
            'Prochaine ouverture : ' . $justPassed->modify('+1 year')->format('d/m/Y') . '.',
            $this->renderPage('settings')
        );
    }

    /**
     * A 29th of February can only come from the generic settings page;
     * this form refuses to save it back, and says so before anybody tries.
     * A stored value is read trimmed, exactly as the scheduled task reads
     * it — the picker never looks empty for a schedule that is live.
     */
    public function testAStored29thOfFebruaryAndAPaddedValueAreShownForWhatTheyAre(): void
    {
        $this->settingService->set('registration_scheduled_open_at', '02-29', 'registration');
        $this->settingService->set('registration_scheduled_close_at', ' 08-31 ', 'registration');

        $body = $this->renderPage('settings');

        $this->assertStringContainsString('choisissez une autre date pour pouvoir enregistrer', $body);
        $this->assertMatchesRegularExpression('/<input[^>]*id="scheduled_open_at"[^>]*value="\d{4}-02-29"/', $body);
        $this->assertMatchesRegularExpression('/<input[^>]*id="scheduled_close_at"[^>]*value="\d{4}-08-31"/', $body);
        $this->assertStringNotContainsString('Aucune fermeture programmée', $body);
    }

    /**
     * The HTML of the first `<form>` posting to exactly $action.
     */
    private function formHtml(string $body, string $action): string
    {
        $this->assertSame(
            1,
            preg_match('#<form[^>]*action="' . preg_quote($action, '#') . '"[^>]*>.*?</form>#s', $body, $matches),
            "a form posting to {$action}"
        );

        return $matches[0];
    }

    /**
     * One of the dashboard's four figures: its label, then its value.
     */
    private function assertFigure(string $body, string $label, string $value): void
    {
        $this->assertMatchesRegularExpression(
            '#<div class="small text-body-secondary">' . preg_quote($label, '#') . '</div>\s*'
            . '<div class="h4[^"]*">' . preg_quote($value, '#') . '</div>#u',
            $body,
            "« {$label} » shows {$value}"
        );
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

        $this->renderPage('index');

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

        $settings = $this->renderPage('settings');
        $dashboard = $this->renderPage('index');

        $this->assertNull($this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
        $this->assertStringContainsString(
            'name="capacity[' . $this->baladinsId . '][1]" value=""',
            $settings
        );
        $this->assertStringContainsString('Sans limite', $dashboard);
    }

    public function testTheCapacityTableNeverShowsAnUnlimitedBranchAsFull(): void
    {
        $this->postCapacities([1 => '', 2 => '20']);

        $body = $this->renderPage('index');

        // The unlimited slot's row carries "Sans limite" and no
        // "Attente importante" badge is rendered for it. Nothing else on
        // the page is full either, so the badge must be absent entirely.
        $this->assertStringContainsString('Sans limite', $body);
        $this->assertStringNotContainsString('Attente importante', $body);
    }

    public function testTheWaitlistSwitchLivesInTheCapacityBoxAndSaves(): void
    {
        $body = $this->renderPage('settings');
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

        $settings = $this->renderPage('settings');
        $dashboard = $this->renderPage('index');

        $this->assertStringNotContainsString('name="threshold_available"', $settings);
        $this->assertStringNotContainsString('name="threshold_limited"', $settings);
        $this->assertStringNotContainsString('<th>Restant</th>', $dashboard);
        $this->assertStringNotContainsString('<th>Niveau public</th>', $dashboard);

        $this->settingService->set('registration_waitlist_enabled', '1', 'registration');
        $this->assertStringContainsString('name="threshold_available"', $this->renderPage('settings'));
        $this->assertStringContainsString('<th>Niveau public</th>', $this->renderPage('index'));
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

    public function testRequestsDefaultToTargetYear(): void
    {
        $this->assertStringContainsString('Demandes — année 2027-2028', $this->renderPage('requests'));
    }

    public function testRequestsHonorTheRequestedYear(): void
    {
        $this->createRequest($this->pastYearId);

        $body = $this->renderPage('requests', ['year' => (string) $this->pastYearId]);

        $this->assertStringContainsString('Consultation seule', $body);
        $this->assertStringContainsString('Léa', $body);
        $this->assertStringContainsString('aucune action groupée possible', $body);
    }

    public function testRequestsIgnoreAYearBeyondTarget(): void
    {
        $beyondId = $this->scoutYearService->ensureYear('2028-2029');

        $body = $this->renderPage('requests', ['year' => (string) $beyondId]);

        // Falls back to the target year (2027-2028) rather than accepting 2028-2029.
        $this->assertStringContainsString('Demandes — année 2027-2028', $body);
        $this->assertStringNotContainsString('Consultation seule', $body);
    }

    public function testRequestListShowsSlotAndStatus(): void
    {
        $this->createRequest($this->targetYearId, 'Noa', '2020-06-01');

        $body = $this->renderPage('requests');

        $this->assertStringContainsString('Noa', $body);
        $this->assertStringContainsString('Baladins', $body);
        $this->assertStringContainsString('En attente', $body);
        $this->assertStringContainsString('href="/config/inscriptions/demandes/', $body);
    }

    public function testStatusFilterExcludesOtherStatuses(): void
    {
        $this->createRequest($this->targetYearId, 'Pending');
        $acceptedId = $this->createRequest($this->targetYearId, 'Accepted');
        $this->requestRepository->updateStatus($acceptedId, 'accepted', null);

        $body = $this->renderPage('requests', ['status' => 'accepted']);

        $this->assertStringContainsString('Accepted', $body);
        $this->assertStringNotContainsString('Pending', $body);
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

    /**
     * Issue #215: the button and the hourly poll write the same setting,
     * and on a fresh installation every marker is empty — so a scheduled
     * transition still inside its catch-up window would undo the choice
     * that was just made by hand. Opening the desk settles it.
     */
    public function testToggleOpenSettlesAScheduledTransitionStillPending(): void
    {
        $yesterday = (new \DateTimeImmutable('-1 day'));
        $this->settingService->set('registration_scheduled_close_at', $yesterday->format('m-d'), 'registration');

        $token = CsrfGuard::generateToken();
        $this->controller->toggleOpen(new Request('POST', '/config/inscriptions/toggle-open', [], [
            '_csrf_token' => $token,
        ], [], []), []);

        $this->assertSame('1', $this->settingService->get('registration_form_open', 'registration'));
        $this->assertSame(
            $yesterday->format('Y-m-d'),
            $this->settingService->get('registration_scheduled_close_applied_on', 'registration')
        );
    }

    public function testToggleOpenLeavesAScheduleThatIsNotDueAlone(): void
    {
        $this->settingService->set(
            'registration_scheduled_close_at',
            (new \DateTimeImmutable('+40 days'))->format('m-d'),
            'registration'
        );

        $token = CsrfGuard::generateToken();
        $this->controller->toggleOpen(new Request('POST', '/config/inscriptions/toggle-open', [], [
            '_csrf_token' => $token,
        ], [], []), []);

        $this->assertSame(
            '',
            (string) $this->settingService->get('registration_scheduled_close_applied_on', 'registration')
        );
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

    // ------------------------------------------------------------------
    // Issue #843, IT-01 — the existing POSTs keep their address and send
    // the visitor back to the sub-page their form lives on
    // ------------------------------------------------------------------

    /**
     * @return array<string, array{string, string, array<string, string>, string}>
     */
    public static function postRedirectProvider(): array
    {
        return [
            'capacity box' => ['save', '/config/inscriptions', ['waitlist_enabled' => '1'], '/config/inscriptions/reglages'],
            'open or close now' => ['toggleOpen', '/config/inscriptions/toggle-open', [], '/config/inscriptions/reglages'],
            'schedule' => ['saveSchedule', '/config/inscriptions/schedule', ['scheduled_open_at' => '2027-09-30', 'scheduled_close_at' => ''], '/config/inscriptions/reglages'],
            'schedule refused' => ['saveSchedule', '/config/inscriptions/schedule', ['scheduled_open_at' => '02-29', 'scheduled_close_at' => ''], '/config/inscriptions/reglages'],
            'new code' => ['regenerateCode', '/config/inscriptions/code/regenerate', [], '/config/inscriptions/reglages'],
            'code off' => ['deactivateCode', '/config/inscriptions/code/deactivate', [], '/config/inscriptions/reglages'],
            'refuse all' => ['bulkRefuse', '/config/inscriptions/bulk/refuse', ['scout_year_id' => 'TARGET'], '/config/inscriptions/demandes?year=TARGET'],
            'withdraw all' => ['bulkWithdraw', '/config/inscriptions/bulk/withdraw', ['scout_year_id' => 'TARGET'], '/config/inscriptions/demandes?year=TARGET'],
            'refuse all, past year' => ['bulkRefuse', '/config/inscriptions/bulk/refuse', ['scout_year_id' => 'PAST'], '/config/inscriptions/demandes?year=PAST'],
        ];
    }

    /**
     * @param array<string, string> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('postRedirectProvider')]
    public function testEachPostRedirectsToTheSubPageItsFormLivesOn(string $action, string $path, array $body, string $expected): void
    {
        $this->createRequest($this->targetYearId);
        $years = ['TARGET' => (string) $this->targetYearId, 'PAST' => (string) $this->pastYearId];
        $body = array_map(static fn(string $value) => strtr($value, $years), $body);

        $response = $this->controller->$action(
            new Request('POST', $path, [], $body + ['_csrf_token' => CsrfGuard::generateToken()], [], []),
            []
        );

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(strtr($expected, $years), $response->getHeaders()['Location'] ?? null);
    }

    /**
     * An expired session is sent back to the same sub-page, not to the
     * dashboard it no longer came from.
     */
    public function testARefusedCsrfTokenAlsoReturnsToTheSubPage(): void
    {
        $settings = $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => 'invalid', 'scheduled_open_at' => '2027-10-01', 'scheduled_close_at' => '',
        ], [], []), []);
        $requests = $this->controller->bulkRefuse(new Request('POST', '/config/inscriptions/bulk/refuse', [], [
            '_csrf_token' => 'invalid', 'scout_year_id' => (string) $this->targetYearId,
        ], [], []), []);

        $this->assertSame('/config/inscriptions/reglages', $settings->getHeaders()['Location'] ?? null);
        $this->assertSame(
            '/config/inscriptions/demandes?year=' . $this->targetYearId,
            $requests->getHeaders()['Location'] ?? null
        );
    }

    /**
     * What a native date picker posts is a full date: only its month and
     * day are stored, whatever the year it carries.
     */
    public function testSaveScheduleStoresTheMonthAndDayOfWhatTheDatePickerPosts(): void
    {
        $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'scheduled_open_at' => '2027-10-01',
            'scheduled_close_at' => '2031-07-15',
        ], [], []), []);

        $this->assertSame('10-01', $this->settingService->get('registration_scheduled_open_at', 'registration'));
        $this->assertSame('07-15', $this->settingService->get('registration_scheduled_close_at', 'registration'));
    }

    /**
     * Decided for issue #843 (IT-01): the 29th of February stays refused,
     * picked in a date picker on a leap year as much as typed — a recurring
     * date that exists one year in four would fire one year in four. The
     * whole save is refused, the other field included, and the message
     * says why.
     */
    public function testSaveScheduleRefusesThe29thOfFebruaryFromTheDatePicker(): void
    {
        $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'scheduled_open_at' => '2028-02-29',
            'scheduled_close_at' => '2027-07-15',
        ], [], []), []);

        $this->assertSame('09-30', $this->settingService->get('registration_scheduled_open_at', 'registration'));
        $this->assertSame('08-31', $this->settingService->get('registration_scheduled_close_at', 'registration'));
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('29 février', $flash['message'] ?? '');
    }

    public function testSaveScheduleRefusesADateThatDoesNotExist(): void
    {
        $this->controller->saveSchedule(new Request('POST', '/config/inscriptions/schedule', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'scheduled_open_at' => '2027-04-31',
            'scheduled_close_at' => '',
        ], [], []), []);

        $this->assertSame('09-30', $this->settingService->get('registration_scheduled_open_at', 'registration'));
        $this->assertSame('08-31', $this->settingService->get('registration_scheduled_close_at', 'registration'));
    }
}
