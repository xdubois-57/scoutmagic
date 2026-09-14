<?php

declare(strict_types=1);

namespace Tests\Core\Alert\Check;

use Core\Alert\Check\AuthenticationLaneCheck;
use Core\Alert\Check\DeferredMailBacklogCheck;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Mail\MailPurpose;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The two alerts the outbound chantier adds (D9, ARCHITECTURE.md §8.106).
 *
 * They watch the two ways this machinery fails silently. The deferral
 * queue fails by working: every sender was told their message left, and
 * none of them has. The authentication lane fails by emptying, and the
 * person who would come and repair it is the person who can no longer
 * sign in.
 *
 * What is pinned below is each one's comparison, and — the part that
 * matters more — what each does when it cannot tell, and by which road
 * the news travels.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class OutboundMailChecksTest extends TestCase
{
    private \PDO $pdo;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private SettingService $settings;
    private DeferredMailRepository $deferred;

    /** @var array<string, string> */
    private array $secrets = [];

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->deferred = new DeferredMailRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
    }

    // ── la voie d'authentification ────────────────────────────────────

    /**
     * The ordinary installation: the local send is in the lane, and the
     * local send is always usable. Nothing to say.
     */
    public function testALaneWithTheLocalSendInItIsNotAnAlert(): void
    {
        $this->chains->append(MailLane::Authentication, MailProvider::LOCAL_ID, true);

        $reading = $this->laneCheck()->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('1 fournisseur', $reading->value);
    }

    /**
     * **The alert the whole chantier exists for.** An empty
     * authentication lane is not a degraded site: it is a site nobody can
     * get into, including whoever would come and put an entry back.
     */
    public function testAnEmptyAuthenticationLaneTriggers(): void
    {
        $reading = $this->laneCheck()->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
        $this->assertSame('0 fournisseur', $reading->value);
        $this->assertStringContainsString('plus personne ne peut se connecter', $reading->why);
        $this->assertSame('/config/courrier-sortant/acheminement', $reading->actionUrl);
    }

    /**
     * An entry that is in the lane but switched off is not an entry: the
     * chain skips it, so counting it would report a lane that works while
     * nothing can leave.
     */
    public function testADisabledEntryDoesNotCount(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test');
        $this->chains->append(MailLane::Authentication, $relay, false);

        $this->assertTrue($this->laneCheck()->read()->overTrigger);
    }

    /**
     * A relay row whose credentials never made it into `secrets.enc` has
     * no host, and a transport with no host connects to nothing. The row
     * exists; the way out does not.
     */
    public function testARelayWithNoHostDoesNotCount(): void
    {
        $id = $this->providers->create('Relais orphelin', null, 50, 10);
        $this->chains->append(MailLane::Authentication, $id, true);

        $this->assertTrue($this->laneCheck()->read()->overTrigger);
    }

    /**
     * The lane is read on its own: a Transactional chain full of healthy
     * relays says nothing about whether a sign-in link can leave.
     */
    public function testItReadsTheAuthenticationLaneAndNoOther(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test');
        $this->chains->append(MailLane::Transactional, $relay, true);
        $this->chains->append(MailLane::Bulk, $relay, true);

        $this->assertTrue($this->laneCheck()->read()->overTrigger);
    }

    /**
     * The table is gone — mid-migration, or a broken deployment. That is
     * « I could not tell », which is neither an alarm nor an all-clear:
     * raising one would cry wolf, and clearing one would take away a
     * warning somebody still needs.
     */
    public function testItStaysInconclusiveWhenTheLaneCannotBeRead(): void
    {
        $this->pdo->exec('DROP TABLE mail_lane_entries');

        $reading = $this->laneCheck()->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    // ── la file des messages différés ─────────────────────────────────

    /** A few messages waiting for the next pass is the mechanism working. */
    public function testAShallowQueueIsNotAnAlert(): void
    {
        $this->queue(3);

        $reading = $this->backlogCheck()->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertTrue($reading->underRearm);
        $this->assertSame('3 en attente', $reading->value);
    }

    /**
     * Deep enough that nothing but a lane that has stopped draining
     * produces it.
     */
    public function testADeepQueueTriggers(): void
    {
        $this->queue(DeferredMailBacklogCheck::TRIGGER_COUNT);

        $reading = $this->backlogCheck()->read();

        $this->assertTrue($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
        $this->assertStringContainsString('l\'expéditeur a vu un envoi réussi', $reading->why);
        $this->assertSame('/config/courrier-sortant', $reading->actionUrl);
    }

    /**
     * Between the two thresholds the alert neither fires nor clears — the
     * gap that stops it flickering on and off while a backlog drains
     * past its own number.
     */
    public function testBetweenTheTwoThresholdsNothingChanges(): void
    {
        $this->queue(DeferredMailBacklogCheck::REARM_COUNT + 1);

        $reading = $this->backlogCheck()->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    /**
     * It counts what is still waiting. An abandoned message is a message
     * nobody is going to send any more: leaving it in the total would
     * keep the alarm ringing about a queue that has stopped growing.
     */
    public function testAbandonedMessagesAreNotWaiting(): void
    {
        $ids = $this->queue(DeferredMailBacklogCheck::TRIGGER_COUNT);
        foreach (array_slice($ids, 0, DeferredMailBacklogCheck::TRIGGER_COUNT - 2) as $id) {
            $this->deferred->abandon($id, 3, 'expiré');
        }

        $this->assertFalse($this->backlogCheck()->read()->overTrigger);
    }

    /** Every lane's queue is one queue as far as this alert is concerned. */
    public function testItAddsTheLanesTogether(): void
    {
        $this->queue(2, MailLane::Transactional);
        $this->queue(3, MailLane::Bulk);

        $this->assertSame('5 en attente', $this->backlogCheck()->read()->value);
    }

    public function testItStaysInconclusiveWhenTheQueueCannotBeRead(): void
    {
        $this->pdo->exec('DROP TABLE mail_deferred_messages');

        $reading = $this->backlogCheck()->read();

        $this->assertFalse($reading->overTrigger);
        $this->assertFalse($reading->underRearm);
    }

    // ── helpers ───────────────────────────────────────────────────────

    private function laneCheck(): AuthenticationLaneCheck
    {
        return new AuthenticationLaneCheck(
            $this->chains,
            new MailProviderDirectory(
                $this->providers,
                new ProviderConnections($this->secrets),
                $this->settings
            )
        );
    }

    private function backlogCheck(): DeferredMailBacklogCheck
    {
        return new DeferredMailBacklogCheck($this->deferred);
    }

    private function addRelay(string $name, string $host): int
    {
        $id = $this->providers->create($name, null, 50, 10);
        $prefix = ProviderConnections::prefixFor($id);
        $this->secrets[$prefix . '_host'] = $host;
        $this->secrets[$prefix . '_port'] = '587';
        $this->secrets[$prefix . '_user'] = 'user@' . $host;

        return $id;
    }

    /**
     * @return array{
     *     to: string, subject: string, bodyHtml: string, bodyText: string,
     *     replyTo: ?string, fromAddressOverride: ?string, fromNameOverride: ?string,
     *     extraHeaders: array<string, string>,
     *     attachments: array<int, array{name: string, content: string}>
     * }
     */
    private function payload(): array
    {
        return [
            'to' => 'parent@exemple.test',
            'subject' => 'Sujet',
            'bodyHtml' => '<p>Bonjour</p>',
            'bodyText' => 'Bonjour',
            'replyTo' => null,
            'fromAddressOverride' => null,
            'fromNameOverride' => null,
            'extraHeaders' => [],
            'attachments' => [],
        ];
    }

    /**
     * @return array<int, int> the ids queued
     */
    private function queue(int $count, MailLane $lane = MailLane::Transactional): array
    {
        $ids = [];
        for ($i = 0; $i < $count; $i++) {
            $ids[] = $this->deferred->add(
                $lane,
                MailPurpose::Ordinary,
                $this->payload(),
                'quota épuisé',
                '2026-09-14 10:00:00',
                '2026-09-15 10:00:00'
            );
        }

        return $ids;
    }
}
