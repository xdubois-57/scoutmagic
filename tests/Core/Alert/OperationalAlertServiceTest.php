<?php

declare(strict_types=1);

namespace Tests\Core\Alert;

use Core\Alert\AlertReading;
use Core\Alert\OperationalAlert;
use Core\Alert\OperationalAlertRepository;
use Core\Alert\OperationalAlertService;
use Core\Alert\OperationalCheck;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Maintenance\Health\Security\HostSecurityAlertCheck;
use Core\Notification\NotificationService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The armed/triggered state machine — decision D1 of the chantier, and the
 * whole reason this mechanism has a table at all.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class OperationalAlertServiceTest extends TestCase
{
    private \PDO $pdo;
    private OperationalAlertRepository $repository;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->repository = new OperationalAlertRepository($this->pdo);
    }

    /**
     * A NotificationService that records what it was asked to send.
     *
     * `createMock()` rather than a hand-written double, following this
     * repository's convention for this exact collaborator
     * (`Tests\Modules\Camps\Service\ReviewNotificationServiceTest` and
     * five others). `recipientsForType()` answers with one super-admin, so
     * the audience resolution is stubbed out and what is under test is the
     * transition logic.
     *
     * @param list<string> $types filled in with the type id of every dispatch
     */
    private function notificationsRecording(array &$types): NotificationService
    {
        $mock = $this->createStub(NotificationService::class);
        $mock->method('recipientsForType')->willReturn([['userAccountId' => 1, 'memberId' => null]]);
        $mock->method('dispatch')->willReturnCallback(
            static function (string $typeId) use (&$types): void {
                $types[] = $typeId;
            }
        );

        return $mock;
    }

    // ————— La transition, et elle seule —————

    public function testCrossingTheTriggerNotifiesOnceAndRecordsTheState(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $this->assertTrue($service->evaluate(self::check(over: true, under: false, value: '92 %')));

        $this->assertSame(1, count($types));
        $stored = $this->repository->findOrArmed('probe');
        $this->assertSame(OperationalAlert::STATE_TRIGGERED, $stored->state);
        $this->assertSame('92 %', $stored->lastValue);
        $this->assertNotNull($stored->triggeredAt);
        $this->assertNotNull($stored->lastNotifiedAt);
    }

    /**
     * The failure this whole design exists to prevent: a check that speaks
     * on every pass is switched off within days, and is then not there on
     * the day it matters.
     */
    public function testStayingOverTheTriggerNeverNotifiesTwice(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));
        $check = self::check(over: true, under: false, value: '92 %');

        $service->evaluate($check);
        $service->evaluate($check);
        $service->evaluate($check);

        $this->assertSame(1, count($types), 'only the transition speaks');
    }

    /**
     * Between the two thresholds nothing moves. A single-threshold check
     * would notify on every pass here, which is exactly why there are two.
     */
    public function testOscillatingBetweenTheTwoThresholdsNeverNotifiesAgain(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $service->evaluate(self::check(over: true, under: false, value: '86 %'));
        // 84 %, 86 %, 84 % — over the re-arm, under and over the trigger.
        $service->evaluate(self::check(over: false, under: false, value: '84 %'));
        $service->evaluate(self::check(over: true, under: false, value: '86 %'));
        $service->evaluate(self::check(over: false, under: false, value: '84 %'));

        $this->assertSame(1, count($types));
        $this->assertSame(OperationalAlert::STATE_TRIGGERED, $this->repository->findOrArmed('probe')->state);
    }

    public function testComingBackUnderTheRearmArmsItAgainSilently(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $service->evaluate(self::check(over: true, under: false, value: '92 %'));
        $service->evaluate(self::check(over: false, under: true, value: '60 %'));

        $this->assertSame(1, count($types), 'recovery is not news');
        $stored = $this->repository->findOrArmed('probe');
        $this->assertSame(OperationalAlert::STATE_ARMED, $stored->state);
        $this->assertNull($stored->triggeredAt);
        $this->assertSame('60 %', $stored->lastValue);
    }

    public function testItCanTriggerAgainAfterRearming(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $service->evaluate(self::check(over: true, under: false, value: '92 %'));
        $service->evaluate(self::check(over: false, under: true, value: '60 %'));
        $service->evaluate(self::check(over: true, under: false, value: '91 %'));

        $this->assertSame(2, count($types));
    }

    /**
     * A check that cannot tell must leave a triggered alert triggered.
     * Treating "I don't know" as "all clear" would silently clear an alert
     * on exactly the installations least able to notice.
     */
    public function testAnInconclusiveReadingLeavesATriggeredAlertAlone(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $service->evaluate(self::check(over: true, under: false, value: '92 %'));
        $service->evaluate(new StubCheck(AlertReading::inconclusive()));

        $this->assertSame(OperationalAlert::STATE_TRIGGERED, $this->repository->findOrArmed('probe')->state);
        $this->assertSame(1, count($types));
    }

    /**
     * ...and it must leave the FIGURE alone too, not only the state.
     *
     * `AlertReading::inconclusive()` carries an empty value. Recording it
     * unconditionally blanked what a still-triggered alert was showing, so
     * the attention point fell from « Espace disque : 92 % » to a bare
     * « Espace disque » the first time the host stopped answering — the
     * exact opposite of what that value is stored for, and silent.
     */
    public function testAnInconclusiveReadingDoesNotBlankTheFigureStillOnScreen(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $service->evaluate(self::check(over: true, under: false, value: '92 %'));
        $this->assertSame('92 %', $this->repository->findOrArmed('probe')->lastValue);

        $service->evaluate(new StubCheck(AlertReading::inconclusive()));

        $this->assertSame(
            '92 %',
            $this->repository->findOrArmed('probe')->lastValue,
            'A check that cannot tell must not erase what the last one could.'
        );
    }

    /** A healthy site writes no row at all — an absent row and an armed row say the same thing. */
    public function testAHealthySiteLeavesTheTableEmpty(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $service->evaluate(self::check(over: false, under: true, value: '12 %'));

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM operational_alerts')->fetchColumn());
    }

    // ————— Le routage des canaux —————

    /**
     * The alert about e-mail not working must not be sent by e-mail — and
     * its own failed send would be journaled as `mail_send_failed`,
     * inflating the very count that raised it.
     */
    public function testTheMailAlertUsesTheTypeWhoseEmailChannelIsLockedOff(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $service->evaluate(new StubCheck(
            new AlertReading(true, false, '9 échecs', 'Titre', 'Pourquoi'),
            \Core\Alert\Check\MailDeliveryCheck::KEY
        ));

        $this->assertSame([OperationalAlertService::TYPE_MAIL], $types);
    }

    /**
     * The two alerts the outbound chantier adds take the same road, and
     * the authentication one is the sharpest case of all: it fires
     * precisely when nothing on the site can carry a message, so an
     * e-mail about it is the one message guaranteed not to arrive.
     */
    public function testTheOutboundAlertsAlsoAvoidEmail(): void
    {
        foreach ([
            \Core\Alert\Check\AuthenticationLaneCheck::KEY,
            \Core\Alert\Check\DeferredMailBacklogCheck::KEY,
        ] as $key) {
            $types = [];
            $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

            $service->evaluate(new StubCheck(
                new AlertReading(true, false, 'valeur', 'Titre', 'Pourquoi'),
                $key
            ));

            $this->assertSame([OperationalAlertService::TYPE_MAIL], $types, $key);
        }
    }

    public function testEveryOtherAlertUsesTheOrdinaryType(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $service->evaluate(self::check(over: true, under: false, value: '92 %'));

        $this->assertSame([OperationalAlertService::TYPE_DEFAULT], $types);
    }

    // ————— Un contrôle cassé n'arrête pas les autres —————

    public function testAThrowingCheckIsSkippedAndTheRestStillRun(): void
    {
        $types = [];
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types));

        $triggered = $service->run([
            new ThrowingCheck(),
            self::check(over: true, under: false, value: '92 %'),
        ]);

        $this->assertSame(1, $triggered);
        $this->assertSame(1, count($types));
    }

    /**
     * Issue #1358 — the journal page is `role_min: admin`, and a host
     * security reading (exact version, advisory count and identifiers) is
     * for superadmins alone. Its trigger and its growth are journaled by
     * key only; an ordinary alert keeps its title and value.
     */
    public function testAHostSecurityAlertIsJournaledWithoutItsReading(): void
    {
        $journalRepository = new JournalRepository($this->pdo);
        $types = [];
        $service = new OperationalAlertService(
            $this->repository,
            $this->notificationsRecording($types),
            new JournalService($journalRepository)
        );
        $hostKey = (string) array_key_first(HostSecurityAlertCheck::labels());
        $reading = static fn(array $subjects): AlertReading => new AlertReading(
            true,
            false,
            '8.4.1 — 1 avis',
            'PHP du site 8.4.1 : mise à jour de sécurité nécessaire',
            'Pourquoi',
            null,
            null,
            $subjects
        );

        $this->assertTrue($service->evaluate(new StubCheck($reading(['CVE-2026-0001']), $hostKey)));
        $this->assertTrue($service->evaluate(new StubCheck($reading(['CVE-2026-0001', 'CVE-2026-0002']), $hostKey)));
        $this->assertTrue($service->evaluate(self::check(over: true, under: false, value: '92 %')));

        foreach (['operational_alert_triggered', 'operational_alert_grew'] as $event) {
            $entry = $journalRepository->findLatestEvent('core', $event);
            $this->assertNotNull($entry, $event);
            $logged = json_encode($entry, JSON_UNESCAPED_UNICODE);
            if ($event === 'operational_alert_triggered') {
                // The latest trigger is the ordinary probe: untouched.
                $this->assertStringContainsString('92 %', (string) $logged);
                continue;
            }
            $this->assertStringContainsString($hostKey, (string) $logged);
            $this->assertStringNotContainsString('8.4.1', (string) $logged);
            $this->assertStringNotContainsString('CVE-2026', (string) $logged);
        }

        $all = json_encode($this->pdo->query("SELECT * FROM event_log")->fetchAll(\PDO::FETCH_ASSOC), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('8.4.1', (string) $all);
        $this->assertStringNotContainsString('CVE-2026', (string) $all);
    }

    // ————— Une panne du journal ou de l'envoi ne perd pas l'annonce (revue de #1415) —————

    /**
     * A journal that refuses its row can no longer stand between the
     * transition and its notification, on either path: the first trigger
     * and a grown subject set.
     */
    public function testAFailingJournalNeverSwallowsTheNotification(): void
    {
        $types = [];
        $journal = $this->createStub(\Core\Journal\JournalService::class);
        $journal->method('log')->willThrowException(new \PDOException('journal down'));
        $service = new OperationalAlertService($this->repository, $this->notificationsRecording($types), $journal);

        $this->assertTrue($service->evaluate(self::withSubjects(['CVE-2026-0001'])));
        $this->assertTrue($service->evaluate(self::withSubjects(['CVE-2026-0001', 'CVE-2026-0002'])));

        $this->assertCount(2, $types);
        $this->assertSame(['CVE-2026-0001', 'CVE-2026-0002'], $this->repository->findOrArmed('probe')->notifiedSubjects);
    }

    /**
     * A notification that cannot be handed off leaves nothing recorded as
     * announced: the next pass announces it instead of believing it done.
     */
    public function testAFailedHandOffIsRetriedOnTheNextPass(): void
    {
        $failing = $this->createStub(NotificationService::class);
        $failing->method('recipientsForType')->willReturn([['userAccountId' => 1, 'memberId' => null]]);
        $failing->method('dispatch')->willThrowException(new \RuntimeException('queue down'));
        $types = [];
        $working = $this->notificationsRecording($types);

        // First trigger: the failure leaves the alert armed.
        try {
            (new OperationalAlertService($this->repository, $failing))->evaluate(self::withSubjects(['CVE-2026-0001']));
        } catch (\RuntimeException) {
        }
        $this->assertFalse($this->repository->findOrArmed('probe')->isTriggered());
        $this->assertTrue((new OperationalAlertService($this->repository, $working))->evaluate(self::withSubjects(['CVE-2026-0001'])));

        // Growth: the failure leaves the new subject unannounced.
        try {
            (new OperationalAlertService($this->repository, $failing))->evaluate(self::withSubjects(['CVE-2026-0001', 'CVE-2026-0002']));
        } catch (\RuntimeException) {
        }
        $this->assertSame(['CVE-2026-0001'], $this->repository->findOrArmed('probe')->notifiedSubjects);
        $this->assertTrue((new OperationalAlertService($this->repository, $working))->evaluate(self::withSubjects(['CVE-2026-0001', 'CVE-2026-0002'])));
        $this->assertCount(2, $types);
    }

    /** @param list<string> $subjects */
    private static function withSubjects(array $subjects): OperationalCheck
    {
        return new StubCheck(new AlertReading(true, false, 'v', 'Titre', 'Pourquoi', null, null, $subjects));
    }

    private static function check(bool $over, bool $under, string $value): OperationalCheck
    {
        return new StubCheck(new AlertReading($over, $under, $value, 'Titre', 'Pourquoi'));
    }
}

final class StubCheck implements OperationalCheck
{
    public function __construct(
        private readonly AlertReading $reading,
        private readonly string $key = 'probe'
    ) {
    }

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return 'Sonde';
    }

    public function read(): AlertReading
    {
        return $this->reading;
    }
}

final class ThrowingCheck implements OperationalCheck
{
    public function key(): string
    {
        return 'broken';
    }

    public function label(): string
    {
        return 'Cassé';
    }

    public function read(): AlertReading
    {
        throw new \RuntimeException('this check is broken');
    }
}
