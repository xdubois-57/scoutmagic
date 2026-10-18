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
use Core\Http\FlashMessage;
use Core\Http\Request;
use Modules\Registration\Controller\RegistrationConfigController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationYearCodeRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Core\Mail\MailService;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\RequestStatusService;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\RequestWithInput;
use Tests\Modules\Registration\RegistrationTestHelper;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * Full-stack tests through the real controller/templates for the "page de
 * gestion" (module spec Deliverable 1), split into four sub-pages by
 * issue #843 (IT-01): the dashboard (four figures, capacity table), the
 * requests (year selection — target year default, past years read-only,
 * never beyond target —, the list with its filters, the bulk actions'
 * explicit-confirmation/CSRF/read-only-year guards) and the settings
 * (schedule, code, capacity box), plus where every existing POST sends the
 * visitor back. « Gabarits » has its own controller and its own test
 * (RegistrationTemplateControllerTest).
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
    private EditableContentService $editableContentService;

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
        $settingService->register('registration_threshold_yes_percent', '70', 'number', 'Oui', 'desc', 'registration');
        $settingService->register(
            'registration_threshold_maybe_percent',
            '100',
            'number',
            'Peut-être',
            'desc',
            'registration'
        );
        $settingService->register('registration_waitlist_enabled', '1', 'boolean', 'Gérer les listes d\'attente', 'desc', 'registration');
        $settingService->register(
            'registration_priority_order',
            '',
            'text',
            'Priorités',
            'desc',
            'registration',
            null,
            null,
            false
        );
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
        $slotService = RegistrationTestHelper::slotService($this->pdo, $encryption, $settingService);
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
);
        $editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->editableContentService = $editableContentService;
        $journalService = new JournalService(new JournalRepository($this->pdo));
        $statusService = new RequestStatusService($this->requestRepository, $journalService);
        $decisionService = RegistrationTestHelper::decisionService(
            $this->pdo,
            $encryption,
            $settingService,
            $statusService,
            new RequestEmailService(
                $this->requestRepository,
                $this->createStub(MailService::class),
                $editableContentService,
                $journalService,
                'https://example.com',
                'Unité Test',
                new \Modules\Registration\Service\DecisionEmailService(
                    new \Modules\Registration\Repository\DecisionEmailRepository($this->pdo),
                    $journalService
                )
            )
        );

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
            $editableContentService, $statusService, $decisionService, $journalService, $settingService,
            new \Modules\Registration\Service\RequestExportService(),
            new \Modules\Registration\Service\PriorityOrderService($settingService)
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
     * The export's « Fratrie » column — like the list's — counts every
     * sibling the family DECLARED, one who has left the unit since
     * included: only the recommendation's « Fratrie déjà dans l'unité » is
     * restricted to the ones still in it (issue #843, after IT-03).
     */
    public function testTheExportCountsEveryDeclaredSiblingEvenOneWhoHasLeft(): void
    {
        $siblings = [];
        foreach (['SIBIN' => 1, 'SIBOUT' => 0] as $deskId => $active) {
            $memberId = RegistrationTestHelper::insertMember($this->pdo, $deskId);
            $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, '
                . 'last_name_encrypted, is_active) VALUES (?, ?, ?, ?, ?)')
                ->execute([$memberId, $this->currentYearId, 'x', 'x', $active]);
            $siblings[] = $memberId;
        }
        $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Noa',
            'gender' => 'F', 'birth_date' => '2020-06-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'noa@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, $siblings);

        $response = $this->controller->export(new Request('GET', '/config/inscriptions/export', [], [], [], []), []);
        $column = array_search('Fratrie', $this->headersFromResponse($response), true);

        $this->assertIsInt($column);
        $this->assertSame('2', $this->rowsFromResponse($response)[0][$column]);
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
     * The sub-pages this controller renders; « Gabarits » is
     * Controller\RegistrationTemplateController's, and
     * RegistrationTemplateControllerTest holds the same two properties
     * for it.
     *
     * @return array<string, array{string, string}>
     */
    public static function subPageProvider(): array
    {
        return [
            'tableau de bord' => ['index', 'Tableau de bord'],
            'demandes' => ['requests', 'Demandes'],
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
        // The list editor's « Descendre » arrows (the « Priorités » box)
        // move a row; they fold nothing, so they are left out of this.
        $withoutMoveArrows = (string) preg_replace(
            '#<button[^>]*list-editor-move-down[^>]*>.*?</button>#s',
            '',
            $body
        );
        $this->assertStringNotContainsString('bi-chevron-down', $withoutMoveArrows, "« {$tab} »");
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
        RegistrationTestHelper::recordDecisionEmailSent($this->pdo, $refused, 'refused');
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
     * Issue #843, IT-04 — the « Action rapide » column: « Décider » on each
     * pending request, « — » on every other one, and the dialog it opens,
     * one per pending request, posting to the decision route. The child's
     * name opens the fiche.
     */
    public function testTheActionColumnOffersADecisionOnPendingRequestsOnly(): void
    {
        $pendingId = $this->createRequest($this->targetYearId, 'Noa');
        $acceptedId = $this->createRequest($this->targetYearId, 'Ilan', '2020-02-02');
        $this->requestRepository->updateStatus($acceptedId, 'accepted', null);

        $body = $this->renderPage('requests');

        $this->assertStringContainsString('<th>Action rapide</th>', $body);
        $this->assertStringContainsString('data-bs-target="#decide-' . $pendingId . '"', $body);
        $this->assertStringContainsString('aria-label="Décider — Noa Dupont">Décider</button>', $body);
        $this->assertStringNotContainsString('#decide-' . $acceptedId . '"', $body);
        $this->assertStringNotContainsString('id="decide-' . $acceptedId . '"', $body);
        $this->assertStringContainsString(
            '<a href="/config/inscriptions/demandes/' . $acceptedId . '">Ilan Dupont</a>',
            $body
        );

        $dialog = $this->dialogOf($body, $pendingId);
        $this->assertStringContainsString('Décider — Noa Dupont', $dialog);
        $this->assertStringContainsString(
            'action="/config/inscriptions/demandes/' . $pendingId . '/decision"',
            $dialog
        );
        // The slot as the mock-up names it (IT-11), the list's « Créneau »
        // cell with it.
        $this->assertMatchesRegularExpression(
            '/>\s*Baladins · \d(re|e) année · section prévue non définie · reçue le \d/u',
            $dialog
        );
        $this->assertMatchesRegularExpression('#<td>Baladins · \d(re|e) année</td>#u', $body);
        $this->assertStringNotContainsString('ᵉ année', $body);
        foreach (['Note interne', 'Motif du refus', 'Pas de place', 'Autre'] as $label) {
            $this->assertStringContainsString($label, $dialog);
        }
        $this->assertMatchesRegularExpression('#data-bs-dismiss="modal">Annuler</button>#', $dialog);
        $this->assertMatchesRegularExpression('#data-decide-refuse>Refuser</button>#', $dialog);
        $this->assertMatchesRegularExpression(
            '#name="decision" value="refuse"\s+class="btn btn-danger" hidden disabled data-decide-refuse-confirm>'
                . 'Confirmer le refus#',
            $dialog
        );
        $this->assertMatchesRegularExpression(
            '#name="decision" value="accept"\s+class="btn btn-primary">Accepter#',
            $dialog
        );
        // The reason is hidden until « Refuser » is pressed.
        $this->assertStringContainsString('<div hidden data-decide-reason>', $dialog);
    }

    /**
     * A child born outside every age bracket: the list's « Créneau » and
     * the dialog's header say « Hors des tranches d'âge », the mock-up's
     * wording (IT-11), never a placeholder.
     */
    public function testARequestOutsideTheBracketsIsNamedAsTheMockUpNamesIt(): void
    {
        $outsideId = $this->createRequest($this->targetYearId, 'Ana', '1990-01-01');

        $body = $this->renderPage('requests');

        $this->assertStringContainsString('<td>Hors des tranches d&#039;âge</td>', $body);
        $this->assertMatchesRegularExpression(
            '/>\s*Hors des tranches d&#039;âge · section prévue non définie · reçue le \d/u',
            $this->dialogOf($body, $outsideId)
        );
        $this->assertStringNotContainsString('Non déterminé', $body);
    }

    /**
     * R8: a past year is consultation only — no « Décider », no dialog. The
     * current year, open to in-year registrations, still has them.
     */
    public function testAPastYearOffersNoDecisionButTheCurrentYearDoes(): void
    {
        $pastId = $this->createRequest($this->pastYearId, 'Ancienne');
        $currentId = $this->createRequest($this->currentYearId, 'Actuelle', '2019-04-04');

        $past = $this->renderPage('requests', ['year' => (string) $this->pastYearId]);
        $current = $this->renderPage('requests', ['year' => (string) $this->currentYearId]);

        $this->assertStringContainsString('Ancienne', $past);
        $this->assertStringContainsString('<th>Action rapide</th>', $past);
        $this->assertStringNotContainsString('data-decide-form', $past);
        $this->assertStringNotContainsString('#decide-' . $pastId . '"', $past);
        $this->assertStringNotContainsString('/decision"', $past);

        $this->assertStringContainsString('data-bs-target="#decide-' . $currentId . '"', $current);
        $this->assertStringContainsString('id="decide-' . $currentId . '"', $current);
    }

    /**
     * The recommendation and its explanation, coloured by its level, while
     * waitlists are managed — « Oui » on a slot with room, « Non » on a
     * closed one, which also presets the reason to « Pas de place ».
     */
    public function testTheDialogShowsTheRecommendationColouredByItsLevel(): void
    {
        $id = $this->createRequest($this->targetYearId, 'Noa');

        $open = $this->dialogOf($this->renderPage('requests'), $id);

        $this->assertStringContainsString('Recommandation', $open);
        $this->assertMatchesRegularExpression(
            '/class="border rounded p-3 mb-3 bg-success-subtle[^"]*" data-decide-recommendation="yes">/',
            $open
        );
        $this->assertMatchesRegularExpression('/<div class="small">Oui — [^<]+<\/div>/u', $open);
        $this->assertStringContainsString('<option value="" selected>Choisir…</option>', $open);
        $this->assertStringContainsString('<option value="no_room">Pas de place</option>', $open);

        $this->slotCapacityRepository->upsert($this->baladinsId, 1, 0);
        $this->slotCapacityRepository->upsert($this->baladinsId, 2, 0);
        $closed = $this->dialogOf($this->renderPage('requests'), $id);

        $this->assertMatchesRegularExpression(
            '/class="border rounded p-3 mb-3 bg-danger-subtle[^"]*" data-decide-recommendation="no">/',
            $closed
        );
        $this->assertMatchesRegularExpression('/<div class="small">Non — [^<]+<\/div>/u', $closed);
        $this->assertStringContainsString('<option value="no_room" selected>Pas de place</option>', $closed);
        $this->assertStringContainsString('<option value="">Choisir…</option>', $closed);
    }

    /**
     * Waitlists not managed: no recommendation at all — no box, not an
     * empty one — and no reason preset, whatever the capacity.
     */
    public function testTheDialogShowsNoRecommendationWhileWaitlistsAreNotManaged(): void
    {
        $id = $this->createRequest($this->targetYearId, 'Noa');
        $this->slotCapacityRepository->upsert($this->baladinsId, 1, 0);
        $this->slotCapacityRepository->upsert($this->baladinsId, 2, 0);
        $this->settingService->set('registration_waitlist_enabled', '0', 'registration');

        $dialog = $this->dialogOf($this->renderPage('requests'), $id);

        $this->assertStringContainsString('Décider — Noa Dupont', $dialog);
        $this->assertStringNotContainsString('Recommandation', $dialog);
        $this->assertStringNotContainsString('data-decide-recommendation', $dialog);
        $this->assertStringContainsString('<option value="" selected>Choisir…</option>', $dialog);
    }

    /**
     * A possible duplicate — the same child filed twice — says so, in the
     * dialog and on its row, and has no recommendation (grey box).
     */
    public function testAPossibleDuplicateIsFlaggedInItsDialogAndOnItsRow(): void
    {
        $firstId = $this->createRequest($this->targetYearId, 'Noa');
        $secondId = $this->createRequest($this->targetYearId, 'Noa');

        $body = $this->renderPage('requests');
        $second = $this->dialogOf($body, $secondId);

        $this->assertStringContainsString(
            'Doublon possible de la demande #' . $firstId . ' : même enfant, même date de naissance.',
            $second
        );
        $this->assertMatchesRegularExpression(
            '/class="border rounded p-3 mb-3 bg-body-tertiary" data-decide-recommendation="none">/',
            $second
        );
        $this->assertStringContainsString('Pas de recommandation tant que le doublon possible', $second);
        $this->assertStringContainsString('Doublon possible de #' . $firstId . '</span>', $body);
        $this->assertStringNotContainsString('Doublon possible de', $this->dialogOf($body, $firstId));
    }

    /**
     * The note shows as stored, ready to be completed; and the e-mail
     * sentences say that nothing leaves on its own — or that nothing can
     * leave while the templates are not written.
     */
    public function testTheDialogCarriesTheNoteAndSaysWhetherAnEmailCanLeave(): void
    {
        $id = $this->createRequest($this->targetYearId, 'Noa');
        $this->requestRepository->updateInternalNotes($id, 'Appeler les parents avant d\'accepter.');

        $none = $this->dialogOf($this->renderPage('requests'), $id);

        $this->assertMatchesRegularExpression(
            '#<textarea[^>]+name="internal_notes"[^>]*placeholder="Visible par le Staff d&\#039;U seulement"[^>]*>'
                . 'Appeler les parents avant d&\#039;accepter.</textarea>#',
            $none
        );
        $this->assertStringContainsString(
            '<input type="hidden" name="internal_notes_shown" value="Appeler les parents avant d&#039;accepter.">',
            $none
        );
        $this->assertStringContainsString(
            "Aucun e-mail de décision ne peut partir tant que les gabarits d'acceptation et de refus ne sont "
                . 'pas rédigés',
            $none
        );
        $this->assertStringNotContainsString('ne part tout seul', $none);

        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);
        $half = $this->dialogOf($this->renderPage('requests'), $id);
        $this->assertStringContainsString(
            "Si vous acceptez : aucun e-mail ne part tout seul, vous l'envoyez depuis la fiche.",
            $half
        );
        $this->assertStringContainsString(
            "Si vous refusez : aucun e-mail ne peut partir tant que le gabarit de refus n'est pas rédigé",
            $half
        );

        $this->editableContentService->set('registration_email_refused_body', '<p>Désolés</p>', 'rich_text', 1);
        $both = $this->dialogOf($this->renderPage('requests'), $id);
        $this->assertStringContainsString(
            "Si vous refusez : aucun e-mail ne part tout seul, vous l'envoyez depuis la fiche.",
            $both
        );
        $this->assertStringNotContainsString('ne peut partir', $both);
    }

    /**
     * One request's decision dialog, out of a rendered « Demandes » page.
     */
    private function dialogOf(string $body, int $requestId): string
    {
        $found = preg_match(
            '/<div class="modal fade" id="decide-' . $requestId . '".*?(?=<div class="modal fade" id="decide-|\z)/s',
            $body,
            $match
        );
        $this->assertSame(1, $found, "the page carries the decision dialog of request #{$requestId}");

        return $match[0];
    }

    /**
     * The e-mails' texts are written in « Gabarits », not on the site's
     * parameters page, which cannot write them (issue #843, IT-07).
     */
    public function testTheSettingsPageSaysWhereTheEmailTextsAreWritten(): void
    {
        $body = $this->renderPage('settings');

        $this->assertStringContainsString(
            'Les textes des e-mails de demande se rédigent dans <a href="/config/inscriptions/gabarits">Gabarits</a>.',
            $body
        );
        $this->assertStringNotContainsString('textes des emails', $body);
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
     * One « Enregistrer » per box, and per bordered sub-box where a box
     * groups several (issue #843, D11): the schedule, the thresholds, the
     * capacity grid and the priorities each carry exactly one submit
     * button, and nothing else on the page saves with one — the waitlist
     * switch saves on change.
     */
    public function testEachSettingsBoxHasExactlyOneSaveButton(): void
    {
        $body = $this->renderPage('settings');

        $forms = [
            '/config/inscriptions/schedule',
            '/config/inscriptions/seuils',
            '/config/inscriptions',
            '/config/inscriptions/priorites',
            // « Copie des e-mails d'inscription » (IT-06): the switch and
            // its address, one decision.
            '/config/inscriptions/copie',
            // « Décisions automatiques » (IT-09): the switch and its delay.
            '/config/inscriptions/decisions-automatiques',
        ];
        foreach ($forms as $action) {
            $form = $this->formHtml($body, $action);
            $this->assertSame(1, substr_count($form, 'type="submit"'), $action);
            $this->assertMatchesRegularExpression('/type="submit"[^>]*>\s*Enregistrer\s*</', $form, $action);
        }
        $this->assertSame(6, preg_match_all('/>\s*Enregistrer\s*</', $body));
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
        $this->settingService->set(
            'registration_scheduled_open_applied_on',
            $justPassed->format('Y-m-d'),
            'registration'
        );
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
     * Both code actions reach families who already hold the code — a new
     * one invalidates it, deactivating shuts them out — so both forms ask
     * first (design.md §7.5), and the question says what is at stake.
     */
    public function testBothCodeActionsAskForConfirmation(): void
    {
        $withoutCode = $this->renderPage('settings');
        $this->assertStringContainsString(
            'data-confirm="Générer un code ?',
            $this->formHtml($withoutCode, '/config/inscriptions/code/regenerate')
        );
        $this->assertStringNotContainsString('/config/inscriptions/code/deactivate', $withoutCode);

        $this->controller->regenerateCode(new Request('POST', '/config/inscriptions/code/regenerate', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
        ], [], []), []);
        $withCode = $this->renderPage('settings');

        $this->assertStringContainsString(
            "data-confirm=\"Générer un nouveau code ? L'ancien ne fonctionnera plus pour les familles "
            . "qui l'ont reçu.\"",
            $this->formHtml($withCode, '/config/inscriptions/code/regenerate')
        );
        $this->assertStringContainsString(
            "data-confirm=\"Désactiver ce code ? Les familles qui l'ont reçu ne pourront plus accéder "
            . "au formulaire.\"",
            $this->formHtml($withCode, '/config/inscriptions/code/deactivate')
        );
    }

    /**
     * The export link carries the screen's filters as query parameters, each
     * URL-encoded — a status value never breaks out of its parameter.
     */
    public function testTheExportLinkEncodesTheStatusFilter(): void
    {
        $body = $this->renderPage('requests', ['status' => 'pending&year=1']);

        $this->assertStringContainsString('&amp;status=pending%26year%3D1&amp;q=', $body);
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
     * The help text under « Envoyer automatiquement les e-mails de
     * décision » in the « Décisions automatiques » sub-box $box — the
     * automatic acceptance below it has help texts of its own.
     */
    private function autoSendHelp(string $box): string
    {
        $this->assertSame(
            1,
            preg_match('#<span[^>]*id="registration-auto-send-help"[^>]*>.*?</span>#s', $box, $matches),
            'the help text of the automatic sending'
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
        $this->assertMatchesRegularExpression(
            '/name="capacity\[' . $this->baladinsId . '\]\[1\]"\s+value=""/',
            $settings
        );
        $this->assertStringContainsString('Sans limite', $dashboard);
    }

    public function testTheCapacityTableNeverShowsAnUnlimitedBranchAsFull(): void
    {
        $this->postCapacities([1 => '', 2 => '20']);

        $body = $this->renderPage('index');

        // The unlimited slot's row carries "Sans limite" and no
        // « Complet » badge is rendered for it. Nothing else on the page
        // is full either, so the badge must be absent entirely.
        $this->assertStringContainsString('Sans limite', $body);
        $this->assertStringNotContainsString('>Complet</span>', $body);
        $this->assertStringContainsString('>Disponible</span>', $body);
    }

    /**
     * The capacity check shows the unified projection: a current animé
     * whose family has not answered the reenrollment is counted as staying,
     * and said to be (« dont N sans réponse »), and the paragraph under the
     * table explains how the projection is built.
     */
    public function testTheCapacityCheckShowsHowManyProjectedAnimesHaveNotAnswered(): void
    {
        $this->pdo->prepare('INSERT INTO sections (desk_code, age_branch_id, name, is_visible) VALUES (?, ?, ?, 1)')
            ->execute(['BALA1', $this->baladinsId, 'Baladins']);
        $sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO functions (desk_code, label, role) VALUES ('anime', 'Animé', 'identified')");
        $functionId = (int) $this->pdo->lastInsertId();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        // Born 2020: Baladins 1st year in 2026-2027, 2nd year in 2027-2028.
        foreach (['DESK_A', 'DESK_B'] as $deskId) {
            $memberId = RegistrationTestHelper::insertMember($this->pdo, $deskId);
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                    birth_date_encrypted) VALUES (?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $memberId, $this->currentYearId,
                $encryption->encrypt('Prénom', 'member_years.first_name'),
                $encryption->encrypt('Nom', 'member_years.last_name'),
                $encryption->encrypt('2020-03-01', 'member_years.birth_date'),
            ]);
            $stmt = $this->pdo->prepare(
                'INSERT INTO member_functions (member_year_id, function_id, section_id, age_branch_id, is_main_function)
                    VALUES (?, ?, ?, ?, 1)'
            );
            $stmt->execute([(int) $this->pdo->lastInsertId(), $functionId, $sectionId, $this->baladinsId]);
        }

        $body = $this->renderPage('index');

        $this->assertStringContainsString('(dont 2 sans réponse)', $body);
        $this->assertStringContainsString('seuls les départs annoncés', $body);
        $this->assertStringContainsString('encodées dans Desk ou non', $body);
    }

    /**
     * « Gérer les listes d'attente » is one independent control, so it
     * saves on change through its own JSON endpoint (design.md §7.13) — it
     * sits in the capacity box, in no form of its own.
     */
    public function testTheWaitlistSwitchSavesOnChangeFromTheCapacityBox(): void
    {
        $body = $this->renderPage('settings');
        $this->assertMatchesRegularExpression('/<input[^>]*id="waitlist_enabled"[^>]*data-waitlist-switch/', $body);
        $this->assertStringContainsString("Gérer les listes d'attente", $body);
        foreach (['/config/inscriptions', '/config/inscriptions/seuils'] as $action) {
            $this->assertStringNotContainsString('id="waitlist_enabled"', $this->formHtml($body, $action), $action);
        }

        $off = $this->postWaitlist(['enabled' => false]);
        $this->assertSame(200, $off->getStatusCode());
        $this->assertSame(true, json_decode((string) $off->getBody(), true)['success'] ?? null);
        $this->assertSame('0', $this->settingService->get('registration_waitlist_enabled', 'registration', '1'));

        $on = $this->postWaitlist(['enabled' => true]);
        $this->assertSame(200, $on->getStatusCode());
        $this->assertSame('1', $this->settingService->get('registration_waitlist_enabled', 'registration', '0'));
        $this->assertSame(
            ['registration_waitlist_toggled', 'registration_waitlist_toggled'],
            array_column($this->journalEntries('registration_waitlist_toggled'), 'action')
        );
    }

    /**
     * A refused token, a body that is not JSON, or an `enabled` that is not
     * a boolean changes nothing.
     *
     * @return array<string, array{string|false, int}>
     */
    public static function refusedWaitlistBodies(): array
    {
        return [
            'no token' => [json_encode(['enabled' => false]), 403],
            'not JSON' => ['enabled=0', 400],
            'a string' => [json_encode(['enabled' => '0', '_csrf_token' => '{token}']), 400],
            'missing' => [json_encode(['_csrf_token' => '{token}']), 400],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('refusedWaitlistBodies')]
    public function testARefusedWaitlistSaveChangesNothing(string|false $raw, int $status): void
    {
        $raw = str_replace('{token}', CsrfGuard::generateToken(), (string) $raw);

        $response = $this->controller->saveWaitlist(
            new RequestWithInput('POST', '/config/inscriptions/listes-attente', [], [], [], [], $raw),
            []
        );

        $this->assertSame($status, $response->getStatusCode());
        $this->assertSame('1', $this->settingService->get('registration_waitlist_enabled', 'registration', ''));
    }

    /**
     * With the waitlist off, the capacity table drops the columns that
     * describe it, and the thresholds sub-box stays on the page — greyed,
     * its fieldset disabled, the stored values shown and kept (chantier
     * #843, D3); the « Priorités » box says it has no effect.
     */
    public function testTheThresholdsStayVisibleButDisabledWhileTheSwitchIsOff(): void
    {
        $this->settingService->set('registration_waitlist_enabled', '0', 'registration');
        $this->settingService->set('registration_threshold_yes_percent', '60', 'registration');
        $this->settingService->set('registration_threshold_maybe_percent', '90', 'registration');

        $settings = $this->renderPage('settings');
        $dashboard = $this->renderPage('index');

        $thresholds = $this->formHtml($settings, '/config/inscriptions/seuils');
        $this->assertMatchesRegularExpression('/<fieldset class="opacity-50" disabled>/', $thresholds);
        $this->assertStringContainsString('value="60"', $this->inputTag($settings, 'threshold_yes_percent'));
        $this->assertStringContainsString('value="90"', $this->inputTag($settings, 'threshold_maybe_percent'));
        $this->assertMatchesRegularExpression(
            '/<p class="small text-body-secondary mb-2" data-threshold-disabled-note>\s*Désactivés tant que/',
            $thresholds
        );
        $this->assertMatchesRegularExpression(
            '/<span data-waitlist-off-mention class="">\s*\(sans effet tant que les listes d\'attente ne sont '
            . 'pas gérées\)<\/span>/',
            $settings
        );
        $this->assertStringNotContainsString('<th>Restant</th>', $dashboard);
        $this->assertStringNotContainsString('<th>Niveau public</th>', $dashboard);

        $this->settingService->set('registration_waitlist_enabled', '1', 'registration');
        $backOn = $this->renderPage('settings');
        $this->assertMatchesRegularExpression(
            '/<fieldset class="" >/',
            $this->formHtml($backOn, '/config/inscriptions/seuils')
        );
        $this->assertStringContainsString('value="60"', $this->inputTag($backOn, 'threshold_yes_percent'));
        $this->assertStringContainsString('data-threshold-disabled-note>', $backOn);
        $this->assertMatchesRegularExpression('/mb-2 d-none" data-threshold-disabled-note>/', $backOn);
        $this->assertStringContainsString('<span data-waitlist-off-mention class="d-none">', $backOn);
        $this->assertStringContainsString('<th>Niveau public</th>', $this->renderPage('index'));
    }

    /**
     * The fields are whole percentages between 1 and 120, as the browser
     * is told too — and so are the slider's two handles, set to the same
     * two values.
     */
    public function testTheThresholdFieldsAndTheSliderAreWholePercentagesFromOneToOneHundredTwenty(): void
    {
        $body = $this->renderPage('settings');

        foreach (['threshold_yes_percent', 'threshold_maybe_percent'] as $name) {
            $tag = $this->inputTag($body, $name);
            $this->assertStringContainsString('type="number"', $tag);
            $this->assertStringContainsString('min="1"', $tag);
            $this->assertStringContainsString('max="120"', $tag);
            $this->assertStringContainsString('step="1"', $tag);
        }
        $this->assertStringContainsString('value="70"', $this->inputTag($body, 'threshold_yes_percent'));
        $this->assertStringContainsString('value="100"', $this->inputTag($body, 'threshold_maybe_percent'));

        $this->assertMatchesRegularExpression(
            '/<input type="range"[^>]*data-slider-handle="yes"[^>]*min="1" max="120" step="1"\s+value="70"\s+'
            . 'aria-label="Oui jusqu\'à ce pourcentage de places occupées">/',
            $body
        );
        $this->assertMatchesRegularExpression(
            '/<input type="range"[^>]*data-slider-handle="maybe"[^>]*min="1" max="120" step="1"\s+value="100"\s+'
            . 'aria-label="Peut-être jusqu\'à ce pourcentage de places occupées">/',
            $body
        );
        $this->assertStringContainsString('100 % · toutes les places', $body);
        // The « Oui » zone ends where a native thumb at 70 sits on a 1–120
        // track: half a thumb, plus 69/119 of the rest.
        $this->assertMatchesRegularExpression(
            '/data-slider-zone="yes"\s+style="width: calc\(0\.75rem \+ 0\.57983 \* \(100% - 1\.5rem\)\)"/',
            $body
        );
        $this->assertStringContainsString('Exemple avec une capacité de', $body);
        $this->assertMatchesRegularExpression('/id="threshold_example_capacity"[^>]*value="15"/', $body);
    }

    /**
     * The sentence about going beyond 100 % is there only when the
     * « Peut-être » threshold goes beyond it.
     */
    public function testTheSentenceBeyondOneHundredPercentFollowsThePeutEtreThreshold(): void
    {
        $this->assertMatchesRegularExpression(
            '/mb-0 d-none" data-threshold-over-full>/',
            $this->renderPage('settings')
        );

        $this->settingService->set('registration_threshold_maybe_percent', '110', 'registration');

        $body = $this->renderPage('settings');
        $this->assertMatchesRegularExpression('/mt-2 mb-0" data-threshold-over-full>/', $body);
        $this->assertStringContainsString('Au-delà de 100 %, vous acceptez plus de monde que de places', $body);
    }

    /**
     * Configuration > Réglages validates each threshold on its own, so it
     * can store an inverted pair. The levels then fall back to 70 / 100,
     * and the capacity box says so rather than showing the defaults as if
     * a chief had chosen them.
     */
    public function testAnUnusableStoredPairIsAnnouncedInTheCapacityBox(): void
    {
        $body = $this->renderPage('settings');
        $this->assertStringNotContainsString('Les seuils enregistrés ne sont pas utilisables', $body);

        $this->settingService->set('registration_threshold_yes_percent', '90', 'registration');
        $this->settingService->set('registration_threshold_maybe_percent', '80', 'registration');

        $body = $this->renderPage('settings');
        $this->assertStringContainsString('Les seuils enregistrés ne sont pas utilisables', $body);
        $this->assertStringContainsString('value="70"', $this->inputTag($body, 'threshold_yes_percent'));
        $this->assertStringContainsString('value="100"', $this->inputTag($body, 'threshold_maybe_percent'));
    }

    public function testThresholdsAreSavedFromTheirOwnSubBox(): void
    {
        $this->postThresholds(' 75 ', '120');

        $this->assertSame('75', $this->settingService->get('registration_threshold_yes_percent', 'registration', ''));
        $this->assertSame(
            '120',
            $this->settingService->get('registration_threshold_maybe_percent', 'registration', '')
        );
        $this->assertSame(['type' => 'success', 'message' => 'Seuils enregistrés.'], FlashMessage::get());
        $this->assertCount(1, $this->journalEntries('registration_thresholds_saved'));
        // The capacity grid is another sub-box, untouched.
        $this->assertSame(10, $this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
    }

    /**
     * While waitlists are not managed the sub-box is greyed out, and a
     * submission that reaches the server anyway changes nothing: the
     * values a chief cannot see take effect must not move behind the grey.
     */
    public function testThresholdsAreNotSavedWhileTheWaitlistIsOff(): void
    {
        $this->settingService->set('registration_waitlist_enabled', '0', 'registration');

        $this->postThresholds('60', '90');

        $this->assertSame('70', $this->settingService->get('registration_threshold_yes_percent', 'registration', ''));
        $this->assertSame(
            '100',
            $this->settingService->get('registration_threshold_maybe_percent', 'registration', '')
        );
        $this->assertSame('error', FlashMessage::get()['type'] ?? null);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function invalidThresholdPairs(): array
    {
        return [
            'Oui equal to Peut-être' => ['100', '100'],
            'Oui above Peut-être' => ['90', '80'],
            'zero' => ['0', '100'],
            'beyond 120' => ['70', '121'],
            'a decimal' => ['70.5', '100'],
            'empty' => ['', '100'],
            'not a number' => ['soixante-dix', '100'],
        ];
    }

    /**
     * An invalid pair changes nothing at all: neither threshold is written
     * alone.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidThresholdPairs')]
    public function testAnInvalidThresholdPairSavesNothing(string $yes, string $maybe): void
    {
        $this->postThresholds($yes, $maybe);

        $this->assertSame('70', $this->settingService->get('registration_threshold_yes_percent', 'registration', ''));
        $this->assertSame(
            '100',
            $this->settingService->get('registration_threshold_maybe_percent', 'registration', '')
        );
        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Seuils invalides', (string) ($flash['message'] ?? ''));
        $this->assertSame([], $this->journalEntries('registration_thresholds_saved'));
    }

    /**
     * The capacity grid is one row per slot, named as the recommendation
     * names it, with its own « Enregistrer »; saving it touches neither
     * the switch nor the thresholds.
     */
    public function testTheCapacityGridIsOneRowPerSlotAndSavesOnItsOwn(): void
    {
        $this->settingService->set('registration_threshold_yes_percent', '60', 'registration');

        $grid = $this->formHtml($this->renderPage('settings'), '/config/inscriptions');
        $this->assertStringContainsString('<th>Branche · année</th>', $grid);
        $this->assertStringContainsString('<td>Baladins · 1re année</td>', $grid);
        $this->assertStringContainsString('<td>Baladins · 2e année</td>', $grid);
        $this->assertStringContainsString('placeholder="sans limite"', $grid);
        $this->assertStringContainsString('aria-label="Capacité — Baladins · 2e année"', $grid);

        $this->postCapacities([1 => '12', 2 => '']);

        $this->assertSame(12, $this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
        $this->assertNull($this->slotCapacityRepository->capacityFor($this->baladinsId, 2));
        $this->assertSame(['type' => 'success', 'message' => 'Capacités enregistrées.'], FlashMessage::get());
        $this->assertSame('1', $this->settingService->get('registration_waitlist_enabled', 'registration', ''));
        $this->assertSame('60', $this->settingService->get('registration_threshold_yes_percent', 'registration', ''));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidCapacities(): array
    {
        return [
            'a word' => ['douze'],
            'negative' => ['-1'],
            'a decimal' => ['2.5'],
        ];
    }

    /**
     * A box that is neither empty nor a whole number refuses the whole
     * grid: it used to be read as « pas de limite », opening a branch
     * nobody meant to open.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidCapacities')]
    public function testAnInvalidCapacitySavesNothing(string $raw): void
    {
        $this->postCapacities([1 => '12', 2 => $raw]);

        $this->assertSame(10, $this->slotCapacityRepository->capacityFor($this->baladinsId, 1));
        $this->assertSame(10, $this->slotCapacityRepository->capacityFor($this->baladinsId, 2));
        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Capacités invalides', (string) ($flash['message'] ?? ''));
    }

    /**
     * « Priorités »: three connected lists of the shared list editor, in
     * the default order, the locked « Limite » line AFTER « Classement »,
     * with the mock-up's titles and help lines — and no activation switch,
     * no delete, no add.
     */
    public function testThePrioritiesBoxShowsTheThreeConnectedZonesInTheirDefaultOrder(): void
    {
        $body = $this->renderPage('settings');
        $box = $this->formHtml($body, '/config/inscriptions/priorites');

        $this->assertSame(
            [['always', 'sibling'], ['rank', 'previous_no_room'], ['rank', 'arrival']],
            $this->priorityFields($box)
        );
        foreach (['always', 'rank', 'off'] as $zone) {
            $this->assertStringContainsString(
                'data-sortable-group="registration-priorities" data-group-key="' . $zone . '"',
                $box
            );
        }
        $always = strpos($box, '>Toujours Oui</h3>');
        $rank = strpos($box, '>Classement</h3>');
        $limit = strpos($box, "Limite : le nombre de places de l'année de parcours");
        $off = strpos($box, '>Désactivés</h3>');
        $this->assertNotFalse($always);
        $this->assertTrue($always < $rank && $rank < $limit && $limit < $off, 'zones, then the limit after Classement');

        $this->assertStringContainsString('Toujours appliquée, en fin de classement.', $box);
        $this->assertStringContainsString("Fratrie déjà dans l&#039;unité", $box);
        $this->assertStringContainsString('Ancienne demande restée sans place', $box);
        $this->assertStringContainsString("Ordre d&#039;arrivée de la demande", $box);
        $this->assertStringContainsString('La demande déposée en premier passe en premier.', $box);
        $this->assertStringContainsString('Glissez un critère ici pour le désactiver.', $box);
        $this->assertStringContainsString('aria-label="Déplacer — Fratrie déjà dans l&#039;unité"', $box);
        $this->assertStringNotContainsString('list-editor-active-toggle', $box);
        $this->assertStringNotContainsString('list-editor-delete-btn', $box);
        $this->assertStringNotContainsString('list-editor-add-btn', $box);
        $this->assertMatchesRegularExpression('/data-reorder-url=""/', $box);
        $this->assertStringContainsString('data-can-always="0"', $box);
        $this->assertStringContainsString('<span data-waitlist-off-mention class="d-none">', $box);
    }

    public function testThePrioritiesAreSavedAndShownInTheOrderChosen(): void
    {
        $this->postPriorities(['rank' => ['arrival', 'sibling'], 'off' => ['previous_no_room']]);

        $this->assertSame(['type' => 'success', 'message' => 'Priorités enregistrées.'], FlashMessage::get());
        $this->assertSame(
            '{"always":[],"rank":["arrival","sibling"],"off":["previous_no_room"]}',
            $this->settingService->get('registration_priority_order', 'registration', '')
        );
        $this->assertCount(1, $this->journalEntries('registration_priorities_saved'));

        $box = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/priorites');
        $this->assertSame(
            [['rank', 'arrival'], ['rank', 'sibling'], ['off', 'previous_no_room']],
            $this->priorityFields($box)
        );
        $this->assertStringContainsString('Aucune règle : toutes les demandes sont classées.', $box);
    }

    /**
     * « Ordre d'arrivée » ranks requests and cannot make one of them a
     * « Oui »: the server refuses it with the mock-up's sentence, whatever
     * the browser let through.
     */
    public function testOrdreDArriveeCannotBeToujoursOui(): void
    {
        $this->postPriorities(['always' => ['sibling', 'arrival'], 'rank' => ['previous_no_room']]);

        $this->assertSame(
            [
                'type' => 'error',
                'message' => "« Ordre d'arrivée de la demande » ne peut pas être « Toujours Oui » : elle ne sert "
                    . "qu'à classer. Rien n'a été enregistré.",
            ],
            FlashMessage::get()
        );
        $this->assertSame('', $this->settingService->get('registration_priority_order', 'registration', ''));
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function malformedPriorities(): array
    {
        return [
            'a criterion missing' => [['always' => ['sibling'], 'rank' => ['arrival']]],
            'a criterion twice' => [['always' => ['sibling'], 'rank' => ['previous_no_room', 'arrival', 'sibling']]],
            'an unknown criterion' => [[
                'always' => ['sibling'],
                'rank' => ['previous_no_room', 'arrival', 'transfer'],
            ]],
            'an unknown zone' => [['always' => ['sibling'], 'rank' => ['previous_no_room'], 'later' => ['arrival']]],
            'not a list' => ['sibling'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('malformedPriorities')]
    public function testAMalformedOrderSavesNothing(mixed $priority): void
    {
        $this->controller->savePriorities(new Request('POST', '/config/inscriptions/priorites', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'priority' => $priority,
        ], [], []), []);

        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertStringContainsString('Priorités invalides', (string) ($flash['message'] ?? ''));
        $this->assertSame('', $this->settingService->get('registration_priority_order', 'registration', ''));
    }

    /**
     * The priorities stay editable while waitlists are not managed, and the
     * box says they have no effect yet (D3).
     */
    public function testThePrioritiesStayEditableWhileTheWaitlistIsOff(): void
    {
        $this->settingService->set('registration_waitlist_enabled', '0', 'registration');

        $box = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/priorites');
        $this->assertStringContainsString('<span data-waitlist-off-mention class="">', $box);
        $this->assertStringNotContainsString('<fieldset', $box);

        $this->postPriorities(['always' => ['previous_no_room'], 'rank' => ['sibling', 'arrival']]);
        $this->assertSame('success', FlashMessage::get()['type'] ?? null);
    }

    /**
     * A stored order this version cannot read is shown as the defaults,
     * and the box says so.
     */
    public function testAnUnusableStoredOrderIsAnnounced(): void
    {
        $this->settingService->setInternal('registration_priority_order', '{"always":["arrival"]}', 'registration');

        $box = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/priorites');

        $this->assertStringContainsString('Les priorités enregistrées ne sont pas utilisables', $box);
        $this->assertSame(
            [['always', 'sibling'], ['rank', 'previous_no_room'], ['rank', 'arrival']],
            $this->priorityFields($box)
        );
    }

    /**
     * The zone and criterion of every hidden field of the « Priorités »
     * box, in document order — the order the form sends.
     *
     * @return array<int, array{string, string}>
     */
    private function priorityFields(string $box): array
    {
        preg_match_all('/name="priority\[(\w+)\]\[\]" value="(\w+)"/', $box, $matches, PREG_SET_ORDER);

        return array_map(static fn(array $m): array => [$m[1], $m[2]], $matches);
    }

    /**
     * @param array<string, array<int, string>> $zones
     */
    private function postPriorities(array $zones): void
    {
        $this->controller->savePriorities(new Request('POST', '/config/inscriptions/priorites', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'priority' => $zones,
        ], [], []), []);
    }

    private function postThresholds(string $yes, string $maybe): void
    {
        $this->controller->saveThresholds(new Request('POST', '/config/inscriptions/seuils', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'threshold_yes_percent' => $yes,
            'threshold_maybe_percent' => $maybe,
        ], [], []), []);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function postWaitlist(array $payload): \Core\Http\Response
    {
        return $this->controller->saveWaitlist(new RequestWithInput(
            'POST',
            '/config/inscriptions/listes-attente',
            [],
            [],
            [],
            [],
            json_encode($payload + ['_csrf_token' => CsrfGuard::generateToken()])
        ), []);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalEntries(string $action): array
    {
        $stmt = $this->pdo->prepare('SELECT event_type AS action FROM event_log WHERE event_type = ? ORDER BY id');
        $stmt->execute([$action]);

        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * The `<input>` tag of the field named $name, or a failure when the page
     * does not render one.
     */
    private function inputTag(string $body, string $name): string
    {
        if (preg_match('/<input[^>]*name="' . preg_quote($name, '/') . '"[^>]*>/', $body, $match) !== 1) {
            $this->fail('No <input name="' . $name . '"> on the page.');
        }

        return $match[0];
    }

    /**
     * POSTs the capacity grid for the Baladins branch.
     *
     * @param array<int, string> $capacities year-in-branch => raw field value
     */
    private function postCapacities(array $capacities): void
    {
        $body = [
            'capacity' => [$this->baladinsId => $capacities],
            '_csrf_token' => CsrfGuard::generateToken(),
        ];

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
            '_csrf_token' => $token, 'scout_year_id' => (string) $this->targetYearId, 'refusal_reason' => 'no_room',
        ], [], []), []);

        $this->assertSame('refused', $this->requestRepository->findById($id1)->status);
        $this->assertSame('refused', $this->requestRepository->findById($id2)->status);
        $this->assertSame('pending', $this->requestRepository->findById($otherYearId)->status);
        // Every refusal of the batch carries the reason chosen once (D6).
        $this->assertSame('no_room', $this->requestRepository->findById($id1)->refusalReason);
        $this->assertSame('no_room', $this->requestRepository->findById($id2)->refusalReason);
        $context = (string) $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'registration_bulk_action'"
        )->fetchColumn();
        $this->assertSame(
            ['scout_year_id' => $this->targetYearId, 'count' => 2, 'refusal_reason' => 'no_room'],
            json_decode($context, true)
        );
        // Like every decision (issue #843, IT-08), the bulk refusal answers
        // « un e-mail va-t-il partir ? » — here: none, its template is
        // not written yet.
        $this->assertSame(
            [
                'type' => 'success',
                'message' => "2 demandes refusées (Pas de place). Aucun e-mail n'est parti : le gabarit de refus "
                    . "n'est pas encore rédigé (voir Gabarits). Les familles voient « en attente » jusqu'à l'envoi "
                    . 'de leur e-mail.',
            ],
            FlashMessage::get()
        );
    }

    /**
     * « Tout refuser » without a reason, or with one that is not « Pas de
     * place » / « Autre », refuses nothing at all — not the first request
     * and then an error — and says why.
     *
     * @return array<string, array{array<string, string>}>
     */
    public static function missingBulkReasonProvider(): array
    {
        return [
            'no reason' => [[]],
            'empty reason' => [['refusal_reason' => '']],
            'unknown reason' => [['refusal_reason' => 'full']],
        ];
    }

    /**
     * @param array<string, string> $reason
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingBulkReasonProvider')]
    public function testBulkRefuseWithoutAValidReasonRefusesNothing(array $reason): void
    {
        $id1 = $this->createRequest($this->targetYearId, 'A');
        $id2 = $this->createRequest($this->targetYearId, 'B');

        $response = $this->controller->bulkRefuse(new Request('POST', '/config/inscriptions/bulk/refuse', [], [
            '_csrf_token' => CsrfGuard::generateToken(), 'scout_year_id' => (string) $this->targetYearId,
        ] + $reason, [], []), []);

        $this->assertSame('pending', $this->requestRepository->findById($id1)->status);
        $this->assertSame('pending', $this->requestRepository->findById($id2)->status);
        $this->assertSame(
            '/config/inscriptions/demandes?year=' . $this->targetYearId,
            $response->getHeaders()['Location'] ?? null
        );
        $flash = FlashMessage::get();
        $this->assertSame('error', $flash['type'] ?? null);
        $this->assertSame(RequestStatusService::MESSAGE_REFUSAL_REASON_REQUIRED, $flash['message'] ?? null);
        $this->assertSame(
            0,
            (int) $this->pdo->query("SELECT COUNT(*) FROM event_log WHERE event_type = 'registration_bulk_action'")
                ->fetchColumn()
        );
    }

    /**
     * « Tout retirer » asks for no reason: a withdrawal is not a refusal.
     */
    public function testBulkWithdrawNeedsNoReason(): void
    {
        $id = $this->createRequest($this->targetYearId);

        $this->controller->bulkWithdraw(new Request('POST', '/config/inscriptions/bulk/withdraw', [], [
            '_csrf_token' => CsrfGuard::generateToken(), 'scout_year_id' => (string) $this->targetYearId,
        ], [], []), []);

        $this->assertSame('withdrawn', $this->requestRepository->findById($id)->status);
        $this->assertNull($this->requestRepository->findById($id)->refusalReason);
    }

    public function testBulkActionsRefusedForPastYear(): void
    {
        $id = $this->createRequest($this->pastYearId);

        $token = CsrfGuard::generateToken();
        $this->controller->bulkRefuse(new Request('POST', '/config/inscriptions/bulk/refuse', [], [
            '_csrf_token' => $token, 'scout_year_id' => (string) $this->pastYearId, 'refusal_reason' => 'other',
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

    private const SETTINGS = '/config/inscriptions/reglages';

    /**
     * @return array<string, array{string, string, array<string, string>, string}>
     */
    public static function postRedirectProvider(): array
    {
        return [
            'capacity box' => ['save', '/config/inscriptions', ['waitlist_enabled' => '1'], self::SETTINGS],
            'open or close now' => ['toggleOpen', '/config/inscriptions/toggle-open', [], self::SETTINGS],
            'schedule' => [
                'saveSchedule',
                '/config/inscriptions/schedule',
                ['scheduled_open_at' => '2027-09-30', 'scheduled_close_at' => ''],
                self::SETTINGS,
            ],
            'schedule refused' => [
                'saveSchedule',
                '/config/inscriptions/schedule',
                ['scheduled_open_at' => '02-29', 'scheduled_close_at' => ''],
                self::SETTINGS,
            ],
            'new code' => ['regenerateCode', '/config/inscriptions/code/regenerate', [], self::SETTINGS],
            'code off' => ['deactivateCode', '/config/inscriptions/code/deactivate', [], self::SETTINGS],
            'refuse all' => [
                'bulkRefuse',
                '/config/inscriptions/bulk/refuse',
                ['scout_year_id' => 'TARGET', 'refusal_reason' => 'no_room'],
                '/config/inscriptions/demandes?year=TARGET',
            ],
            'refuse all, no reason' => [
                'bulkRefuse',
                '/config/inscriptions/bulk/refuse',
                ['scout_year_id' => 'TARGET'],
                '/config/inscriptions/demandes?year=TARGET',
            ],
            'withdraw all' => [
                'bulkWithdraw',
                '/config/inscriptions/bulk/withdraw',
                ['scout_year_id' => 'TARGET'],
                '/config/inscriptions/demandes?year=TARGET',
            ],
            'refuse all, past year' => [
                'bulkRefuse',
                '/config/inscriptions/bulk/refuse',
                ['scout_year_id' => 'PAST', 'refusal_reason' => 'no_room'],
                '/config/inscriptions/demandes?year=PAST',
            ],
        ];
    }

    /**
     * @param array<string, string> $body
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('postRedirectProvider')]
    public function testEachPostRedirectsToTheSubPageItsFormLivesOn(
        string $action,
        string $path,
        array $body,
        string $expected
    ): void
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

    // ── « Décisions automatiques » (issue #843, IT-09) ─────────────────

    /**
     * The first bordered sub-box of « Capacités par branche », right under
     * the waitlist switch and above the thresholds: off by default, a
     * delay of 24 hours, and the switch locked — with the mock-up's reason
     * — while a decision template is « À rédiger ».
     */
    public function testTheAutomationSubBoxShowsItsDefaultsAndIsLockedWhileATemplateIsToWrite(): void
    {
        $body = $this->renderPage('settings');
        $box = $this->formHtml($body, '/config/inscriptions/decisions-automatiques');

        $capacityBox = strpos($body, 'id="registration-capacity-box"');
        $this->assertNotFalse($capacityBox);
        $subBox = strpos($body, 'decisions-automatiques');
        $this->assertGreaterThan(strpos($body, 'id="waitlist_enabled"'), $subBox);
        $this->assertLessThan(strpos($body, 'action="/config/inscriptions/seuils"'), $subBox);
        $this->assertGreaterThan($capacityBox, $subBox);

        $this->assertStringContainsString('>Décisions automatiques</h3>', $box);
        $this->assertStringContainsString('>Envoyer automatiquement les e-mails de décision</label>', $box);
        $this->assertMatchesRegularExpression('/<input[^>]+name="auto_send"[^>]*>/', $box);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="auto_send"[^>]*\bchecked\b/', $box);
        $this->assertMatchesRegularExpression('/<input[^>]+name="auto_send"[^>]*\bdisabled\b/', $box);
        $this->assertStringContainsString(
            "Indisponible : les gabarits d'acceptation et de refus ne sont pas encore rédigés",
            $box
        );
        $this->assertMatchesRegularExpression(
            '/<input[^>]+name="delay_hours"[^>]*value="24"[^>]*min="1"[^>]*max="720"/s',
            $box
        );
        $this->assertStringContainsString('Délai avant envoi', $box);
        $this->assertStringContainsString('name="_csrf_token"', $box);

        $this->writeDecisionTemplates();
        $ready = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/decisions-automatiques');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="auto_send"[^>]*\bdisabled\b/', $ready);
        $this->assertStringNotContainsString('Indisponible', $this->autoSendHelp($ready));
        $this->assertStringContainsString("L'e-mail d'acceptation ou de refus part après le délai ci-dessous", $ready);
    }

    /**
     * Switched on, the switch shows checked with the stored delay — and
     * stays usable even if a template went missing since, because
     * switching off is always allowed.
     */
    public function testAnActiveSwitchShowsCheckedAndStaysUsableToSwitchOff(): void
    {
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 48);

        $box = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/decisions-automatiques');

        $this->assertMatchesRegularExpression('/<input[^>]+name="auto_send"[^>]*\bchecked\b/', $box);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="auto_send"[^>]*\bdisabled\b/', $box);
        $this->assertMatchesRegularExpression('/<input[^>]+name="delay_hours"[^>]*value="48"/', $box);
        $this->assertStringContainsString("Les gabarits d'acceptation et de refus ne sont plus tous rédigés", $box);
        $this->assertStringNotContainsString('Indisponible', $this->autoSendHelp($box));
    }

    /**
     * While the automatic sending is on, the dialog says WHEN each e-mail
     * would leave if decided now — the mock-up's sentences — instead of
     * « aucun e-mail ne part tout seul ».
     */
    public function testTheDialogSaysWhenTheEmailWouldLeaveWhileTheAutomaticSendingIsOn(): void
    {
        $id = $this->createRequest($this->targetYearId, 'Noa');
        $this->writeDecisionTemplates();
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);

        $before = new \DateTimeImmutable('+24 hours');
        $dialog = $this->dialogOf($this->renderPage('requests'), $id);
        $after = new \DateTimeImmutable('+24 hours');

        $this->assertMatchesRegularExpression(
            "#Si vous acceptez : l'e-mail d'acceptation partira le (\d{2}/\d{2}/\d{4}) à \d{2}:\d{2}\. "
                . "D'ici là, vous pouvez changer d'avis sans que rien ne soit envoyé\.#",
            html_entity_decode($dialog, ENT_QUOTES)
        );
        preg_match('#partira le (\d{2}/\d{2}/\d{4})#', $dialog, $match);
        $this->assertContains($match[1] ?? '', [$before->format('d/m/Y'), $after->format('d/m/Y')]);
        $this->assertMatchesRegularExpression(
            "#Si vous refusez : l'e-mail de refus partira le \d{2}/\d{2}/\d{4} à \d{2}:\d{2}\.#",
            html_entity_decode($dialog, ENT_QUOTES)
        );
        $this->assertStringNotContainsString('ne part tout seul', $dialog);
    }

    /**
     * « Tout refuser » with the automatic sending on plans one e-mail per
     * refusal and says when they leave.
     */
    public function testBulkRefusalPlansEachEmailAndSaysWhenTheyLeave(): void
    {
        $first = $this->createRequest($this->targetYearId, 'A');
        $second = $this->createRequest($this->targetYearId, 'B');
        $this->writeDecisionTemplates();
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);

        $this->controller->bulkRefuse(new Request('POST', '/config/inscriptions/bulk/refuse', [], [
            '_csrf_token' => CsrfGuard::generateToken(),
            'scout_year_id' => (string) $this->targetYearId,
            'refusal_reason' => 'other',
        ], [], []), []);

        $rows = $this->pdo->query(
            "SELECT registration_request_id, decision, state FROM registration_decision_emails ORDER BY id"
        )->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertSame(
            [
                ['registration_request_id' => $first, 'decision' => 'refused', 'state' => 'scheduled'],
                ['registration_request_id' => $second, 'decision' => 'refused', 'state' => 'scheduled'],
            ],
            array_map(static fn(array $row): array => [
                'registration_request_id' => (int) $row['registration_request_id'],
                'decision' => $row['decision'],
                'state' => $row['state'],
            ], $rows)
        );
        $flash = FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertMatchesRegularExpression(
            '#^2 demandes refusées \(Autre\)\. Les e-mails de refus partiront vers le '
                . '\d{2}/\d{2}/\d{4} à \d{2}:\d{2} ;#',
            $flash['message'] ?? ''
        );
    }

    /**
     * IT-10: « Accepter automatiquement les demandes « Oui » » sits in the
     * same sub-box, under the delay and above its one « Enregistrer », with
     * its date picker; off and locked by default — the automatic sending is
     * off — with the mock-up's reason, the others hidden.
     */
    public function testTheAutomaticAcceptanceSitsInTheSubBoxOffAndLockedWithoutTheSending(): void
    {
        $box = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/decisions-automatiques');

        $this->assertStringContainsString('>Accepter automatiquement les demandes « Oui »</label>', $box);
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="auto_accept"[^>]*\bchecked\b/', $box);
        $this->assertMatchesRegularExpression('/<input[^>]+name="auto_accept"[^>]*\bdisabled\b/', $box);
        $this->assertMatchesRegularExpression('/<input type="date"[^>]+name="auto_accept_from"/', $box);
        $this->assertSame(['auto_send'], $this->shownAcceptanceHelp($box));
        $this->assertStringContainsString(
            "Indisponible : activez d'abord l'envoi automatique des e-mails de décision, sans quoi la famille",
            $box
        );
        $this->assertLessThan(strpos($box, 'name="auto_accept"'), strpos($box, 'name="delay_hours"'));
        $this->assertLessThan(strrpos($box, '>Enregistrer</button>'), strpos($box, 'name="auto_accept_from"'));
        $this->assertSame(1, substr_count($box, '>Enregistrer</button>'));
    }

    /**
     * Each prerequisite, in the server's order, locks the switch with its
     * own reason — waitlists before the sending, the sending before the
     * template — and with all of them the switch is free, checked when on,
     * its stored date shown.
     */
    public function testTheAutomaticAcceptanceSwitchFollowsItsPrerequisites(): void
    {
        $this->writeDecisionTemplates();
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);

        $ready = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/decisions-automatiques');
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="auto_accept"[^>]*\bdisabled\b/', $ready);
        $this->assertSame(['ready'], $this->shownAcceptanceHelp($ready));

        RegistrationTestHelper::enableAutomaticAcceptance($this->settingService, '2026-10-15');
        $on = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/decisions-automatiques');
        $this->assertMatchesRegularExpression('/<input[^>]+name="auto_accept"[^>]*\bchecked\b/', $on);
        $this->assertMatchesRegularExpression('/<input[^>]+name="auto_accept_from"[^>]+value="2026-10-15"/', $on);

        $this->editableContentService->set('registration_email_accepted_body', '<p>&nbsp;</p>', 'rich_text', 1);
        $noTemplate = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/decisions-automatiques');
        $this->assertSame(['template'], $this->shownAcceptanceHelp($noTemplate));
        // Locked, it is not shown on: saving would switch it off.
        $this->assertDoesNotMatchRegularExpression('/<input[^>]+name="auto_accept"[^>]*\bchecked\b/', $noTemplate);

        $this->settingService->set('registration_waitlist_enabled', '0', 'registration');
        $noWaitlist = $this->formHtml($this->renderPage('settings'), '/config/inscriptions/decisions-automatiques');
        $this->assertSame(['waitlist'], $this->shownAcceptanceHelp($noWaitlist));
        $this->assertMatchesRegularExpression('/<input[^>]+name="auto_accept"[^>]*\bdisabled\b/', $noWaitlist);
        $this->assertStringContainsString('data-waitlist-enabled="0"', $noWaitlist);
        // The template is still missing behind the waitlists: the script
        // must not free the switch once waitlists are back on.
        $this->assertStringContainsString('data-acceptance-template-ready="0"', $noWaitlist);
    }

    /**
     * Turning « Gérer les listes d'attente » off switches the automatic
     * acceptance off with it, and says so; turning it back on does not
     * switch it back on.
     */
    public function testSwitchingWaitlistsOffSwitchesTheAutomaticAcceptanceOff(): void
    {
        RegistrationTestHelper::enableAutomaticAcceptance($this->settingService, '2026-10-15');

        $off = json_decode((string) $this->postWaitlist(['enabled' => false])->getBody(), true);

        $this->assertTrue($off['auto_accept_switched_off']);
        $this->assertStringEndsWith("L'acceptation automatique des demandes « Oui » est désactivée.", $off['message']);
        $this->assertSame('0', $this->settingService->get('registration_auto_accept', 'registration'));
        $this->assertSame('2026-10-15', $this->settingService->get('registration_auto_accept_from', 'registration'));

        $on = json_decode((string) $this->postWaitlist(['enabled' => true])->getBody(), true);
        $this->assertFalse($on['auto_accept_switched_off']);
        $this->assertSame('0', $this->settingService->get('registration_auto_accept', 'registration'));

        // Already off: nothing to say.
        $again = json_decode((string) $this->postWaitlist(['enabled' => false])->getBody(), true);
        $this->assertFalse($again['auto_accept_switched_off']);
        $this->assertStringNotContainsString('acceptation automatique', $again['message']);
    }

    /**
     * IT-10, in the decision dialog while the automatic acceptance runs:
     * a request it would take says so, a duplicate and a request outside
     * every bracket say it will not — and nothing of the kind while it is
     * off.
     */
    public function testTheDialogSaysWhatTheAutomaticAcceptanceWillDoWithTheRequest(): void
    {
        $taken = $this->createRequest($this->targetYearId, 'Noa');
        $duplicate = $this->createRequest($this->targetYearId, 'Noa');
        $outside = $this->createRequest($this->targetYearId, 'Inès', '2024-01-01');

        $off = $this->renderPage('requests');
        $this->assertStringNotContainsString('data-decide-auto-accept', $off);
        $this->assertStringNotContainsString('Le traitement automatique', $off);

        $this->writeDecisionTemplates();
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);
        RegistrationTestHelper::enableAutomaticAcceptance($this->settingService, '2026-01-01');
        $body = $this->renderPage('requests');

        $this->assertStringContainsString(
            'Sans décision de votre part, cette demande sera acceptée automatiquement à la prochaine vérification du '
            . "site, dans l'heure.",
            $this->dialogOf($body, $taken)
        );
        $this->assertStringContainsString(
            'même enfant, même date de naissance. Le traitement automatique ne la prendra pas.',
            $this->dialogOf($body, $duplicate)
        );
        $this->assertStringNotContainsString('sera acceptée automatiquement', $this->dialogOf($body, $duplicate));
        $this->assertStringContainsString(
            'Le traitement automatique ne prendra pas cette demande.',
            $this->dialogOf($body, $outside)
        );
    }

    /**
     * The keys of the automatic acceptance's help texts shown (not hidden)
     * in the sub-box $box.
     *
     * @return array<int, string>
     */
    private function shownAcceptanceHelp(string $box): array
    {
        preg_match_all('/<span data-auto-accept-help="(\w+)"\s*(hidden)?\s*>/', $box, $matches, PREG_SET_ORDER);
        $this->assertCount(4, $matches, 'four help texts, one per state');

        return array_values(array_map(
            static fn(array $match): string => $match[1],
            array_filter($matches, static fn(array $match): bool => ($match[2] ?? '') === '')
        ));
    }

    private function writeDecisionTemplates(): void
    {
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolés</p>', 'rich_text', 1);
    }
}
