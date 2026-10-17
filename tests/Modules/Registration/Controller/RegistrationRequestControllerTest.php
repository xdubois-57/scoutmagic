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
use Modules\Registration\Controller\RequestDecisionEmailController;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\RegistrationSecondaryEmailRepository;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\FamilyEmailCopyService;
use Modules\Registration\Service\HouseholdRegistrationCountService;
use Modules\Registration\Service\MigrationService;
use Modules\Registration\Service\RequestDecisionService;
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
 * requests, internal notes saved without ever being journaled, and
 * manual linking (invalid/duplicate Desk numbers refused, a valid one
 * migrating through Service\MigrationService — the same path Service\
 * ReconciliationService uses).
 *
 * And the fiche's « E-mail de décision » block (issue #843, IT-08): every
 * state it renders, its three buttons through Controller\
 * RequestDecisionEmailController (« Envoyer maintenant », « Réessayer »,
 * « Renvoyer l'e-mail », the copy to the unit included), the independent
 * « Note interne », and the confirmation after a decision taken here.
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
    private RequestDecisionEmailController $emailController;
    private DecisionEmailService $decisionEmails;

    /**
     * Every e-mail the doubled MailService was handed: its recipient and
     * its copy.
     *
     * @var list<array{to: string, cc: mixed}>
     */
    private array $sentMails = [];

    /** How many of the next sends the doubled transport refuses. */
    private int $mailFailures = 0;

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

        // The doubled transport records what it is handed — the copy read
        // by the parameter's name — and refuses the first $mailFailures.
        $ccIndex = null;
        foreach ((new \ReflectionMethod(MailService::class, 'send'))->getParameters() as $parameter) {
            if ($parameter->getName() === 'cc') {
                $ccIndex = $parameter->getPosition();
            }
        }
        $this->assertNotNull($ccIndex, 'MailService::send() takes a cc.');
        $mailService = $this->createStub(MailService::class);
        $mailService->method('send')->willReturnCallback(function (...$arguments) use ($ccIndex): void {
            if ($this->mailFailures > 0) {
                $this->mailFailures--;
                throw new \Core\Mail\MailException('SMTP Error: Could not connect to smtp.example.com.');
            }
            $this->sentMails[] = ['to' => (string) $arguments[0], 'cc' => $arguments[$ccIndex] ?? null];
        });
        $this->editableContentService = new EditableContentService(new EditableContentRepository($this->pdo));
        $module = 'registration';
        $settingService->register(FamilyEmailCopyService::SETTING_ENABLED, '1', 'boolean', 'Copie', 'desc', $module);
        $settingService->register(FamilyEmailCopyService::SETTING_ADDRESS, '', 'email', 'Adresse', 'desc', $module);
        $settingService->register(
            FamilyEmailCopyService::SETTING_UNIT_ADDRESS,
            'unite@example.com',
            'email',
            'Contact',
            'desc'
        );
        $this->decisionEmails = new DecisionEmailService(new DecisionEmailRepository($this->pdo), $journalService);
        $emailService = new RequestEmailService(
            $this->requestRepository,
            $mailService,
            $this->editableContentService,
            $journalService,
            'https://example.com',
            'Unité Test',
            $this->decisionEmails,
            new FamilyEmailCopyService($settingService, $journalService)
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

        $decisionService = RegistrationTestHelper::decisionService(
            $this->pdo,
            $encryption,
            $settingService,
            $statusService,
            $emailService,
            $this->requestRepository
        );
        $this->controller = new RegistrationRequestController(
            $twig, $this->requestRepository, $ageBracketRepository, $sectionService, $feeCategoryRepository,
            $feeEstimationService, $statusService, $emailService, $migrationService, $memberRepository,
            $memberYearRepository, $scoutYearResolver, $scoutYearService, $slotService, $memberService,
            $decisionService
        );
        $this->emailController = new RequestDecisionEmailController(
            $twig,
            $this->requestRepository,
            $emailService,
            $scoutYearService
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
        // « Créneau », named as the mock-up names it (IT-11).
        $this->assertMatchesRegularExpression(
            '#>Créneau</span><br>Baladins · \d(re|e) année\s*<#u',
            (string) $response->getBody()
        );
    }

    /** A child born outside every age bracket: « Hors des tranches d'âge ». */
    public function testTheFicheNamesARequestOutsideTheBrackets(): void
    {
        $id = $this->createRequest('1990-01-01');

        $body = (string) $this->controller->show(
            new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []),
            ['id' => (string) $id]
        )->getBody();

        $this->assertStringContainsString('>Créneau</span><br>Hors des tranches d&#039;âge', $body);
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
        // Named as the request's own « Créneau » is (IT-11).
        $this->assertStringContainsString('Louveteaux · 2e année', $body);
        $this->assertStringNotContainsString('ᵉ année', $body);
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

    private function show(int $id): string
    {
        return (string) $this->controller->show(
            new Request('GET', "/config/inscriptions/demandes/{$id}", [], [], [], []),
            ['id' => (string) $id]
        )->getBody();
    }

    /**
     * One of the block's three buttons, as the fiche posts it.
     */
    private function emailAction(int $id, string $action, array $body = []): \Core\Http\Response
    {
        $paths = ['sendNow' => 'envoyer', 'retry' => 'reessayer', 'resend' => 'renvoyer'];

        return $this->emailController->$action(
            new Request(
                'POST',
                "/config/inscriptions/demandes/{$id}/e-mail/{$paths[$action]}",
                [],
                $body === [] ? $this->csrfBody() : $body,
                [],
                []
            ),
            ['id' => (string) $id]
        );
    }

    /**
     * The « E-mail de décision » block alone, out of a rendered fiche.
     */
    private function emailBlock(int $id): string
    {
        $body = $this->show($id);
        $found = preg_match(
            '#<h2 class="h5 mb-3">E-mail de décision</h2>(.*?)</div>\s*</div>\s*(?:\{|<div|$)#s',
            $body,
            $m
        );
        $this->assertSame(1, $found, 'the fiche renders the « E-mail de décision » block');

        return $m[1];
    }

    private function writeBodies(): void
    {
        $content = $this->editableContentService;
        $content->set('registration_email_accepted_body', '<p>Bienvenue {{prenom_enfant}} !</p>', 'rich_text', 1);
        $content->set('registration_email_refused_body', '<p>Désolés.</p>', 'rich_text', 1);
    }

    private function acceptedRequest(): int
    {
        $id = $this->createRequest();
        $this->requestRepository->updateStatus($id, 'accepted', null);

        return $id;
    }

    /**
     * @return array<int, string>
     */
    private function states(int $id): array
    {
        return array_map(
            static fn(DecisionEmail $email): string => $email->state,
            (new DecisionEmailRepository($this->pdo))->findForRequest($id)
        );
    }

    /**
     * @return array<int, string> every journal entry's type, in order
     */
    private function eventTypes(): array
    {
        return array_map(
            'strval',
            $this->pdo->query('SELECT event_type FROM event_log ORDER BY id')->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * A pending request holds no decision, so there is no e-mail to speak
     * of: no block at all, like the mock-up's.
     */
    public function testAPendingRequestHasNoDecisionEmailBlock(): void
    {
        $id = $this->createRequest();
        $this->writeBodies();

        $body = $this->show($id);

        $this->assertStringNotContainsString('E-mail de décision', $body);
        $this->assertStringNotContainsString('/e-mail/', $body);
    }

    /**
     * « Gabarit à rédiger »: nothing can leave, no button — the chief is
     * sent to « Gabarits », where the e-mail is written, and nowhere else.
     */
    public function testAnUnwrittenTemplateShowsGabaritARedigerAndNoButton(): void
    {
        $id = $this->acceptedRequest();

        $block = $this->emailBlock($id);

        $this->assertStringContainsString('E-mail d&#039;acceptation', $block);
        $this->assertStringContainsString('<span class="badge text-bg-danger">Gabarit à rédiger</span>', $block);
        $this->assertStringContainsString(
            "Le corps de l'e-mail n'a pas encore été rédigé : rien ne peut partir. Rédigez-le dans "
                . '<a href="/config/inscriptions/gabarits">Gabarits</a>.',
            $block
        );
        $this->assertStringNotContainsString('<form', $block);
        $this->assertStringNotContainsString('href="/config/settings"', $this->show($id));
    }

    /**
     * « Non planifié »: the decision is taken, nothing is planned, and
     * « Envoyer maintenant » asks before it sends (design.md §7.5).
     */
    public function testNoEmailYetShowsNonPlanifieAndEnvoyerMaintenant(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();

        $block = $this->emailBlock($id);

        $this->assertStringContainsString('<span class="badge text-bg-secondary">Non planifié</span>', $block);
        $this->assertStringContainsString("L'envoi automatique est désactivé : l'e-mail s'envoie à la main.", $block);
        $this->assertMatchesRegularExpression(
            '#<form method="post" action="/config/inscriptions/demandes/' . $id . '/e-mail/envoyer"\s+'
                . 'data-confirm="Envoyer l\'e-mail d&\#039;acceptation à la famille de Léa Dupont \? '
                . 'Il part immédiatement et ne peut pas être rappelé\."#',
            $block
        );
        $this->assertStringContainsString('Envoyer maintenant</button>', $block);
        $this->assertStringNotContainsString('Réessayer', $block);
        $this->assertStringNotContainsString('Renvoyer', $block);
        $this->assertStringNotContainsString('Historique', $block);
    }

    /**
     * « Prévu le … » — what the automatic sending (IT-09) plans: the date
     * it is due, « Envoyer maintenant », and the planning in the history.
     */
    public function testAPlannedEmailShowsPrevuLeAndItsPlanning(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService);
        $this->decisionEmails->schedule(
            $this->requestRepository->findById($id),
            new \DateTimeImmutable('2027-03-04 09:30:00')
        );

        $block = $this->emailBlock($id);

        $this->assertStringContainsString(
            '<span class="badge text-bg-warning">Prévu le 04/03/2027 à 09:30</span>',
            $block
        );
        $this->assertStringContainsString(
            "La famille voit encore « en attente ». Changer la décision d'ici là annule cet envoi.",
            $block
        );
        $this->assertStringNotContainsString('désormais désactivé', $block);
        $this->assertStringContainsString('/e-mail/envoyer"', $block);
        $this->assertStringContainsString('Planifié</span>', $block);
        $this->assertStringContainsString('pour le 04/03/2027 à 09:30', $block);
    }

    /**
     * « Envoyé le … »: the family sees the decision, « Renvoyer l'e-mail »
     * is the only button, and the send is in the history.
     */
    public function testASentEmailShowsEnvoyeLeAndRenvoyer(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        $this->emailAction($id, 'sendNow');

        $block = $this->emailBlock($id);

        $this->assertMatchesRegularExpression(
            '#<span class="badge text-bg-success">Envoyé le \d{2}/\d{2}/\d{4} à \d{2}:\d{2}</span>#',
            $block
        );
        $this->assertStringContainsString('La famille voit la décision.', $block);
        $this->assertStringContainsString('/e-mail/renvoyer"', $block);
        $this->assertStringContainsString("Renvoyer l'e-mail</button>", $block);
        $this->assertStringNotContainsString('/e-mail/envoyer"', $block);
        $this->assertStringContainsString('Envoyé</span>', $block);
    }

    /**
     * « Échec le … »: « Réessayer », and the history keeps the sentence
     * written for a chief — never the transport's own text, which names
     * the relay.
     */
    public function testAFailedEmailShowsEchecLeAndReessayerWithoutTheTechnicalError(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        $this->mailFailures = 1;
        $this->emailAction($id, 'sendNow');

        $block = $this->emailBlock($id);

        $this->assertMatchesRegularExpression(
            '#<span class="badge text-bg-danger">Échec le \d{2}/\d{2}/\d{4} à \d{2}:\d{2}</span>#',
            $block
        );
        $this->assertStringContainsString('La famille voit toujours « en attente ».', $block);
        $this->assertStringContainsString('/e-mail/reessayer"', $block);
        $this->assertStringNotContainsString('/e-mail/envoyer"', $block);
        $this->assertStringContainsString('Échec</span>', $block);
        $this->assertStringContainsString('n&#039;a pas pu être envoyé à la famille', $block);
        $this->assertStringNotContainsString('smtp.example.com', $this->show($id));
        $this->assertStringNotContainsString('SMTP', $this->show($id));
    }

    /**
     * « Annulé suite à un changement de décision »: the acceptance planned
     * for a request refused since will not leave; the refusal is sent by
     * hand, and the history says what became of the acceptance.
     */
    public function testAnEmailCancelledByAChangeOfDecisionShowsAnnule(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        $this->decisionEmails->schedule(
            $this->requestRepository->findById($id),
            new \DateTimeImmutable('+1 day')
        );
        $this->statusService->refuse($this->requestRepository->findById($id), 'other');

        $block = $this->emailBlock($id);

        $this->assertStringContainsString('E-mail de refus', $block);
        $this->assertStringContainsString(
            '<span class="badge text-bg-warning">Annulé suite à un changement de décision</span>',
            $block
        );
        $this->assertStringContainsString('/e-mail/envoyer"', $block);
        $this->assertStringContainsString('Annulé</span>', $block);
        $this->assertStringContainsString("e-mail d&#039;acceptation · décision changée avant l&#039;envoi", $block);
    }

    /**
     * « Envoi en cours »: a send claimed moments ago offers no button —
     * a click now would only meet the claim.
     */
    public function testASendInFlightShowsEnvoiEnCoursAndNoButton(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        $this->decisionEmails->begin($this->requestRepository->findById($id), DecisionEmail::DECISION_ACCEPTED);

        $block = $this->emailBlock($id);

        $this->assertStringContainsString('<span class="badge text-bg-warning">Envoi en cours</span>', $block);
        $this->assertStringNotContainsString('<form', $block);
    }

    /**
     * A send claimed long ago and never reported back: « Envoi interrompu »,
     * « Réessayer », and the same word in the history — never « Envoi en
     * cours » for ever.
     */
    public function testASendClaimedLongAgoShowsEnvoiInterrompuAndReessayer(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        (new DecisionEmailRepository($this->pdo))->insert(
            $id,
            DecisionEmail::DECISION_ACCEPTED,
            DecisionEmail::STATE_SENDING,
            null,
            1,
            false,
            new \DateTimeImmutable('-1 hour')
        );

        $block = $this->emailBlock($id);

        $this->assertStringContainsString('<span class="badge text-bg-danger">Envoi interrompu</span>', $block);
        $this->assertStringContainsString("il n'est pas certain que la famille ait reçu cet e-mail", $block);
        $this->assertStringContainsString('/e-mail/reessayer"', $block);
        $this->assertStringContainsString('Interrompu</span>', $block);
        $this->assertStringNotContainsString('Envoi en cours', $block);

        $this->emailAction($id, 'retry');

        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame(['failed', 'sent'], $this->states($id));
    }

    /**
     * « Envoyer maintenant » sends the e-mail of the current decision once,
     * in copy to the unit, and says so; a second one posted afterwards —
     * a late double click, a form sent again — is refused, never turned
     * into a resend.
     */
    public function testSendNowSendsOnceAndASecondSendNowIsRefused(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();

        $response = $this->emailAction($id, 'sendNow');

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame("/config/inscriptions/demandes/{$id}", $response->getHeaders()['Location'] ?? null);
        $this->assertSame(
            [
                'type' => 'success',
                'message' => "L'e-mail d'acceptation est parti : la famille voit maintenant la décision.",
            ],
            \Core\Http\FlashMessage::get()
        );
        $this->assertSame([['to' => 'marie@example.com', 'cc' => 'unite@example.com']], $this->sentMails);
        $this->assertSame(['sent'], $this->states($id));

        $this->emailAction($id, 'sendNow');

        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertCount(1, $this->sentMails);
        $this->assertSame(['sent'], $this->states($id));
    }

    /**
     * « Envoyer maintenant » on a planned e-mail sends THAT e-mail — the
     * row is claimed, not doubled.
     */
    public function testSendNowTakesThePlannedEmail(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        $this->decisionEmails->schedule(
            $this->requestRepository->findById($id),
            new \DateTimeImmutable('+1 day')
        );

        $this->emailAction($id, 'sendNow');

        $this->assertCount(1, $this->sentMails);
        $this->assertSame(['sent'], $this->states($id));
    }

    /**
     * « Réessayer » after a failure: a new attempt of its own, the failure
     * kept in the history. Without a failure, there is nothing to retry.
     */
    public function testRetryAfterAFailureIsANewAttemptAndNothingToRetryOtherwise(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();

        $this->emailAction($id, 'retry');
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->states($id));

        $this->mailFailures = 1;
        $this->emailAction($id, 'sendNow');
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame(['failed'], $this->states($id));

        $this->emailAction($id, 'retry');

        $this->assertSame('success', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame(['failed', 'sent'], $this->states($id));
        $this->assertSame([['to' => 'marie@example.com', 'cc' => 'unite@example.com']], $this->sentMails);
    }

    /**
     * « Renvoyer l'e-mail » after a success: a second e-mail, in copy to
     * the unit too (D9, « renvois compris »), a row of its own marked as a
     * resend, and the first send's date — what the family's view reads
     * (R3) — never moved. Before any success there is nothing to resend.
     */
    public function testResendAfterASuccessCarriesTheCopyAndNeverMovesTheFirstSend(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();

        $this->emailAction($id, 'resend');
        $this->assertSame('error', \Core\Http\FlashMessage::get()['type'] ?? null);
        $this->assertSame([], $this->sentMails);

        $this->emailAction($id, 'sendNow');
        $firstSentAt = $this->requestRepository->findById($id)->decisionEmailSentAt;
        $this->assertNotNull($firstSentAt);

        $this->emailAction($id, 'resend');

        $this->assertSame(
            ['type' => 'success', 'message' => "L'e-mail d'acceptation a été renvoyé à la famille."],
            \Core\Http\FlashMessage::get()
        );
        $this->assertSame(
            [
                ['to' => 'marie@example.com', 'cc' => 'unite@example.com'],
                ['to' => 'marie@example.com', 'cc' => 'unite@example.com'],
            ],
            $this->sentMails
        );
        $history = (new DecisionEmailRepository($this->pdo))->findForRequest($id);
        $this->assertSame([false, true], array_map(static fn(DecisionEmail $email) => $email->isResend, $history));
        $this->assertEquals($firstSentAt, $this->requestRepository->findById($id)->decisionEmailSentAt);
        $this->assertStringContainsString('renvoi', $this->emailBlock($id));
    }

    /**
     * The refusal e-mail goes through the same buttons, for a refused
     * request — and only its own e-mail: the block of a refused request is
     * about the refusal.
     */
    public function testARefusedRequestSendsItsRefusalEmail(): void
    {
        $id = $this->createRequest();
        $this->writeBodies();
        $this->statusService->refuse($this->requestRepository->findById($id), 'no_room');

        $this->assertStringContainsString('E-mail de refus', $this->emailBlock($id));
        $this->emailAction($id, 'sendNow');

        $this->assertSame(
            ['type' => 'success', 'message' => "L'e-mail de refus est parti : la famille voit maintenant la décision."],
            \Core\Http\FlashMessage::get()
        );
        $history = (new DecisionEmailRepository($this->pdo))->findForRequest($id);
        $this->assertSame(['refused'], array_map(static fn(DecisionEmail $email) => $email->decision, $history));
    }

    /**
     * Every button is journaled — the request, the decision and the action,
     * never the family's address.
     */
    public function testEachEmailActionIsJournaledWithoutTheFamily(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        $this->mailFailures = 1;

        $this->emailAction($id, 'sendNow');
        $this->emailAction($id, 'retry');
        $this->emailAction($id, 'resend');

        $rows = $this->pdo->query(
            "SELECT context FROM event_log WHERE event_type = 'registration_decision_email_requested' ORDER BY id"
        )->fetchAll(\PDO::FETCH_COLUMN);
        $actions = array_map(static fn($context) => json_decode((string) $context, true)['action'] ?? null, $rows);
        $this->assertSame(['send_now', 'retry', 'resend'], $actions);
        foreach ($rows as $context) {
            $this->assertSame($id, json_decode((string) $context, true)['request_id'] ?? null);
            $this->assertStringNotContainsString('marie@example.com', (string) $context);
        }
    }

    /**
     * An expired token, or a request that does not exist, sends nothing.
     */
    public function testAnEmailActionWithAnInvalidTokenOrAnUnknownRequestSendsNothing(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();

        foreach (['sendNow', 'retry', 'resend'] as $action) {
            $this->emailAction($id, $action, ['_csrf_token' => 'bad']);
        }
        $missing = $this->emailController->sendNow(
            new Request('POST', '/config/inscriptions/demandes/999/e-mail/envoyer', [], $this->csrfBody(), [], []),
            ['id' => '999']
        );

        $this->assertSame(404, $missing->getStatusCode());
        $this->assertSame([], $this->sentMails);
        $this->assertSame([], $this->states($id));
    }

    /**
     * « Note interne » is saved on its own: no e-mail, no status change, no
     * decision e-mail touched — not even one planned — and only the fact
     * of the save in the journal.
     */
    public function testSavingTheNoteSendsNothingAndChangesNoStatus(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        $this->decisionEmails->schedule(
            $this->requestRepository->findById($id),
            new \DateTimeImmutable('+1 day')
        );

        $eventsBefore = $this->eventTypes();

        $response = $this->controller->saveNotes(new Request(
            'POST', "/config/inscriptions/demandes/{$id}/notes", [],
            $this->csrfBody(['internal_notes' => "Rappeler la famille.\r\nAvant samedi."]), [], []
        ), ['id' => (string) $id]);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(['type' => 'success', 'message' => 'Note enregistrée.'], \Core\Http\FlashMessage::get());
        $request = $this->requestRepository->findById($id);
        $this->assertSame("Rappeler la famille.\nAvant samedi.", $request->internalNotes);
        $this->assertSame('accepted', $request->status);
        $this->assertNull($request->decisionEmailSentAt);
        $this->assertSame([], $this->sentMails);
        $this->assertSame(['scheduled'], $this->states($id));
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM event_log WHERE event_type = 'registration_request_note_saved'"
            )->fetchColumn()
        );
        $this->assertSame(
            ['registration_request_note_saved'],
            array_slice($this->eventTypes(), count($eventsBefore)),
            'the save journals that a note was saved, and nothing else happened'
        );
    }

    /**
     * The note box of the mock-up: « Enregistrer la note », and the promise
     * that it never sends anything.
     */
    public function testTheNoteHasItsOwnFormAndSaysItSendsNothing(): void
    {
        $id = $this->acceptedRequest();

        $body = $this->show($id);

        $found = preg_match(
            '#<form method="post" action="/config/inscriptions/demandes/' . $id . '/notes">.*?</form>#s',
            $body,
            $form
        );
        $this->assertSame(1, $found);
        $this->assertStringContainsString('<h2 class="h5 mb-3">Note interne</h2>', $body);
        $this->assertStringContainsString('placeholder="Visible par le Staff d\'U seulement"', $form[0]);
        $this->assertStringContainsString('Enregistrer la note</button>', $form[0]);
        $this->assertStringContainsString("Enregistrer la note n'envoie jamais d'e-mail.", $form[0]);
        $this->assertStringNotContainsString('/e-mail/', $form[0]);
    }

    /**
     * A decision taken on the fiche answers « un e-mail va-t-il partir ? »:
     * no, send it from the block below — or no, its template is not
     * written yet.
     */
    public function testADecisionOnTheFicheSaysWhetherAnEmailLeaves(): void
    {
        $id = $this->createRequest();

        $this->controller->accept(
            new Request('POST', "/config/inscriptions/demandes/{$id}/accept", [], $this->csrfBody(), [], []),
            ['id' => (string) $id]
        );
        $this->assertSame(
            [
                'type' => 'success',
                'message' => "Demande acceptée. Aucun e-mail n'est parti : le gabarit d'acceptation n'est pas encore "
                    . "rédigé (voir Gabarits). La famille voit « en attente » jusqu'à son envoi.",
            ],
            \Core\Http\FlashMessage::get()
        );

        $this->writeBodies();
        $this->controller->refuse(
            new Request('POST', "/config/inscriptions/demandes/{$id}/refuse", [], $this->csrfBody([
                'refusal_reason' => 'no_room',
            ]), [], []),
            ['id' => (string) $id]
        );
        $this->assertSame(
            [
                'type' => 'success',
                'message' => "Demande refusée (Pas de place). Aucun e-mail n'est parti : envoyez-le avec « Envoyer "
                    . "maintenant », ci-dessous. La famille voit « en attente » jusque-là.",
            ],
            \Core\Http\FlashMessage::get()
        );
        $this->assertSame([], $this->sentMails);
    }

    /**
     * R3, still true with the block: a decision taken on the fiche stays
     * « en attente » for the family until its e-mail has really left — not
     * after the decision, not after a failed send — and shows once it has.
     */
    public function testTheFamilySeesPendingUntilTheEmailReallyLeaves(): void
    {
        $id = $this->createRequest();
        $this->writeBodies();
        $visible = fn(): string => $this->statusService->visibleStatus($this->requestRepository->findById($id));

        $this->controller->accept(
            new Request('POST', "/config/inscriptions/demandes/{$id}/accept", [], $this->csrfBody(), [], []),
            ['id' => (string) $id]
        );
        $this->assertSame('pending', $visible());

        $this->mailFailures = 1;
        $this->emailAction($id, 'sendNow');
        $this->assertSame('pending', $visible());

        $this->emailAction($id, 'retry');
        $this->assertSame('accepted', $visible());
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

    // ── The automatic sending (issue #843, IT-09) ───────────────────────

    /**
     * Switching the automatic sending off cancels nothing: an e-mail
     * planned before still shows « Prévu le … », the block says it will
     * leave all the same, and « Envoyer maintenant » is offered.
     */
    public function testAPlannedEmailAfterTheSwitchWasTurnedOffSaysItStillLeaves(): void
    {
        $id = $this->createRequest();
        $this->writeBodies();
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService);
        $this->controller->accept(
            new Request('POST', "/config/inscriptions/demandes/{$id}/accept", [], $this->csrfBody(), [], []),
            ['id' => (string) $id]
        );
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24, false);

        $block = $this->emailBlock($id);

        $this->assertSame(['scheduled'], $this->states($id));
        $this->assertMatchesRegularExpression('#<span class="badge text-bg-warning">Prévu le [^<]+</span>#', $block);
        $this->assertStringContainsString(
            "L'envoi automatique est désormais désactivé, mais cet e-mail, planifié avant, partira quand même",
            $block
        );
        $this->assertStringContainsString('Envoyer maintenant</button>', $block);
        $this->assertStringContainsString('/e-mail/envoyer"', $block);

        // And « Envoyer maintenant » sends that very e-mail, once.
        $this->emailAction($id, 'sendNow');
        $this->assertCount(1, $this->sentMails);
        $this->assertSame(['sent'], $this->states($id));
    }

    /**
     * With the automatic sending on, a decision taken on the fiche is
     * planned for now + the delay, and the confirmation says when it
     * leaves; changing the decision before then cancels it and plans the
     * new one with a FULL delay again.
     */
    public function testADecisionOnTheFicheIsPlannedAndAChangeReplansWithAFullDelay(): void
    {
        $id = $this->createRequest();
        $this->writeBodies();
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);

        $before = new \DateTimeImmutable('+24 hours');
        $this->controller->accept(
            new Request('POST', "/config/inscriptions/demandes/{$id}/accept", [], $this->csrfBody(), [], []),
            ['id' => (string) $id]
        );
        $flash = \Core\Http\FlashMessage::get();
        $this->assertSame('success', $flash['type'] ?? null);
        $this->assertMatchesRegularExpression(
            "#^Demande acceptée\. L'e-mail d'acceptation partira le \d{2}/\d{2}/\d{4} à \d{2}:\d{2} ; d'ici là, "
                . 'vous pouvez changer la décision sans que rien ne soit envoyé\. La famille voit « en attente » '
                . 'jusque-là\.$#',
            $flash['message'] ?? ''
        );
        $accepted = (new DecisionEmailRepository($this->pdo))->findForRequest($id);
        $this->assertCount(1, $accepted);
        $this->assertSame('scheduled', $accepted[0]->state);
        $this->assertGreaterThanOrEqual($before->getTimestamp() - 1, $accepted[0]->dueAt?->getTimestamp());
        $this->assertLessThanOrEqual(
            (new \DateTimeImmutable('+24 hours'))->getTimestamp(),
            $accepted[0]->dueAt?->getTimestamp()
        );

        // A change of decision an hour later — simulated by moving the
        // first planning back — still restarts the full 24 hours.
        $this->pdo->exec("UPDATE registration_decision_emails SET created_at = datetime(created_at, '-1 hour'), "
            . "due_at = datetime(due_at, '-1 hour')");
        $this->controller->refuse(
            new Request('POST', "/config/inscriptions/demandes/{$id}/refuse", [], $this->csrfBody([
                'refusal_reason' => 'other',
            ]), [], []),
            ['id' => (string) $id]
        );

        $rows = (new DecisionEmailRepository($this->pdo))->findForRequest($id);
        $this->assertSame(
            ['cancelled', 'scheduled'],
            array_map(static fn(DecisionEmail $e): string => $e->state, $rows)
        );
        $this->assertSame('refused', $rows[1]->decision);
        $this->assertGreaterThanOrEqual(
            (new \DateTimeImmutable('+24 hours'))->getTimestamp() - 5,
            $rows[1]->dueAt?->getTimestamp()
        );
        $this->assertSame([], $this->sentMails);

        // The block shows the new planning, the cancellation in the history.
        $block = $this->emailBlock($id);
        $this->assertStringContainsString('E-mail de refus', $block);
        $this->assertMatchesRegularExpression('#Prévu le [^<]+</span>#', $block);
        $this->assertStringContainsString('Annulé</span>', $block);
    }

    /**
     * A decision taken while the automatic sending was off is not planned
     * when it is switched on afterwards — the block says no e-mail is
     * planned for it, and only a manual send makes it leave.
     */
    public function testADecisionTakenBeforeTheActivationStaysUnplanned(): void
    {
        $id = $this->createRequest();
        $this->writeBodies();
        $this->controller->accept(
            new Request('POST', "/config/inscriptions/demandes/{$id}/accept", [], $this->csrfBody(), [], []),
            ['id' => (string) $id]
        );
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);

        $block = $this->emailBlock($id);

        $this->assertSame([], $this->states($id));
        $this->assertStringContainsString('Non planifié</span>', $block);
        $this->assertStringContainsString(
            "Aucun envoi n'est prévu pour cette décision : seules les décisions prises depuis l'activation de",
            $block
        );
        $this->assertStringNotContainsString("L'envoi automatique est désactivé", $block);
        $this->assertStringContainsString('Envoyer maintenant</button>', $block);
    }

    /**
     * « Annulé suite à un changement de décision » with the automatic
     * sending on: the new decision was taken before the activation, so
     * the block does not promise an e-mail nobody planned.
     */
    public function testACancelledEmailWithTheAutomaticSendingOnSaysTheNewDecisionIsNotPlanned(): void
    {
        $id = $this->acceptedRequest();
        $this->writeBodies();
        $this->decisionEmails->schedule($this->requestRepository->findById($id), new \DateTimeImmutable('+1 day'));
        $this->statusService->refuse($this->requestRepository->findById($id), 'other');
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settingService, 24);

        $block = $this->emailBlock($id);

        $this->assertStringContainsString('Annulé suite à un changement de décision</span>', $block);
        $this->assertStringContainsString("aucun envoi n'est prévu pour la", $block);
        $this->assertStringNotContainsString("Celui de la nouvelle décision s'envoie à la main ; d'ici là", $block);
        $this->assertStringContainsString('Envoyer maintenant</button>', $block);
    }
}
