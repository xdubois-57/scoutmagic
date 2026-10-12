<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Security\EncryptionService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\AutomaticDecisionEmailService;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\RegistrationException;
use Modules\Registration\Service\RequestEmailService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * « Envoyer automatiquement les e-mails de décision » (chantier #843, D7.1
 * / IT-09): the switch and the delay of the « Décisions automatiques »
 * sub-box — their defaults, what saving accepts, and the planning they
 * drive.
 */
#[Group('database')]
class AutomaticDecisionEmailServiceTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private EditableContentService $content;
    private RegistrationRequestRepository $requests;
    private DecisionEmailService $decisionEmails;
    private AutomaticDecisionEmailService $service;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        // From the manifest, as production registers them: the defaults
        // under test are module.json's own.
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settings);

        $journal = new JournalService(new JournalRepository($this->pdo));
        $repository = new DecisionEmailRepository($this->pdo);
        $this->requests = new RegistrationRequestRepository($this->pdo, $encryption, null, $repository);
        $this->content = new EditableContentService(new EditableContentRepository($this->pdo));
        $this->decisionEmails = new DecisionEmailService($repository, $journal);
        $emailService = new RequestEmailService(
            $this->requests,
            $this->createStub(MailService::class),
            $this->content,
            $journal,
            'https://example.com',
            'Unité Test',
            $this->decisionEmails
        );
        $this->service = new AutomaticDecisionEmailService($this->settings, $emailService, $this->decisionEmails);
        $this->yearId = RegistrationTestHelper::insertScoutYear($this->pdo, '2027-2028', '2027-09-01', '2028-08-31');
    }

    public function testOffByDefaultWithADelayOfTwentyFourHoursAndNeitherSettingOnTheParametersPage(): void
    {
        $this->assertFalse($this->service->isEnabled());
        $this->assertSame(24, $this->service->delayHours());
        $this->assertSame(
            ['enabled' => false, 'delay_hours' => 24, 'templates_ready' => false, 'can_enable' => false,
                'min_delay' => 1, 'max_delay' => 720],
            $this->service->boxState()
        );

        $editable = $this->pdo->query(
            "SELECT setting_key, editable FROM settings WHERE setting_key IN
                ('registration_decision_email_auto_send', 'registration_decision_email_delay_hours')
             ORDER BY setting_key"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(
            ['registration_decision_email_auto_send' => 0, 'registration_decision_email_delay_hours' => 0],
            array_map('intval', $editable)
        );
    }

    /**
     * D7: impossible to switch on while the acceptance or the refusal
     * template is « À rédiger » — the one definition of IT-07, an emptied
     * paragraph included — and nothing is saved, the delay neither.
     */
    public function testSwitchingOnIsRefusedWhileADecisionTemplateIsToWrite(): void
    {
        $this->content->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);
        $this->content->set('registration_email_refused_body', '<p>&nbsp;</p>', 'rich_text', 1);

        try {
            $this->service->save(true, '12');
            $this->fail('Switching on without the refusal template must be refused.');
        } catch (RegistrationException $e) {
            $this->assertSame(AutomaticDecisionEmailService::MESSAGE_TEMPLATES_MISSING, $e->getMessage());
        }

        $this->assertFalse($this->service->isEnabled());
        $this->assertSame(24, $this->service->delayHours());
    }

    /**
     * Switching ON is what the templates condition: an active switch whose
     * template went missing since may still be saved — to change its delay
     * — and nothing is then planned for that decision.
     */
    public function testAnActiveSwitchCanBeKeptOnWhenATemplateWentMissingSince(): void
    {
        $this->writeTemplates();
        $this->service->save(true, '24');
        $this->content->set('registration_email_refused_body', '<p>&nbsp;</p>', 'rich_text', 1);

        $this->service->save(true, '48');

        $this->assertTrue($this->service->isEnabled());
        $this->assertSame(48, $this->service->delayHours());
        $this->assertNull($this->service->plannedDueAtIfDecidedNow(DecisionEmail::DECISION_REFUSED));
        $this->assertNotNull($this->service->plannedDueAtIfDecidedNow(DecisionEmail::DECISION_ACCEPTED));
        $this->assertTrue($this->service->boxState()['can_enable']);
    }

    public function testSwitchingOnWithBothTemplatesSavesTheSwitchAndTheDelayAndSaysWhatLeaves(): void
    {
        $this->writeTemplates();

        $message = $this->service->save(true, ' 12 ');

        $this->assertTrue($this->service->isEnabled());
        $this->assertSame(12, $this->service->delayHours());
        $this->assertSame(
            "Réglages enregistrés. Les e-mails d'acceptation et de refus partiront 12 h après chaque décision. "
                . "Tant qu'ils ne sont pas partis, vous pouvez changer la décision et rien n'est envoyé. Seules "
                . 'les décisions prises à partir de maintenant sont concernées : une décision déjà prise sans envoi '
                . 'reste à envoyer à la main depuis sa fiche.',
            $message
        );
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidDelays(): array
    {
        return [
            'zero' => ['0'],
            'negative' => ['-1'],
            'decimal comma' => ['24,5'],
            'decimal point' => ['1.5'],
            'beyond thirty days' => ['721'],
            'empty' => [''],
            'words' => ['un jour'],
            'exponent' => ['1e3'],
            'with a unit' => ['24 h'],
        ];
    }

    #[DataProvider('invalidDelays')]
    public function testAnInvalidDelayIsRefusedAndNothingIsSaved(string $delay): void
    {
        $this->writeTemplates();

        try {
            $this->service->save(true, $delay);
            $this->fail("The delay « {$delay} » must be refused.");
        } catch (RegistrationException $e) {
            $this->assertSame(AutomaticDecisionEmailService::MESSAGE_DELAY_INVALID, $e->getMessage());
        }

        $this->assertFalse($this->service->isEnabled());
        $this->assertSame(24, $this->service->delayHours());
    }

    public function testTheBoundsThemselvesAreAccepted(): void
    {
        $this->writeTemplates();

        $this->service->save(true, '1');
        $this->assertSame(1, $this->service->delayHours());
        $this->service->save(true, '720');
        $this->assertSame(720, $this->service->delayHours());
    }

    /**
     * Switching off is always allowed — templates or not — and a delay
     * field that was not submitted keeps the stored delay.
     */
    public function testSwitchingOffIsAlwaysAllowedAndAMissingDelayKeepsTheStoredOne(): void
    {
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 36);

        $message = $this->service->save(false, null);

        $this->assertFalse($this->service->isEnabled());
        $this->assertSame(36, $this->service->delayHours());
        $this->assertSame(
            "Réglages enregistrés. Aucun e-mail ne partira tout seul : chaque envoi se fait depuis la fiche de la "
                . 'demande.',
            $message
        );
    }

    /**
     * Switching off cancels nothing, and the confirmation warns about the
     * e-mails already planned — that they still leave, or that they will
     * fail while a template is missing.
     */
    public function testSwitchingOffWarnsThatThePlannedEmailsStillLeave(): void
    {
        $this->writeTemplates();
        $this->service->save(true, '24');
        $this->service->plan($this->acceptedRequest('Un'), DecisionEmail::DECISION_ACCEPTED);
        $this->service->plan($this->acceptedRequest('Deux'), DecisionEmail::DECISION_ACCEPTED);

        $message = $this->service->save(false, '24');

        $this->assertSame(2, $this->decisionEmails->countScheduled());
        $this->assertSame(
            "Réglages enregistrés. Aucune nouvelle décision n'enverra d'e-mail toute seule : chaque envoi se fait "
                . 'depuis la fiche de la demande. Attention : les 2 e-mails déjà planifiés partiront quand même à '
                . 'leur échéance ; « Envoyer maintenant », sur leur fiche, les fait partir tout de suite.',
            $message
        );

        // A template emptied since — the one definition counts an empty
        // paragraph as « À rédiger ».
        $this->content->set('registration_email_refused_body', '<p>&nbsp;</p>', 'rich_text', 1);
        $this->assertStringContainsString(
            "les 2 e-mails déjà planifiés restent prévus, mais leur envoi échouera tant que les gabarits",
            $this->service->save(false, '24')
        );
    }

    public function testChangingTheDelayLeavesThePlannedEmailsTheirDueDate(): void
    {
        $this->writeTemplates();
        $this->service->save(true, '24');
        $planned = $this->service->plan($this->acceptedRequest(), DecisionEmail::DECISION_ACCEPTED);

        $message = $this->service->save(true, '48');

        $this->assertStringEndsWith(" L'e-mail déjà planifié garde son échéance.", $message);
        $this->assertEquals($planned?->dueAt, $this->decisionEmails->history($planned->requestId)[0]->dueAt);
    }

    /**
     * plan(): now + the delay while switched on and the decision's template
     * is written; nothing otherwise.
     */
    public function testPlanSchedulesTheDecisionEmailAtTheDelayOnlyWhileSwitchedOn(): void
    {
        $this->writeTemplates();
        $request = $this->acceptedRequest();
        $decidedAt = new \DateTimeImmutable('2027-01-10 08:15:00');

        $this->assertNull($this->service->plan($request, DecisionEmail::DECISION_ACCEPTED, $decidedAt));
        $this->assertSame(0, $this->decisionEmails->countScheduled());

        $this->service->save(true, '24');
        $planned = $this->service->plan($request, DecisionEmail::DECISION_ACCEPTED, $decidedAt);

        $this->assertNotNull($planned);
        $this->assertSame(DecisionEmail::STATE_SCHEDULED, $planned->state);
        $this->assertSame('2027-01-11 08:15:00', $planned->dueAt?->format('Y-m-d H:i:s'));
        // Never a second one while it is in play.
        $this->assertNull($this->service->plan($request, DecisionEmail::DECISION_ACCEPTED, $decidedAt));
        // Nor an e-mail of a decision the request does not hold.
        $this->assertNull($this->service->plan($this->acceptedRequest('Autre'), DecisionEmail::DECISION_REFUSED));
    }

    public function testAStoredDelayOutOfBoundsReadsAsTheDefault(): void
    {
        // Written past the setting's own validation, as a hand-edited
        // database would hold it.
        $store = $this->pdo->prepare('UPDATE settings SET setting_value = ? WHERE setting_key = ?');
        $service = fn(): AutomaticDecisionEmailService => new AutomaticDecisionEmailService(
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(RequestEmailService::class),
            $this->decisionEmails
        );

        $store->execute(['0', AutomaticDecisionEmailService::SETTING_DELAY_HOURS]);
        $this->assertSame(24, $service()->delayHours());

        $store->execute(['abc', AutomaticDecisionEmailService::SETTING_DELAY_HOURS]);
        $this->assertSame(24, $service()->delayHours());

        $store->execute(['5', AutomaticDecisionEmailService::SETTING_DELAY_HOURS]);
        $this->assertSame(5, $service()->delayHours());
    }

    public function testTheDueDateIsTheDecisionPlusTheDelay(): void
    {
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 30);

        $this->assertSame(
            '2027-01-11 14:00:00',
            $this->service->dueAt(new \DateTimeImmutable('2027-01-10 08:00:00'))->format('Y-m-d H:i:s')
        );
    }

    private function writeTemplates(): void
    {
        $this->content->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);
        $this->content->set('registration_email_refused_body', '<p>Désolés</p>', 'rich_text', 1);
    }

    private function acceptedRequest(string $firstName = 'Léa'): \Modules\Registration\Repository\RegistrationRequest
    {
        $id = $this->requests->create($this->yearId, [
            'parent_name' => 'Marie Dupont', 'child_last_name' => 'Dupont', 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => '2019-01-01', 'street' => 'S', 'number' => '1',
            'postal_code' => '1000', 'city' => 'V', 'email' => 'marie@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, [])['id'];
        $this->requests->updateStatus($id, 'accepted', null);

        $request = $this->requests->findById($id);
        $this->assertNotNull($request);

        return $request;
    }
}
