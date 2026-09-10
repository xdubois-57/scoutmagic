<?php

declare(strict_types=1);

namespace Tests\Core\Alert;

use Core\Alert\AlertReading;
use Core\Alert\OperationalAlert;
use Core\Alert\OperationalAlertRepository;
use Core\Alert\OperationalAlertService;
use Core\Alert\OperationalCheck;
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
        $mock = $this->createMock(NotificationService::class);
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
