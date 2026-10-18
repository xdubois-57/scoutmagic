<?php

declare(strict_types=1);

namespace Tests\Modules\Registration\Service;

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Import\MemberYearRepository;
use Core\ScoutYear\ScoutYearResolver;
use Core\Security\EncryptionService;
use Modules\Registration\Repository\AgeBracketRepository;
use Modules\Registration\Repository\DecisionEmail;
use Modules\Registration\Repository\RegistrationRequest;
use Modules\Registration\Repository\RegistrationRequestRepository;
use Modules\Registration\Repository\SlotCapacityRepository;
use Modules\Registration\Service\AutomaticAcceptanceService;
use Modules\Registration\Service\PriorityOrderService;
use Modules\Registration\Service\RecommendationService;
use Modules\Registration\Service\SlotMath;
use Modules\Registration\Service\SlotService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Registration\RegistrationTestHelper;

/**
 * The automatic acceptance of the « Oui » requests (chantier #843, D7.2 /
 * IT-10), one pass at a time, on real repositories, the real
 * recommendation queue (RecommendationService over SlotService over the
 * module's projection) and the real decision service — the door a chief's
 * decision goes through.
 *
 * Louveteaux (sort order 20) of the target year 2027-2028, everything
 * switched on — waitlists, the automatic sending (24 h), both templates —
 * and the automatic acceptance from 2026-09-01 unless a test says
 * otherwise. The passes that must work on the production engine (the lock,
 * the row lock, two sessions) are AutomaticAcceptanceServiceOnMysqlTest.
 */
#[Group('database')]
class AutomaticAcceptanceServiceTest extends TestCase
{
    private const LOU1_BIRTH = '2019-05-04';
    private const LOU2_BIRTH = '2018-03-02';

    private \PDO $pdo;
    private EncryptionService $encryption;
    private SettingService $settings;
    private RegistrationRequestRepository $requests;
    private SlotCapacityRepository $capacities;
    private ScoutYearService $scoutYears;
    private int $louveteauxId;
    private int $pastYearId;
    private int $publicYearId;
    private int $targetYearId;
    private int $memberSeq = 0;

    /** @var (\Closure(int, int): void)|null called after each forYear() — see HookedRecommendationService */
    private ?\Closure $afterRecommendation = null;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        RegistrationTestHelper::createTables($this->pdo);
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->settings = new SettingService(new SettingRepository($this->pdo));
        RegistrationTestHelper::registerManifestSettings($this->settings);
        $this->settings->register(
            ScoutYearResolver::SETTING_PUBLIC_YEAR, '0', 'number', 'Public', 'desc', null, '^[0-9]+$', null, false
        );
        $this->settings->register(
            ScoutYearResolver::SETTING_STAFF_YEAR, '0', 'number', 'Staff', 'desc', null, '^[0-9]+$', null, false
        );

        $this->scoutYears = new ScoutYearService($this->pdo);
        $this->pastYearId = $this->scoutYears->ensureYear('2025-2026');
        $this->publicYearId = $this->scoutYears->ensureYear('2026-2027');
        $this->targetYearId = $this->scoutYears->ensureYear('2027-2028');
        $this->settings->setInternal(ScoutYearResolver::SETTING_PUBLIC_YEAR, (string) $this->publicYearId);

        $this->louveteauxId = RegistrationTestHelper::insertAgeBranch($this->pdo, 'LOUV', 'Louveteaux', 20);
        $this->capacities = new SlotCapacityRepository($this->pdo);
        $this->capacities->upsert($this->louveteauxId, 1, 15);
        $this->capacities->upsert($this->louveteauxId, 2, 15);

        $this->requests = new RegistrationRequestRepository($this->pdo, $this->encryption);
        RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24);
        RegistrationTestHelper::enableAutomaticAcceptance($this->settings, '2026-09-01');
    }

    public function testNothingIsAcceptedWhileItIsOff(): void
    {
        RegistrationTestHelper::enableAutomaticAcceptance($this->settings, '2026-09-01', false);
        $id = $this->file('Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-08 10:00:00');

        $this->assertSame(['accepted' => 0, 'busy' => 0, 'more' => false], $this->service()->run());
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($id));
    }

    /**
     * Each prerequisite, read at the pass — not only when the box was
     * saved — is enough to stop it.
     */
    #[DataProvider('missingPrerequisites')]
    public function testNothingIsAcceptedWhileAPrerequisiteIsMissing(string $missing): void
    {
        match ($missing) {
            'waitlist' => $this->settings->set('registration_waitlist_enabled', '0', 'registration'),
            'auto_send' => RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24, false),
            default => RegistrationTestHelper::enableAutomaticAcceptance($this->settings, ''),
        };
        $id = $this->file('Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-08 10:00:00');

        $this->assertSame(0, $this->service()->run()['accepted']);
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($id));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function missingPrerequisites(): array
    {
        return [
            'waitlists not managed' => ['waitlist'],
            'automatic sending off' => ['auto_send'],
            'no date of effect' => ['date'],
        ];
    }

    /**
     * The mock-up's queue: every queued request is « Oui » (6 + 4 = 10
     * places of 15 before the last one), so each is accepted — slot by
     * slot, in queue order, « Toujours Oui » first — with its acceptance
     * e-mail planned 24 h later; the duplicate and the request outside
     * every bracket are left pending. Through the decision service: the
     * status change says it was automatic.
     */
    public function testEveryOuiRequestIsAcceptedInQueueOrderWithItsEmailPlanned(): void
    {
        $this->seedMockupDataSet();
        $before = new \DateTimeImmutable();

        $outcome = $this->service()->run();

        $this->assertSame(['accepted' => 6, 'busy' => 0, 'more' => false], $outcome);
        $this->assertSame([416, 417, 409, 418, 419, 398], $this->autoAcceptedIds());
        foreach ([416, 417, 409, 418, 419, 398] as $id) {
            $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($id), "#{$id}");
            $email = $this->scheduledEmail($id);
            $this->assertSame(DecisionEmail::DECISION_ACCEPTED, $email['decision']);
            $due = new \DateTimeImmutable((string) $email['due_at']);
            $this->assertGreaterThanOrEqual($before->modify('+24 hours')->getTimestamp() - 1, $due->getTimestamp());
            $this->assertLessThanOrEqual((new \DateTimeImmutable('+24 hours'))->getTimestamp(), $due->getTimestamp());
        }
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf(414));
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf(401));

        $statusChanges = $this->journalContexts('registration_request_status_changed');
        $this->assertCount(6, $statusChanges);
        foreach ($statusChanges as $context) {
            $this->assertTrue($context['automatic'] ?? false);
        }
        $this->assertSame(
            ['accepted' => 6, 'busy' => 0, 'more' => false],
            $this->journalContexts('registration_requests_auto_accept_run')[0] ?? null
        );
    }

    /**
     * The journal entry of an automatic acceptance: ids, the slot, the
     * rule and the e-mail's due date — nothing a family wrote.
     */
    public function testTheJournalTellsAnAutomaticAcceptanceApartWithoutPersonalData(): void
    {
        $this->seedMockupDataSet();

        $this->service()->run();

        $entry = $this->journalContexts('registration_request_auto_accepted')[0];
        $this->assertSame(
            [
                'request_id', 'scout_year_id', 'age_branch_id', 'year_in_branch', 'rule', 'position',
                'decision_email_due_at',
            ],
            array_keys($entry)
        );
        $this->assertSame(416, $entry['request_id']);
        $this->assertSame($this->targetYearId, $entry['scout_year_id']);
        $this->assertSame('always', $entry['rule']);
        $logged = (string) json_encode($this->pdo->query('SELECT description, context FROM event_log')->fetchAll());
        foreach (['Vidal', 'Emma', 'emma@example.com', self::LOU1_BIRTH, 'Parent'] as $personal) {
            $this->assertStringNotContainsString($personal, $logged);
        }
        $this->assertStringContainsString('acceptation automatique', $logged);
    }

    /**
     * Ten places, six taken, « Oui » up to 70 % and « Peut-être » up to
     * 80 %: two ranked requests are « Oui » (60 %, 70 %), then
     * « Peut-être » (80 %), then « Non » (90 %) — the pass accepts the two
     * and stops; neither « Peut-être » nor « Non » is ever accepted, a
     * second pass included.
     */
    public function testItStopsAtTheFirstRequestThatIsNotOuiAndNeverAcceptsPeutEtreOrNon(): void
    {
        $this->settings->setManyInternal([
            'registration_threshold_yes_percent' => '70',
            'registration_threshold_maybe_percent' => '80',
        ], 'registration');
        $this->capacities->upsert($this->louveteauxId, 1, 10);
        $this->accepted(6, self::LOU1_BIRTH);
        $ids = $this->fileMany(4, self::LOU1_BIRTH);

        $this->assertSame(2, $this->service()->run()['accepted']);
        $this->assertSame(0, $this->service()->run()['accepted']);

        $this->assertSame([$ids[0], $ids[1]], $this->autoAcceptedIds());
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($ids[2]));
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($ids[3]));
        $levels = $this->recommendations()->forYear($this->targetYearId);
        $this->assertSame(SlotMath::LEVEL_MAYBE, $levels[$ids[2]]->level);
        $this->assertSame(SlotMath::LEVEL_NO, $levels[$ids[3]]->level);
    }

    /**
     * « Oui » up to 110 %: on five places with three taken, the request
     * judged on a full slot is still « Oui » — and it is not accepted:
     * the slot ends at exactly its capacity.
     */
    public function testAOuiThresholdAbove100PercentNeverTakesTheSlotBeyondItsPlaces(): void
    {
        $this->settings->setManyInternal([
            'registration_threshold_yes_percent' => '110',
            'registration_threshold_maybe_percent' => '120',
        ], 'registration');
        $this->capacities->upsert($this->louveteauxId, 1, 5);
        $this->accepted(3, self::LOU1_BIRTH);
        $ids = $this->fileMany(3, self::LOU1_BIRTH);

        $this->assertSame(2, $this->service()->run()['accepted']);

        $this->assertSame([$ids[0], $ids[1]], $this->autoAcceptedIds());
        $third = $this->recommendations()->forYear($this->targetYearId)[$ids[2]];
        $this->assertSame(SlotMath::LEVEL_YES, $third->level);
        $this->assertTrue($third->exceedsCapacity());
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($ids[2]));
    }

    /** « Toujours Oui » may take a full slot beyond its capacity; a ranked request behind it is not. */
    public function testAToujoursOuiRequestMayTakeAFullSlotBeyondItsCapacity(): void
    {
        $this->capacities->upsert($this->louveteauxId, 1, 2);
        $this->accepted(2, self::LOU1_BIRTH);
        $sibling = $this->file('Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-08 10:00:00', sibling: true);
        $ranked = $this->file('Sofia', 'Claes', self::LOU1_BIRTH, '2026-10-05 10:00:00');

        $this->assertSame(1, $this->service()->run()['accepted']);

        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($sibling));
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($ranked));
    }

    /**
     * From 2026-10-06: the requests filed before are left pending, the ones
     * filed since are accepted — the ones behind the early ones too, since
     * an early request is left aside, not a stop.
     */
    public function testARequestFiledBeforeTheDateOfEffectIsNeverAccepted(): void
    {
        RegistrationTestHelper::enableAutomaticAcceptance($this->settings, '2026-10-06');
        $early = $this->file('Lucas', 'Mertens', self::LOU1_BIRTH, '2026-10-05 23:59:59');
        $onTheDay = $this->file('Sofia', 'Claes', self::LOU1_BIRTH, '2026-10-06 00:00:00');
        $later = $this->file('Arthur', 'Noël', self::LOU1_BIRTH, '2026-10-09 10:00:00');

        $this->assertSame(2, $this->service()->run()['accepted']);

        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($early));
        $this->assertSame([$onTheDay, $later], $this->autoAcceptedIds());
    }

    /** A request of a past scout year is consultation only (R8): never accepted. */
    public function testARequestOfAPastYearIsNeverAccepted(): void
    {
        $past = $this->file('Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-08 10:00:00', yearId: $this->pastYearId);
        // Louveteaux 1re année of the current year, 2026-2027.
        $current = $this->file('Sofia', 'Claes', '2018-05-04', '2026-10-08 10:00:00', yearId: $this->publicYearId);

        $this->service()->run();

        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($past));
        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($current));
    }

    /** Two passes, one after the other: the second has nothing left to accept. */
    public function testASecondPassAcceptsNothingMore(): void
    {
        $this->seedMockupDataSet();

        $this->assertSame(6, $this->service()->run()['accepted']);
        $this->assertSame(['accepted' => 0, 'busy' => 0, 'more' => false], $this->service()->run());

        $this->assertCount(6, $this->autoAcceptedIds());
        $this->assertSame(6, (int) $this->pdo->query(
            "SELECT COUNT(*) FROM registration_decision_emails WHERE state = 'scheduled'"
        )->fetchColumn());
    }

    /**
     * A chief refuses the first request between the moment the queue was
     * recomputed and its acceptance: the site does not override that
     * decision, recomputes, and goes on with the next one.
     */
    public function testARequestDecidedByHandSinceTheQueueWasComputedIsNotAccepted(): void
    {
        $first = $this->file('Sofia', 'Claes', self::LOU1_BIRTH, '2026-10-05 10:00:00');
        $second = $this->file('Arthur', 'Noël', self::LOU1_BIRTH, '2026-10-09 10:00:00');
        // Right after the recomputation under the lock that picks $first.
        $this->afterRecommendation = function (int $call, int $scoutYearId) use ($first): void {
            if ($scoutYearId === $this->targetYearId && $call === 2) {
                $refusedAt = new \DateTimeImmutable();
                $this->requests->updateStatus($first, RegistrationRequest::STATUS_REFUSED, $refusedAt, 'other');
            }
        };

        $this->assertSame(1, $this->service()->run()['accepted']);

        $this->assertSame(RegistrationRequest::STATUS_REFUSED, $this->statusOf($first));
        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($second));
        $this->assertSame([$second], $this->autoAcceptedIds());
    }

    /**
     * The automatic sending is switched off while the pass runs — after it
     * checked its prerequisites, before the first acceptance — so that
     * acceptance's e-mail cannot be planned: it is rolled back (status and
     * journal), journaled as a warning, and the pass ends, the request of
     * the other slot included.
     */
    public function testAnAcceptanceWhoseEmailCannotBePlannedIsRolledBackAndEndsThePass(): void
    {
        $blocked = $this->file('Sofia', 'Claes', self::LOU1_BIRTH, '2026-10-05 10:00:00');
        $other = $this->file('Hugo', 'Renard', self::LOU2_BIRTH, '2026-10-06 10:00:00');
        $this->afterRecommendation = function (int $call, int $scoutYearId): void {
            if ($scoutYearId === $this->targetYearId && $call === 2) {
                RegistrationTestHelper::enableAutomaticDecisionEmails($this->settings, 24, false);
            }
        };

        $this->assertSame(['accepted' => 0, 'busy' => 0, 'more' => false], $this->service()->run());

        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($blocked));
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($other));
        $this->assertSame([], $this->journalContexts('registration_request_status_changed'));
        $this->assertSame([], $this->autoAcceptedIds());
        $this->assertSame(
            [['request_id' => $blocked]],
            $this->journalContexts('registration_request_auto_accept_unplanned')
        );
    }

    /**
     * One request that cannot be read any more — its stored name no longer
     * decrypts — fails on its own: rolled back, journaled with the error's
     * class only, its slot left to a person; the other slot is accepted.
     */
    public function testARequestThatFailsLeavesItsSlotToAPersonAndThePassGoesOn(): void
    {
        $broken = $this->file('Sofia', 'Claes', self::LOU1_BIRTH, '2026-10-05 10:00:00');
        $behind = $this->file('Arthur', 'Noël', self::LOU1_BIRTH, '2026-10-06 10:00:00');
        $other = $this->file('Hugo', 'Renard', self::LOU2_BIRTH, '2026-10-06 10:00:00');
        $this->pdo->prepare("UPDATE registration_requests SET child_last_name_encrypted = 'corrompu' WHERE id = ?")
            ->execute([$broken]);

        $this->assertSame(['accepted' => 1, 'busy' => 0, 'more' => false], $this->service()->run());

        $this->assertSame([$other], $this->autoAcceptedIds());
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($behind));
        $failures = $this->journalContexts('registration_request_auto_accept_failed');
        $this->assertCount(1, $failures);
        $this->assertSame($broken, $failures[0]['request_id']);
        $this->assertStringEndsWith('DecryptionException', (string) $failures[0]['error']);
        $this->assertSame(1, count($this->journalContexts('registration_request_status_changed')));
    }

    /**
     * The recommendation of one year cannot be computed: that year is
     * skipped for the pass, journaled with its id and the error's class
     * only, and the next year is still processed.
     */
    public function testAYearWhoseRecommendationFailsIsSkippedAndTheNextYearIsStillProcessed(): void
    {
        // Louveteaux 1re année of the current year, 2026-2027.
        $current = $this->file('Sofia', 'Claes', '2018-05-04', '2026-10-08 10:00:00', yearId: $this->publicYearId);
        $target = $this->file('Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-08 10:00:00');
        $this->afterRecommendation = function (int $call, int $scoutYearId): void {
            if ($scoutYearId === $this->publicYearId) {
                throw new \RuntimeException('recommendation unavailable');
            }
        };

        $this->assertSame(['accepted' => 1, 'busy' => 0, 'more' => false], $this->service()->run());

        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($current));
        $this->assertSame(RegistrationRequest::STATUS_ACCEPTED, $this->statusOf($target));
        $this->assertSame(
            [['scout_year_id' => $this->publicYearId, 'error' => \RuntimeException::class]],
            $this->journalContexts('registration_auto_accept_recommendation_failed')
        );
    }

    /**
     * The recomputation under a slot's lock fails: the rest of that year is
     * skipped for the pass — its other slot would only fail the same way —
     * journaled once, the lock released, and the next year still processed.
     */
    public function testARecomputationThatFailsUnderTheLockSkipsTheRestOfThatYear(): void
    {
        // Louveteaux 1re and 2e année of the current year, 2026-2027.
        $first = $this->file('Sofia', 'Claes', '2018-05-04', '2026-10-05 10:00:00', yearId: $this->publicYearId);
        $second = $this->file('Hugo', 'Renard', '2017-03-02', '2026-10-06 10:00:00', yearId: $this->publicYearId);
        $nextYear = $this->file('Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-07 10:00:00');
        // Call 2 is the recomputation under the lock of the year's first slot.
        $this->afterRecommendation = function (int $call, int $scoutYearId): void {
            if ($scoutYearId === $this->publicYearId && $call === 2) {
                throw new \RuntimeException('recommendation unavailable');
            }
        };

        $this->assertSame(['accepted' => 1, 'busy' => 0, 'more' => false], $this->service()->run());

        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($first));
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($second));
        $this->assertSame([$nextYear], $this->autoAcceptedIds());
        $this->assertSame(
            [['scout_year_id' => $this->publicYearId, 'error' => \RuntimeException::class]],
            $this->journalContexts('registration_auto_accept_recommendation_failed')
        );
        $this->assertTrue($this->requests->acquireAutoAcceptLock($this->publicYearId, $this->louveteauxId, 1));
    }

    /**
     * Waitlists switched off where the box does not see it — Configuration
     * > Paramètres —: the next pass switches the automatic acceptance off,
     * so turning waitlists back on does not bring it back.
     */
    public function testAPassFindingWaitlistsOffSwitchesTheAutomaticAcceptanceOff(): void
    {
        $id = $this->file('Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-08 10:00:00');
        $this->settings->set('registration_waitlist_enabled', '0', 'registration');

        $this->service()->run();
        $this->settings->set('registration_waitlist_enabled', '1', 'registration');
        $this->service()->run();

        $this->assertSame('0', (string) $this->settings->get('registration_auto_accept', 'registration'));
        $this->assertSame(RegistrationRequest::STATUS_PENDING, $this->statusOf($id));
        $this->assertCount(1, $this->journalContexts('registration_auto_accept_switched_off'));
    }

    private function service(): AutomaticAcceptanceService
    {
        $hook = function (int $call, int $scoutYearId): void {
            if ($this->afterRecommendation !== null) {
                ($this->afterRecommendation)($call, $scoutYearId);
            }
        };

        return RegistrationTestHelper::automaticAcceptanceService(
            $this->pdo,
            $this->encryption,
            $this->settings,
            $hook
        );
    }

    /** The real recommendation, to read what the queue says after a pass. */
    private function recommendations(): RecommendationService
    {
        return new RecommendationService(
            $this->requests,
            $this->slotService(),
            new AgeBracketRepository($this->pdo),
            $this->scoutYears,
            $this->resolver(),
            new PriorityOrderService($this->settings)
        );
    }

    private function slotService(): SlotService
    {
        return RegistrationTestHelper::slotService($this->pdo, $this->encryption, $this->settings);
    }

    private function resolver(): ScoutYearResolver
    {
        return new ScoutYearResolver($this->scoutYears, $this->settings, new MemberYearRepository($this->pdo));
    }

    /**
     * The mock-up's data set (RecommendationServiceTest's): Louveteaux
     * 1re année, 6 of 15 places taken, #416 (sibling), #417 (refused
     * « Pas de place » in 2025-2026), #418, #419, #409 and its possible
     * duplicate #414; Louveteaux 2e année, 8 of 15 taken, #398 (sibling);
     * #401 outside every bracket.
     */
    private function seedMockupDataSet(): void
    {
        $this->accepted(6, self::LOU1_BIRTH);
        $this->accepted(8, self::LOU2_BIRTH);

        $refused = $this->file('Lucas', 'Mertens', '2019-02-11', '2025-10-02 09:00:00', yearId: $this->pastYearId);
        $noRoom = RegistrationRequest::REFUSAL_NO_ROOM;
        $this->requests->updateStatus($refused, 'refused', new \DateTimeImmutable(), $noRoom);

        $this->file('Emma', 'Vidal', self::LOU1_BIRTH, '2026-10-08 10:00:00', 416, true);
        $this->file('Lucas', 'Mertens', '2019-02-11', '2026-10-04 10:00:00', 417);
        $this->file('Sofia', 'Claes', self::LOU1_BIRTH, '2026-10-05 10:00:00', 418);
        $this->file('Arthur', 'Noël', self::LOU1_BIRTH, '2026-10-09 10:00:00', 419);
        $this->file('Noah', 'Peeters', '2019-07-07', '2026-10-03 10:00:00', 409);
        $this->file('Noah', 'Peeters', '2019-07-07', '2026-10-07 10:00:00', 414);
        $this->file('Hugo', 'Renard', self::LOU2_BIRTH, '2026-09-28 10:00:00', 398, true);
        $this->file('Inès', 'Bouchat', '2024-01-01', '2026-09-29 10:00:00', 401);
    }

    /**
     * $count requests of the target year, born $birthDate, filed a day
     * apart from 2026-10-01 — so their queue order is their filing order.
     *
     * @return array<int, int>
     */
    private function fileMany(int $count, string $birthDate): array
    {
        $names = ['Alix', 'Basile', 'Camille', 'Diane', 'Elio', 'Fanny'];
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->file($names[$i], 'Famille' . $i, $birthDate, sprintf('2026-10-%02d 10:00:00', $i + 1));
        }

        return $ids;
    }

    /** $count places already taken in the slot of $birthDate: accepted requests. */
    private function accepted(int $count, string $birthDate): void
    {
        for ($i = 0; $i < $count; $i++) {
            $id = $this->file('Pris' . $i, 'Accepté' . $birthDate, $birthDate, '2026-09-15 10:00:00');
            $this->requests->updateStatus($id, 'accepted', null);
        }
    }

    /**
     * Files one request of $yearId (the target year by default), filed at
     * $receivedAt, renumbered to $id when given, with a declared sibling
     * still in the unit when asked.
     */
    private function file(
        string $firstName,
        string $lastName,
        string $birthDate,
        string $receivedAt,
        ?int $id = null,
        bool $sibling = false,
        ?int $yearId = null
    ): int {
        $siblings = $sibling ? [$this->sibling()] : [];
        $created = $this->requests->create($yearId ?? $this->targetYearId, [
            'parent_name' => 'Parent ' . $lastName, 'child_last_name' => $lastName, 'child_first_name' => $firstName,
            'gender' => 'F', 'birth_date' => $birthDate, 'street' => 'Rue', 'number' => '1',
            'postal_code' => '1000', 'city' => 'Ville', 'email' => strtolower($firstName) . '@example.com',
            'phone1' => '000', 'phone2' => null, 'remarks' => null,
        ], null, $siblings)['id'];

        $this->pdo->prepare('UPDATE registration_requests SET received_at = ? WHERE id = ?')
            ->execute([$receivedAt, $created]);
        if ($id === null) {
            return $created;
        }
        $this->pdo->prepare('UPDATE registration_requests SET id = ? WHERE id = ?')->execute([$id, $created]);
        $this->pdo->prepare('UPDATE registration_request_siblings SET registration_request_id = ? '
            . 'WHERE registration_request_id = ?')->execute([$id, $created]);

        return $id;
    }

    /** A member enrolled, active, in the current scout year: a sibling still in the unit. */
    private function sibling(): int
    {
        $memberId = RegistrationTestHelper::insertMember($this->pdo, 'D' . (++$this->memberSeq));
        $this->pdo->prepare('INSERT INTO member_years (member_id, scout_year_id, first_name_encrypted, '
            . 'last_name_encrypted, is_active) VALUES (?, ?, ?, ?, 1)')->execute([
                $memberId,
                $this->publicYearId,
                $this->encryption->encrypt('Jules', 'member_years.first_name'),
                $this->encryption->encrypt('Vidal', 'member_years.last_name'),
            ]);

        return $memberId;
    }

    private function statusOf(int $id): ?string
    {
        return $this->requests->findById($id)?->status;
    }

    /**
     * The request ids of the `registration_request_auto_accepted` entries,
     * in the order they were written.
     *
     * @return array<int, int>
     */
    private function autoAcceptedIds(): array
    {
        return array_map(
            static fn(array $context): int => (int) $context['request_id'],
            $this->journalContexts('registration_request_auto_accepted')
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function journalContexts(string $type): array
    {
        $statement = $this->pdo->prepare('SELECT context FROM event_log WHERE event_type = ? ORDER BY id');
        $statement->execute([$type]);

        return array_map(
            static fn(?string $json): array => (array) json_decode((string) $json, true),
            $statement->fetchAll(\PDO::FETCH_COLUMN)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduledEmail(int $requestId): array
    {
        $statement = $this->pdo->prepare(
            "SELECT decision, due_at FROM registration_decision_emails WHERE registration_request_id = ?
               AND state = 'scheduled'"
        );
        $statement->execute([$requestId]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row, "request #{$requestId} has its e-mail planned");

        return $row;
    }
}
