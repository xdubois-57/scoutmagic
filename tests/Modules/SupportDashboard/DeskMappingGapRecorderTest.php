<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Notification\NotificationService;
use Modules\SupportDashboard\Repository\DeskMappingGapRepository;
use Modules\SupportDashboard\Service\DeskMappingGapRecorder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * One notification per value, ever (issue #356, D8).
 *
 * The rule exists because a receiver holds a report from every
 * installation and gets a fresh one every morning: without `notified_at`,
 * a value four units carry would announce itself four times a day until
 * somebody turned the notification off — which is the failure mode this
 * whole chantier is trying to avoid, arrived at from the other side.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class DeskMappingGapRecorderTest extends TestCase
{
    private \PDO $pdo;
    private DeskMappingGapRepository $gaps;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);
        $this->gaps = new DeskMappingGapRepository($this->pdo);
    }

    public function testAValueThisReceiverHasNeverSeenIsRemembered(): void
    {
        $this->recorder()->record($this->payload([['function', 'Animateur Nutons']]));

        $stored = $this->gaps->findAllKeyed();
        $this->assertArrayHasKey('function|animateur nutons', $stored);
        $this->assertSame('Animateur Nutons', $stored['function|animateur nutons']['value_raw']);
    }

    public function testTheSameValueArrivingAgainTomorrowRemembersNothingNew(): void
    {
        $recorder = $this->recorder();
        $recorder->record($this->payload([['function', 'Animateur Nutons']]));
        $recorder->record($this->payload([['function', 'Animateur Nutons']]));

        $this->assertCount(1, $this->gaps->findAllKeyed());
        $this->assertSame(
            1,
            $this->journalCount('desk_mapping_unknown'),
            'the second report must announce nothing — the value is not new any more'
        );
    }

    /**
     * Two units spelling it differently is still one value, so it is
     * remembered — and announced — once (D7).
     */
    public function testTwoSpellingsAnnounceOnce(): void
    {
        $recorder = $this->recorder();
        $recorder->record($this->payload([['function', 'Animateur Nutons']]));
        $recorder->record($this->payload([['function', 'ANIMATEUR NUTONS']]));

        $this->assertCount(1, $this->gaps->findAllKeyed());
        $this->assertSame(1, $this->journalCount('desk_mapping_unknown'));
    }

    /**
     * A value this receiver's own code recognises is one a release has
     * already fixed. Recording it would mean one notification per
     * installation that has not upgraded yet — the notification would then
     * be saying « somebody is behind », which is not what it is for.
     */
    public function testAValueThisVersionAlreadyKnowsIsNeitherRememberedNorAnnounced(): void
    {
        $this->recorder()->record($this->payload([['branch', 'Baladins']]));

        $this->assertSame([], $this->gaps->findAllKeyed());
        $this->assertSame(0, $this->journalCount('desk_mapping_unknown'));
    }

    public function testAReportWithNothingUnresolvedAnnouncesNothing(): void
    {
        $this->recorder()->record($this->payload([]));

        $this->assertSame(0, $this->journalCount('desk_mapping_unknown'));
    }

    /**
     * Nobody subscribed means nothing was announced, so the rows must stay
     * un-notified rather than be marked as though they had been — the next
     * report is then the one that tells whoever subscribes in between.
     */
    public function testWithNoRecipientTheValueStaysWaitingToBeAnnounced(): void
    {
        $this->recorder()->record($this->payload([['function', 'Animateur Nutons']]));

        $stored = $this->gaps->findAllKeyed()['function|animateur nutons'];
        $this->assertNull($stored['notified_at']);
        $this->assertNotSame([], $this->gaps->idsAwaitingNotification());
    }

    private function recorder(?NotificationService $notifications = null): DeskMappingGapRecorder
    {
        // No NotificationService by default: this receiver has no
        // subscriber, which is the ordinary state of a fresh test database
        // and, on a real one, of a maintainer who has not switched the
        // type on.
        return new DeskMappingGapRecorder(
            $this->gaps,
            new JournalService(new JournalRepository($this->pdo)),
            $notifications
        );
    }

    /**
     * A notification service that records what it was asked to send, and
     * whose subscriber list the test decides.
     *
     * Subclassed rather than mocked because what matters here is the
     * BODY — the count it claims — against the rows marked afterwards, and
     * the two came apart in a way no assertion on « dispatch was called »
     * would have caught.
     */
    private function notifier(bool $withRecipient): NotificationService
    {
        return new class ($withRecipient) extends NotificationService {
            /** @var list<array<string, mixed>> */
            public array $sent = [];

            public function __construct(private bool $withRecipient)
            {
            }

            public function recipientsForType(string $typeId): array
            {
                return $this->withRecipient ? [1] : [];
            }

            public function dispatch(
                string $typeId,
                array $recipients,
                array $payload,
                ?int $actorUserAccountId = null
            ): void {
                $this->sent[] = $payload;
            }
        };
    }

    /**
     * @param list<array{0: string, 1: string}> $unresolved
     * @return array<string, mixed>
     */
    private function payload(array $unresolved): array
    {
        return [
            'statistics_schema_version' => 1,
            'installation_id' => 'aaaabbbbccccdddd',
            'desk_unresolved' => [
                'total' => count($unresolved),
                'listed' => array_map(
                    static fn(array $pair): array => ['kind' => $pair[0], 'value' => $pair[1]],
                    $unresolved
                ),
            ],
        ];
    }

    private function journalCount(string $eventType): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $stmt->execute([$eventType]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * The hole that « le prochain rapport les annoncera » claimed was not
     * there, and was.
     *
     * A value first seen while nobody was subscribed stayed un-notified,
     * correctly — but the announcement only ever ran on the values THIS
     * report brought in. So the second report, carrying the same value,
     * found nothing new, returned before announcing anything, and the
     * value was never announced by any later report however many carried
     * it. Driving the announcement off the backlog instead of off this
     * call's new values is what fixes it.
     */
    public function testAValueSeenWhileNobodyWasSubscribedIsAnnouncedToWhoeverSubscribesLater(): void
    {
        $this->recorder($this->notifier(false))->record($this->payload([['function', 'Animateur Nutons']]));
        $this->assertNotSame([], $this->gaps->idsAwaitingNotification(), 'nothing was announced, so it still waits');

        // The next morning, same value, and by now somebody has switched
        // the notification on.
        $notifier = $this->notifier(true);
        $this->recorder($notifier)->record($this->payload([['function', 'Animateur Nutons']]));

        $this->assertCount(1, $notifier->sent, 'the waiting value must be announced even though nothing is new');
        $this->assertSame([], $this->gaps->idsAwaitingNotification());
        $this->assertNotNull($this->gaps->findAllKeyed()['function|animateur nutons']['notified_at']);
    }

    /**
     * And the second half of the same hole: the message counted only the
     * new values while marking EVERY waiting row as notified. A value that
     * had been waiting was then flagged as announced by a message that
     * never mentioned it — silently, and for good.
     */
    public function testTheMessageCountsExactlyTheRowsItMarks(): void
    {
        $this->recorder($this->notifier(false))->record($this->payload([['function', 'Animateur Nutons']]));

        $notifier = $this->notifier(true);
        $this->recorder($notifier)->record($this->payload([
            ['function', 'Animateur Nutons'],
            ['branch', 'Nutons'],
        ]));

        $this->assertCount(1, $notifier->sent);
        $this->assertStringContainsString(
            '2 nouvelles valeurs',
            (string) $notifier->sent[0]['body'],
            'the body must count the waiting value as well as the new one, since it marks both as announced'
        );
        $this->assertSame([], $this->gaps->idsAwaitingNotification());
    }

    /**
     * Set aside counts as answered. A value can wait un-notified for a
     * while and be judged on the page in the meantime; announcing it
     * afterwards would be telling somebody about a value they have already
     * dismissed.
     */
    public function testAValueSetAsideWhileWaitingIsNeverAnnounced(): void
    {
        $this->recorder($this->notifier(false))->record($this->payload([['function', 'Animateur Nutons']]));
        $this->gaps->setIgnored($this->gaps->findAllKeyed()['function|animateur nutons']['id'], true);

        $notifier = $this->notifier(true);
        $this->recorder($notifier)->record($this->payload([['function', 'Animateur Nutons']]));

        $this->assertSame([], $notifier->sent);
    }

    /**
     * One value, one notification, still — the rule the backlog change had
     * every opportunity to break.
     */
    public function testAValueAlreadyAnnouncedIsNotAnnouncedAgainTomorrow(): void
    {
        $notifier = $this->notifier(true);
        $recorder = $this->recorder($notifier);

        $recorder->record($this->payload([['function', 'Animateur Nutons']]));
        $recorder->record($this->payload([['function', 'Animateur Nutons']]));
        $recorder->record($this->payload([['function', 'ANIMATEUR NUTONS']]));

        $this->assertCount(1, $notifier->sent);
    }
}
