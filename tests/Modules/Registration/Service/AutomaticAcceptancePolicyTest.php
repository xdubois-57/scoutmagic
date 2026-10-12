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
use Modules\Registration\Repository\DecisionEmailRepository;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Service\AutomaticAcceptancePolicy;
use Modules\Registration\Service\AutomaticDecisionEmailService;
use Modules\Registration\Service\DecisionEmailService;
use Modules\Registration\Service\Recommendation;
use Modules\Registration\Service\RecommendationService;
use Modules\Registration\Service\RequestEmailService;
use Modules\Registration\Service\SlotMath;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * « Accepter automatiquement les demandes « Oui » » (chantier #843, D7.2 /
 * IT-10): when it runs — the switch, the date, and the three settings it
 * depends on, read at each pass — and which requests of a slot's queue it
 * takes, on queues built by hand so each rule is seen alone.
 */
#[Group('database')]
class AutomaticAcceptancePolicyTest extends TestCase
{
    private const FROM = '2026-10-15';

    private \PDO $pdo;
    private SettingService $settings;
    private EditableContentService $content;
    private AutomaticAcceptancePolicy $policy;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settings);

        $journal = new JournalService(new JournalRepository($this->pdo));
        $repository = new DecisionEmailRepository($this->pdo);
        $decisionEmails = new DecisionEmailService($repository, $journal);
        $this->content = new EditableContentService(new EditableContentRepository($this->pdo));
        $emailService = new RequestEmailService(
            new RegistrationRequestRepository($this->pdo, $encryption, null, $repository),
            $this->createStub(MailService::class),
            $this->content,
            $journal,
            'https://example.com',
            'Unité Test',
            $decisionEmails
        );
        $automaticEmails = new AutomaticDecisionEmailService($this->settings, $emailService, $decisionEmails);
        $this->policy = new AutomaticAcceptancePolicy(
            $this->settings,
            RegistrationTestHelper::slotService($this->pdo, $encryption, $this->settings),
            $automaticEmails
        );
    }

    public function testOffByDefaultWithNoDateAndNeitherSettingOnTheParametersPage(): void
    {
        $this->assertFalse($this->policy->isSwitchOn());
        $this->assertNull($this->policy->effectiveFrom());
        $this->assertFalse($this->policy->isActive());
        $this->assertSame(
            [
                'enabled' => false,
                'effective_from' => '2026-10-12',
                'can_enable' => false,
                'missing' => AutomaticAcceptancePolicy::MISSING_AUTO_SEND,
                'waitlist_enabled' => true,
                'template_ready' => false,
                'active' => false,
            ],
            $this->policy->boxState(new \DateTimeImmutable('2026-10-12 09:30:00'))
        );

        $editable = $this->pdo->query(
            "SELECT setting_key, editable FROM settings WHERE setting_key IN
                ('registration_auto_accept', 'registration_auto_accept_from') ORDER BY setting_key"
        )->fetchAll(\PDO::FETCH_KEY_PAIR);
        $this->assertSame(['registration_auto_accept' => 0, 'registration_auto_accept_from' => 0], $editable);
    }

    /**
     * Active only with everything at once — and each missing piece, read
     * at the moment, is enough to stop it, in the order the sub-box names
     * them.
     */
    public function testActiveOnlyWithTheSwitchTheDateAndEveryPrerequisite(): void
    {
        $this->enableEverything();
        $this->assertTrue($this->policy->isActive());
        $this->assertNull($this->policy->missingPrerequisite());
        $this->assertSame('2026-10-15 00:00:00', $this->policy->effectiveFrom()?->format('Y-m-d H:i:s'));

        $this->content->set('registration_email_accepted_body', '<p>&nbsp;</p>', 'rich_text', 1);
        $this->assertSame(AutomaticAcceptancePolicy::MISSING_TEMPLATE, $this->policy->missingPrerequisite());
        $this->assertFalse($this->policy->isActive());
        $this->content->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);

        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24, false);
        $this->assertSame(AutomaticAcceptancePolicy::MISSING_AUTO_SEND, $this->policy->missingPrerequisite());
        $this->assertFalse($this->policy->isActive());
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24);

        // Switched off elsewhere (Configuration > Paramètres) than the box.
        $this->settings->set('registration_waitlist_enabled', '0', 'registration');
        $this->assertSame(AutomaticAcceptancePolicy::MISSING_WAITLIST, $this->policy->missingPrerequisite());
        $this->assertFalse($this->policy->isActive());
        $this->assertTrue($this->policy->isSwitchOn());
        $this->settings->set('registration_waitlist_enabled', '1', 'registration');

        $this->settings->setInternal(AutomaticAcceptancePolicy::SETTING_EFFECTIVE_FROM, '', 'registration');
        // No date: nothing is missing in the settings it depends on, and it
        // does not run all the same.
        $this->assertNull($this->policy->missingPrerequisite());
        $this->assertFalse($this->policy->isActive());
    }

    public function testSwitchOffSaysWhetherItWasOn(): void
    {
        $this->enableEverything();

        $this->assertTrue($this->policy->switchOff());
        $this->assertFalse($this->policy->isSwitchOn());
        $this->assertFalse($this->policy->switchOff());
        $this->assertSame(self::FROM, (string) $this->settings->get(
            AutomaticAcceptancePolicy::SETTING_EFFECTIVE_FROM,
            'registration'
        ));
    }

    public function testTheBoxShowsTheStoredDateAndIsUsableOnceEverythingIsThere(): void
    {
        $this->enableEverything();

        $state = $this->policy->boxState(new \DateTimeImmutable('2026-11-01'));

        $this->assertTrue($state['enabled']);
        $this->assertSame(self::FROM, $state['effective_from']);
        $this->assertTrue($state['can_enable']);
        $this->assertNull($state['missing']);
        $this->assertTrue($state['active']);
    }

    /**
     * A queue entirely « Oui »: every request is taken, in queue order,
     * « Toujours Oui » first.
     */
    public function testEveryOuiIsTakenInQueueOrder(): void
    {
        $queue = [
            $this->entry(3, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 3, 7, 15),
            $this->entry(1, SlotMath::LEVEL_YES, Recommendation::KIND_ALWAYS, 1, 5, 15),
            $this->entry(2, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 2, 6, 15),
        ];

        $this->assertSame(1, AutomaticAcceptancePolicy::nextAcceptable(
            AutomaticAcceptancePolicy::queuesBySlot($queue)['7:1'],
            $this->from()
        )?->requestId);
        $this->assertSame([1, 2, 3], AutomaticAcceptancePolicy::acceptableRequestIds($queue, $this->from()));
    }

    /** « Peut-être » ends the slot: neither it nor anything behind it is taken. */
    public function testTheFirstRequestThatIsNotOuiEndsTheSlot(): void
    {
        $queue = [
            $this->entry(1, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 1, 9, 15),
            $this->entry(2, SlotMath::LEVEL_MAYBE, Recommendation::KIND_RANK, 2, 11, 15),
            $this->entry(3, SlotMath::LEVEL_NO, Recommendation::KIND_RANK, 3, 16, 15),
        ];

        $this->assertSame([1], AutomaticAcceptancePolicy::acceptableRequestIds($queue, $this->from()));
        $this->assertNull(AutomaticAcceptancePolicy::nextAcceptable(array_slice($queue, 1), $this->from()));
    }

    /**
     * A « Oui » threshold above 100 %: a ranked request judged on a full
     * slot is still « Oui », and it is not taken — only « Toujours Oui »
     * goes beyond the number of places.
     */
    public function testARankedOuiBeyondTheCapacityIsNotTakenButAToujoursOuiIs(): void
    {
        $ranked = [
            $this->entry(1, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 1, 9, 10),
            $this->entry(2, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 2, 10, 10),
        ];
        $this->assertSame([1], AutomaticAcceptancePolicy::acceptableRequestIds($ranked, $this->from()));

        $always = [
            $this->entry(4, SlotMath::LEVEL_YES, Recommendation::KIND_ALWAYS, 1, 10, 10),
            $this->entry(5, SlotMath::LEVEL_YES, Recommendation::KIND_ALWAYS, 2, 11, 10),
            $this->entry(6, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 3, 12, 10),
        ];
        $this->assertSame([4, 5], AutomaticAcceptancePolicy::acceptableRequestIds($always, $this->from()));

        // No limit at all: never beyond anything.
        $unlimited = [$this->entry(7, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 1, 40, null)];
        $this->assertSame([7], AutomaticAcceptancePolicy::acceptableRequestIds($unlimited, $this->from()));
    }

    /**
     * A request filed before the date of effect is left aside — never
     * taken, and never a stop for the ones behind it, even when it is not
     * « Oui » itself.
     */
    public function testARequestFiledBeforeTheDateIsLeftAsideWithoutStoppingTheSlot(): void
    {
        $queue = [
            $this->entry(1, SlotMath::LEVEL_YES, Recommendation::KIND_ALWAYS, 1, 5, 15, '2026-10-14 23:59:59'),
            $this->entry(2, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 2, 6, 15, '2026-10-15 00:00:00'),
            $this->entry(3, SlotMath::LEVEL_MAYBE, Recommendation::KIND_RANK, 3, 11, 15, '2026-10-01 10:00:00'),
            $this->entry(4, SlotMath::LEVEL_MAYBE, Recommendation::KIND_RANK, 4, 12, 15, '2026-10-20 10:00:00'),
        ];

        $this->assertSame([2], AutomaticAcceptancePolicy::acceptableRequestIds($queue, $this->from()));
    }

    /** Requests left out of the queue — duplicates, outside every bracket — are never taken. */
    public function testRequestsOutsideTheQueueAreNeverTaken(): void
    {
        $recommendations = [
            new Recommendation(8, null, 'doublon', duplicateOfRequestId: 3),
            new Recommendation(9, null, RecommendationService::EXPLANATION_OUT_OF_BRACKETS),
        ];

        $this->assertSame([], AutomaticAcceptancePolicy::queuesBySlot($recommendations));
        $this->assertSame([], AutomaticAcceptancePolicy::acceptableRequestIds($recommendations, $this->from()));
    }

    /** Each slot is its own queue: a stop in one does not stop another. */
    public function testEachSlotIsItsOwnQueue(): void
    {
        $recommendations = [
            $this->entry(1, SlotMath::LEVEL_MAYBE, Recommendation::KIND_RANK, 1, 11, 15),
            $this->entry(2, SlotMath::LEVEL_YES, Recommendation::KIND_RANK, 1, 3, 15, '2026-10-16 10:00:00', 2),
        ];

        $this->assertSame([2], AutomaticAcceptancePolicy::acceptableRequestIds($recommendations, $this->from()));
    }

    private function enableEverything(): void
    {
        $this->content->set('registration_email_accepted_body', '<p>Bienvenue</p>', 'rich_text', 1);
        $this->content->set('registration_email_refused_body', '<p>Désolés</p>', 'rich_text', 1);
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24);
        RegistrationTestHelper::enableAutomaticAcceptance($this->settings, self::FROM);
    }

    private function from(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::FROM . ' 00:00:00');
    }

    /**
     * One queued recommendation of Louveteaux (age branch 7), year
     * $yearInBranch, filed at $filed.
     */
    private function entry(
        int $id,
        string $level,
        string $kind,
        int $position,
        int $occupiedBefore,
        ?int $capacity,
        string $filed = '2026-10-16 10:00:00',
        int $yearInBranch = 1
    ): Recommendation {
        return new Recommendation(
            $id,
            $level,
            'explication',
            $kind,
            7,
            $yearInBranch,
            'Louveteaux · ' . $yearInBranch . 're année',
            $position,
            $kind === Recommendation::KIND_RANK ? $position : null,
            null,
            $occupiedBefore,
            $capacity,
            receivedAt: new \DateTimeImmutable($filed)
        );
    }
}
