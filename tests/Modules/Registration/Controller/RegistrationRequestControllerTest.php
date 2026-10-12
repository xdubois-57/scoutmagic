<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Controller;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\Http\Request;
use Core\Import\FeeCategoryRepository;
use Core\Import\MemberRepository;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Member\FeeEstimationRepository;
use Core\Member\FeeEstimationService;
use Core\Member\MemberEmailRepository;
use Core\Member\MemberService;
use Core\Member\SectionService;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\CsrfGuard;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Core\View\TwigFactory;
use Modules\Registration\Controller\RegistrationRequestController;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\HouseholdRegistrationCountService;
use Modules\Registration\Service\MigrationService;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\RequestStatusService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;
use Core\Member\Repository\MemberProfileRepository;
use Core\Member\Repository\SectionRepository;

/**
 * Full-stack tests through the real controller/templates for the "fiche
 * d'une demande" (module spec Deliverable 2): status transitions and their
 * rejection when inconsistent, "section prévue" restricted to the slot's
 * own branch and never shown to parents, fee suggestion including accepted
 * requests, internal notes saved without ever being journaled, email
 * send guarded by an empty body, and manual linking (invalid/duplicate
 * Desk numbers refused, a valid one migrating through Service\
 * MigrationService — the same path Service\ReconciliationService uses).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class RegistrationRequestControllerTest extends TestCase
{
    private \PDO $pdo;
    private RegistrationRequestController $controller;
    private RegistrationRequestRepository $requestRepository;
    private JournalRepository $journalRepository;
    private EditableContentService $editableContentService;
    private int $baladinsId;
    private int $louveteauxId;
    private int $baladinsSectionId;
    private int $louveteauxSectionId;
    private int $targetYearId;
    private int $publicYearId;
    private EncryptionService $encryption;
    private SettingService $settingService;
    private RequestStatusService $statusService;
    private RequestEmailService $emailService;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->encryption = $encryption;

        $this->baladinsId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'BALA', 'Baladins', 10);
        $this->louveteauxId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $ageBracketRepository = new AgeBracketRepository($this->pdo);

        $stmt = $this->pdo->prepare('INSERT INTO sections (age_branch_id, desk_code, name) VALUES (?, ?, ?)');
        $stmt->execute([$this->baladinsId, 'BALA1', 'Baladins A']);
        $this->baladinsSectionId = (int) $this->pdo->lastInsertId();
        $stmt->execute([$this->louveteauxId, 'LOUV1', 'Louveteaux A']);
        $this->louveteauxSectionId = (int) $this->pdo->lastInsertId();

        $feeCategoryRepository = new FeeCategoryRepository($this->pdo);
        $feeCategoryRepository->create('NORM', 'Tarif normal');

        $settingService = new SettingService(new SettingRepository($this->pdo));
        $this->settingService = $settingService;
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
        $settingService->register(ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false);
        $settingService->register(ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false);

        $scoutYearService = new ScoutYearService($this->pdo);
        $currentYearId = $scoutYearService->ensureYear('2026-2027');
        $this->targetYearId = $scoutYearService->ensureYear('2027-2028');
        $this->publicYearId = $currentYearId;
        $settingService->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $currentYearId);
        $scoutYearResolver = new ScoutYearResolver($scoutYearService, $settingService, new MemberYearRepository($this->pdo));

        $this->requestRepository = new RegistrationRequestRepository($this->pdo, $encryption);
        $slotService = RegistrationTestHelper::slotService($this->pdo, $encryption, $settingService);
        $connection = Connection::withPdo($this->pdo);
        $sectionService = new SectionService(
    new SectionRepository($connection),
    new MemberProfileRepository($connection, $encryption, new \Core\Badge\MemberBadgeRepository($this->pdo))
);

        $feeEstimationRepository = new FeeEstimationRepository($this->pdo, $encryption);
        $registrationCount = new HouseholdRegistrationCountService($this->requestRepository);
        $feeEstimationService = new FeeEstimationService($feeEstimationRepository, $registrationCount);

        $this->journalRepository = new JournalRepository($this->pdo);
        $journalService = new JournalService($this->journalRepository);
        $statusService = new RequestStatusService($this->requestRepository, $journalService);
        $this->statusService = $statusService;

        $mailService = $this->createStub(MailService::class);
        $mailService->method('send');
        $this->editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $emailService = new RequestEmailService(
            $this->requestRepository,
            $mailService,
            $this->editableContentService,
            $journalService,
            'https://example.com',
            'Unité Test',
            new DecisionEmailService(new DecisionEmailRepository($this->pdo), $journalService)
        );
        $this->emailService = $emailService;

        $secondaryEmailRepository = new RegistrationSecondaryEmailRepository($this->pdo, $encryption);
        $memberEmailRepository = new MemberEmailRepository($this->pdo, $encryption);
        $migrationService = new MigrationService($this->pdo, $this->requestRepository, $secondaryEmailRepository, $memberEmailRepository, $journalService);

        $memberRepository = new MemberRepository($this->pdo);
        $memberYearRepository = new MemberYearRepository($this->pdo);
        $memberService = new MemberService(
    $memberYearRepository,
    new MemberProfileRepository($connection, $encryption)
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
        $twig->addGlobal('current_path', '/config/inscriptions/demandes/1');
        $twig->addGlobal('csp_nonce', 'test-nonce');

        $this->controller = new RegistrationRequestController(
            $twig, $this->requestRepository, $ageBracketRepository, $sectionService, $feeCategoryRepository,
            $feeEstimationService, $statusService, $emailService, $migrationService, $memberRepository,
            $memberYearRepository, $scoutYearResolver, $scoutYearService, $slotService, $memberService
        );

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION = [];
    }

    private function createRequest(string $birthDate = '2020-06-01'): int
    {
        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'Rue Test', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, []);

        return $created['id'];
    }

    private function csrfBody(array $extra = []): array
    {
        return array_merge(['_csrf_token' => CsrfGuard::generateToken()], $extra);
    }

    public function testShowRendersFiche(): void
    {
        $id = $this->createRequest();

        $response = $this->controller->show(new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []), ['id' => (string) $id]);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Léa', $response->getBody());
        $this->assertStringContainsString('Dupont', $response->getBody());
        $this->assertStringContainsString('Baladins', $response->getBody());
    }

    /**
     * A declared sibling shows by name and their projected section for the
     * request's target scout year (Louveteaux born 2018 -> 9 years old in
     * 2027 -> Louveteaux still, 2nd year), not just a bare count — staff
     * need to know WHO the siblings are and where they'll sit, not just
     * how many there are.
     */
    public function testShowDisplaysSiblingNameAndProjectedSection(): void
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'SIB1');
        $stmt = $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted, birth_date_encrypted, scout_year_offset)
             VALUES (?, ?, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $memberId, $this->publicYearId,
            $this->encryption->encrypt('MARIE', 'member_years.first_name'), $this->encryption->encrypt('DUPONT', 'member_years.last_name'),
            $this->encryption->encrypt('2018-03-01', 'member_years.birth_date'),
        ]);

        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2020-06-01', 'street' => 'Rue Test', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [$memberId]);

        $response = $this->controller->show(new Request('GET', "/config/inscriptions/demandes/{$created['id']}", [], [], [], []), ['id' => (string) $created['id']]);
        $body = $response->getBody();

        $this->assertStringContainsString('Marie Dupont', $body);
        $this->assertStringContainsString('Louveteaux', $body);
    }

    /**
     * The fiche shows the siblings the family DECLARED, a sibling who has
     * left the unit since included — only the recommendation's « Fratrie
     * déjà dans l'unité » is restricted to the ones still in it (issue
     * #843, after IT-03): one has no active enrolment this year any more,
     * the other was never imported at all.
     */
    public function testShowStillListsADeclaredSiblingWhoHasLeftTheUnit(): void
    {
        $left = RegistrationTestHelper::insertMember($this->pdo, 'SIBLEFT');
        $this->pdo->prepare(
            'INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, last_name_encrypted,
                birth_date_encrypted, is_active) VALUES (?, ?, ?, ?, ?, 0)'
        )->execute([
            $left, $this->publicYearId,
            $this->encryption->encrypt('PAUL', 'member_years.first_name'),
            $this->encryption->encrypt('DUPONT', 'member_years.last_name'),
            $this->encryption->encrypt('2015-03-01', 'member_years.birth_date'),
        ]);
        $neverImported = RegistrationTestHelper::insertMember($this->pdo, 'SIBNONE');

        $created = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Léa',
            'gender' => 'F', 'birth_date' => '2020-06-01', 'street' => 'Rue Test', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [$left, $neverImported]);

        $id = (string) $created['id'];
        $body = (string) $this->controller->show(
            new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []),
            ['id' => $id]
        )->getBody();

        $this->assertStringContainsString('Frère(s)/sœur(s) déjà membre(s) déclaré(s)', $body);
        $this->assertStringContainsString('Paul Dupont', $body);
        $this->assertStringContainsString("Membre #{$neverImported}", $body);
    }

    public function testShowReturns404ForUnknownId(): void
    {
        $response = $this->controller->show(new Request('GET', '/config/inscriptions/demandes/999', [], [], [], []), ['id' => '999']);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAcceptTransitionsPendingToAccepted(): void
    {
        $id = $this->createRequest();

        $this->controller->accept(new Request('POST', "/config/inscriptions/demandes/{$id}/accept", [], $this->csrfBody(), [], []), ['id' => (string) $id]);

        $this->assertSame('accepted', $this->requestRepository->findById($id)->status);
    }

    public function testAcceptRejectedWhenAlreadyEncoded(): void
    {
        $id = $this->createRequest();
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'M1');
        $this->requestRepository->updateStatus($id, 'accepted', null);
        $this->requestRepository->linkToMemberAndEncode($id, $memberId, new \DateTimeImmutable());

        $response = $this->controller->accept(new Request('POST', "/config/inscriptions/demandes/{$id}/accept", [], $this->csrfBody(), [], []), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('encoded', $this->requestRepository->findById($id)->status);
    }

    public function testTransitionRejectedWithInvalidCsrf(): void
    {
        $id = $this->createRequest();

        $this->controller->accept(new Request('POST', "/config/inscriptions/demandes/{$id}/accept", [], ['_csrf_token' => 'bad'], [], []), ['id' => (string) $id]);

        $this->assertSame('pending', $this->requestRepository->findById($id)->status);
    }

    /**
     * Issue #843 D6: a refusal from the fiche carries its reason — stored
     * with the status, and shown beside « Refusée ».
     */
    public function testARefusalFromTheFicheStoresItsReason(): void
    {
        $id = $this->createRequest();

        $this->controller->refuse(
            new Request('POST', "/config/inscriptions/demandes/{$id}/refuse", [], $this->csrfBody([
                'refusal_reason' => 'other',
            ]), [], []),
            ['id' => (string) $id]
        );

        $refused = $this->requestRepository->findById($id);
        $this->assertSame('refused', $refused->status);
        $this->assertSame('other', $refused->refusalReason);
        $body = (string) $this->controller->show(
            new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []),
            ['id' => (string) $id]
        )->getBody();
        $this->assertStringContainsString('<span class="small">Motif : Autre</span>', $body);
    }

    /**
     * @return array<string, array{array<string, string>}>
     */
    public static function missingReasonProvider(): array
    {
        return [
            'no reason' => [[]],
            'empty reason' => [['refusal_reason' => '']],
            'unknown reason' => [['refusal_reason' => 'full']],
        ];
    }

    /**
     * A refusal without a valid reason changes nothing, from a pending
     * request as from an accepted one, and says why.
     *
     * @param array<string, string> $reason
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('missingReasonProvider')]
    public function testARefusalFromTheFicheWithoutAValidReasonChangesNothing(array $reason): void
    {
        $pendingId = $this->createRequest();
        $acceptedId = $this->createRequest('2020-07-01');
        $this->requestRepository->updateStatus($acceptedId, 'accepted', null);

        foreach ([$pendingId, $acceptedId] as $id) {
            $this->controller->refuse(
                new Request('POST', "/config/inscriptions/demandes/{$id}/refuse", [], $this->csrfBody($reason), [], []),
                ['id' => (string) $id]
            );
            $flash = \Core\Http\FlashMessage::get();
            $this->assertSame('error', $flash['type'] ?? null);
            $this->assertSame(RequestStatusService::MESSAGE_REFUSAL_REASON_REQUIRED, $flash['message'] ?? null);
        }

        $this->assertSame('pending', $this->requestRepository->findById($pendingId)->status);
        $this->assertSame('accepted', $this->requestRepository->findById($acceptedId)->status);
    }

    /**
     * Both « Refuser » forms of the fiche — on a pending and on an accepted
     * request — ask for the reason, and cannot be sent without one.
     */
    public function testBothRefusalFormsOfTheFicheAskForTheReason(): void
    {
        $pendingId = $this->createRequest();
        $acceptedId = $this->createRequest('2020-07-01');
        $this->requestRepository->updateStatus($acceptedId, 'accepted', null);

        foreach ([$pendingId, $acceptedId] as $id) {
            $body = (string) $this->controller->show(
                new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []),
                ['id' => (string) $id]
            )->getBody();
            $found = preg_match(
                '#<form method="post" action="/config/inscriptions/demandes/' . $id . '/refuse".*?</form>#s',
                $body,
                $form
            );
            $this->assertSame(1, $found, "request #{$id} can be refused from its fiche");
            $this->assertStringContainsString('data-confirm="', $form[0]);
            $this->assertMatchesRegularExpression('#<select[^>]+name="refusal_reason"[^>]* required#', $form[0]);
            // No reason chosen in advance: the empty one is selected.
            $this->assertStringContainsString('<option value="" selected>Motif du refus…</option>', $form[0]);
            $this->assertSame(1, substr_count($form[0], ' selected'));
            $this->assertStringContainsString('<option value="no_room">Pas de place</option>', $form[0]);
            $this->assertStringContainsString('<option value="other">Autre</option>', $form[0]);
        }
    }

    /**
     * The note written from the decision dialog (issue #843, IT-04) is the
     * fiche's own internal note — the same field, shown there.
     */
    public function testTheNoteWrittenFromTheDecisionDialogShowsOnTheFiche(): void
    {
        $id = $this->createRequest();
        $decisions = RegistrationTestHelper::decisionService(
            $this->pdo,
            $this->encryption,
            $this->settingService,
            $this->statusService,
            $this->emailService
        );

        $decisions->decide(
            $this->requestRepository->findById($id),
            'accept',
            'Fratrie confirmée par téléphone.',
            null
        );

        $body = (string) $this->controller->show(
            new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []),
            ['id' => (string) $id]
        )->getBody();
        $this->assertMatchesRegularExpression(
            '#<textarea name="internal_notes"[^>]*>Fratrie confirmée par téléphone\.</textarea>#u',
            $body
        );
    }

    public function testIntendedSectionAcceptedWhenInSlotBranch(): void
    {
        $id = $this->createRequest('2020-06-01'); // falls into Baladins (entry age 6)

        $this->controller->saveIntendedSection(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/intended-section", [],
            $this->csrfBody(['intended_section_id' => (string) $this->baladinsSectionId]), [], []
        ), ['id' => (string) $id]);

        $this->assertSame($this->baladinsSectionId, $this->requestRepository->findById($id)->intendedSectionId);
    }

    public function testIntendedSectionRejectedWhenOutsideSlotBranch(): void
    {
        $id = $this->createRequest('2020-06-01'); // Baladins slot

        $this->controller->saveIntendedSection(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/intended-section", [],
            $this->csrfBody(['intended_section_id' => (string) $this->louveteauxSectionId]), [], []
        ), ['id' => (string) $id]);

        $this->assertNull($this->requestRepository->findById($id)->intendedSectionId);
    }

    public function testIntendedSectionNeverAppearsOnTrackingSurface(): void
    {
        // Deliverable 2's own rule: the field exists on the fiche's own
        // template only — the tracking templates never reference it at all.
        $trackingTemplate = file_get_contents(dirname(__DIR__, 4) . '/modules/registration/views/tracking_full.html.twig');
        $this->assertStringNotContainsString('intendedSection', $trackingTemplate);
        $trackingMinimal = file_get_contents(dirname(__DIR__, 4) . '/modules/registration/views/tracking_minimal.html.twig');
        $this->assertStringNotContainsString('intendedSection', $trackingMinimal);
    }

    public function testSaveFeeCategoryUpdatesRequest(): void
    {
        $id = $this->createRequest();
        $feeCategoryRepository = new FeeCategoryRepository($this->pdo);
        $categoryId = $feeCategoryRepository->findAll()[0]['id'];

        $this->controller->saveFeeCategory(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/fee-category", [],
            $this->csrfBody(['fee_category_id' => (string) $categoryId]), [], []
        ), ['id' => (string) $id]);

        $this->assertSame($categoryId, $this->requestRepository->findById($id)->feeCategoryId);
    }

    public function testFeeSuggestionCountsAcceptedRequestsAtSameAddress(): void
    {
        $id1 = $this->createRequest();
        $id2 = $this->requestRepository->create($this->targetYearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => 'Noa',
            'gender' => 'H', 'birth_date' => '2019-06-01', 'street' => 'Rue Test', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'noa@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        $this->requestRepository->updateStatus($id2, 'accepted', null);

        $response = $this->controller->show(new Request('GET', "/config/inscriptions/demandes/{$id1}", [], [], [], []), ['id' => (string) $id1]);

        // id1's own household count must include the OTHER accepted request
        // at the same address, but never count itself.
        $this->assertStringContainsString('1 personne(s)', $response->getBody());
    }

    public function testSaveNotesEncryptsAndNeverJournals(): void
    {
        $id = $this->createRequest();

        $this->controller->saveNotes(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/notes", [],
            $this->csrfBody(['internal_notes' => 'Famille difficile, prévoir un appel avant refus.']), [], []
        ), ['id' => (string) $id]);

        $this->assertSame('Famille difficile, prévoir un appel avant refus.', $this->requestRepository->findById($id)->internalNotes);

        $stmt = $this->pdo->query('SELECT description, context FROM event_log');
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $this->assertStringNotContainsString('Famille difficile', (string) $row['description']);
            $this->assertStringNotContainsString('Famille difficile', (string) $row['context']);
        }
    }

    public function testSendAcceptedEmailDisabledUntilBodyWritten(): void
    {
        $id = $this->createRequest();
        $this->requestRepository->updateStatus($id, 'accepted', null);

        $response = $this->controller->sendAcceptedEmail(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/send-accepted-email", [], $this->csrfBody(), [], []
        ), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNull($this->requestRepository->findById($id)->acceptedEmailSentAt());
    }

    public function testSendAcceptedEmailSucceedsOnceBodyWritten(): void
    {
        $id = $this->createRequest();
        $this->requestRepository->updateStatus($id, 'accepted', null);
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue {{prenom_enfant}} !</p>', 'rich_text', 1);

        $this->controller->sendAcceptedEmail(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/send-accepted-email", [], $this->csrfBody(), [], []
        ), ['id' => (string) $id]);

        $firstSentAt = $this->requestRepository->findById($id)->acceptedEmailSentAt();
        $this->assertNotNull($firstSentAt);

        // Resend works too — a second e-mail of its own, which never moves
        // the date of the first (R3).
        $this->controller->sendAcceptedEmail(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/send-accepted-email", [], $this->csrfBody(), [], []
        ), ['id' => (string) $id]);
        $this->assertEquals($firstSentAt, $this->requestRepository->findById($id)->acceptedEmailSentAt());
        $history = (new DecisionEmailRepository($this->pdo))->findForRequest($id);
        $this->assertSame(['sent', 'sent'], array_map(static fn($email) => $email->state, $history));
        $this->assertSame([false, true], array_map(static fn($email) => $email->isResend, $history));
    }

    /**
     * The fiche shows both buttons whatever the decision. The acceptance
     * e-mail of a refused request is refused — it used to leave, telling
     * a family their child was accepted after the unit had said no — and
     * the chief is told why.
     */
    public function testTheAcceptanceEmailOfARefusedRequestIsRefused(): void
    {
        $id = $this->createRequest();
        $this->requestRepository->updateStatus($id, 'refused', new \DateTimeImmutable());
        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue !</p>', 'rich_text', 1);

        $this->controller->sendAcceptedEmail(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/send-accepted-email", [], $this->csrfBody(), [], []
        ), ['id' => (string) $id]);

        $this->assertSame(
            [
                'type' => 'error',
                'message' => "Cette demande n'est pas acceptée : l'e-mail d'acceptation ne peut pas partir.",
            ],
            \Core\Http\FlashMessage::get()
        );
        $this->assertSame([], (new DecisionEmailRepository($this->pdo))->findForRequest($id));
    }

    /**
     * An e-mail still to write sends the chief to where it is written —
     * « Gabarits » (issue #843, IT-07) — and nowhere else: the site
     * parameters page cannot write it.
     */
    public function testAnUnwrittenDecisionEmailPointsToGabarits(): void
    {
        $id = $this->createRequest();
        $show = fn(): string => (string) $this->controller->show(
            new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []),
            ['id' => (string) $id]
        )->getBody();

        $body = $show();
        $this->assertSame(
            2,
            substr_count($body, "n'a pas encore été rédigé — voir <a href=\"/config/inscriptions/gabarits\">Gabarits</a>.")
        );
        $this->assertStringNotContainsString('href="/config/settings"', $body);

        $this->editableContentService->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);
        $this->editableContentService->set('registration_email_refused_body', '<p>Désolé</p>', 'rich_text', 1);
        $this->assertStringNotContainsString("n'a pas encore été rédigé", $show());
    }

    public function testLinkRejectsUnknownDeskNumber(): void
    {
        $id = $this->createRequest();
        $this->requestRepository->updateStatus($id, 'accepted', null);

        $this->controller->link(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/link", [],
            $this->csrfBody(['desk_id' => 'UNKNOWN']), [], []
        ), ['id' => (string) $id]);

        $this->assertSame('accepted', $this->requestRepository->findById($id)->status);
    }

    public function testLinkRejectsMemberAlreadyLinkedToAnotherRequest(): void
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK1');
        $id1 = $this->createRequest();
        $this->requestRepository->updateStatus($id1, 'accepted', null);
        $this->requestRepository->linkToMemberAndEncode($id1, $memberId, new \DateTimeImmutable());

        $id2 = $this->createRequest();
        $this->requestRepository->updateStatus($id2, 'accepted', null);

        $this->controller->link(new Request(
            'POST', "/config/inscriptions/demandes/{$id2}/link", [],
            $this->csrfBody(['desk_id' => 'DESK1']), [], []
        ), ['id' => (string) $id2]);

        $this->assertSame('accepted', $this->requestRepository->findById($id2)->status);
    }

    public function testLinkWithValidDeskNumberMigratesAndEncodes(): void
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'DESK2');
        $id = $this->createRequest();
        $this->requestRepository->updateStatus($id, 'accepted', null);

        $this->controller->link(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/link", [],
            $this->csrfBody(['desk_id' => 'DESK2']), [], []
        ), ['id' => (string) $id]);

        $updated = $this->requestRepository->findById($id);
        $this->assertSame('encoded', $updated->status);
        $this->assertSame($memberId, $updated->linkedMemberId);
        $this->assertSame(
            ['type' => 'success', 'message' => 'Demande liée au membre Desk et encodée.'],
            \Core\Http\FlashMessage::get()
        );
    }

    /**
     * The fiche is a sub-page of « Demandes » (issue #843, D1): the
     * module's title, its rail with « Demandes » selected, the request's
     * number as the last step of the trail, and the manual link offered as
     * « Lier à un membre Desk » — the word it used to carry is gone.
     */
    public function testTheFicheIsASubPageOfTheRequests(): void
    {
        $id = $this->createRequest();
        $this->requestRepository->updateStatus($id, 'accepted', null);

        $body = (string) $this->controller->show(
            new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []),
            ['id' => (string) $id]
        )->getBody();

        $this->assertMatchesRegularExpression('/<h1[^>]*>\s*Formulaire d&#039;inscription\s*</', $body);
        $this->assertMatchesRegularExpression(
            '#<a href="/config/inscriptions/demandes"[^>]*aria-current="page"[^>]*>\s*<span>Demandes</span>#',
            $body
        );
        $this->assertStringContainsString('Léa Dupont — demande #' . $id, $body);
        $this->assertStringContainsString("<title>Demande #{$id} — Formulaire d'inscription", $body);
        $this->assertStringContainsString('Lier à un membre Desk', $body);
        $this->assertStringNotContainsString('rapproch', mb_strtolower($body));
    }
}
