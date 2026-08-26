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

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $settingService->register('registration_reference_date', '12-31', 'text', 'Réf', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_available', '0.5', 'text', 'Dispo', 'desc', 'registration');
        $settingService->register('registration_waitlist_threshold_limited', '0.1', 'text', 'Limité', 'desc', 'registration');
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
