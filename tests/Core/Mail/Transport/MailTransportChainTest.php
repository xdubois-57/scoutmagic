<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailPurpose;
use Core\Mail\MailTransportInterface;
use Core\Mail\Transport\DomainPreferences;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\LaneExhaustedException;
use Core\Mail\Transport\ProviderHealth;
use Core\Mail\Transport\MailReserve;
use Core\Mail\Transport\ProviderHealthRepository;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\MailTransportChain;
use Core\Mail\Transport\ProviderConnections;
use Core\Mail\Transport\SendCounterRepository;
use Core\Mail\Transport\TransportConfigurator;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The chain that removes the site's single point of failure
 * (ARCHITECTURE.md §8.106).
 *
 * The failure it exists for is not hypothetical and it is not about
 * mail: one relay carried everything, so a relay that stopped answering
 * — or spent its free daily quota on a mailing to four hundred parents —
 * took the sign-in links down with it, and nobody could log in to repair
 * anything. Every case below is one half of that.
 *
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailTransportChainTest extends TestCase
{
    private \PDO $pdo;
    private MailProviderRepository $providers;
    private LaneChainRepository $chains;
    private SendCounterRepository $counters;
    private SettingService $settings;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->providers = new MailProviderRepository($this->pdo);
        $this->chains = new LaneChainRepository($this->pdo);
        $this->counters = new SendCounterRepository($this->pdo);
        $this->settings = new SettingService(new SettingRepository($this->pdo));
    }

    // ── falling back ──────────────────────────────────────────────────

    public function testItFallsBackToTheNextProviderWhenTheFirstOneRefuses(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Authentication, [$first, $second]);

        $delivery = $this->recordingTransport(refuseHosts: ['smtp.premier.test']);
        $this->chain($delivery)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(
            ['smtp.premier.test', 'smtp.second.test'],
            $delivery->attemptedHosts,
            'The first relay is tried, refuses, and the second carries the message.'
        );
        $this->assertSame(0, $this->counters->totalForProvider($first), 'A refusal counts nothing.');
        $this->assertSame(1, $this->counters->totalForProvider($second));
    }

    public function testItStepsOverAProviderThatHasSpentItsDailyQuota(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test', dailyQuota: 2);
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);

        $this->counters->increment($first, MailLane::Bulk);
        $this->counters->increment($first, MailLane::Bulk);

        $delivery = $this->recordingTransport();
        $this->chain($delivery)->deliver($this->message(), MailPurpose::Bulk);

        $this->assertSame(
            ['smtp.second.test'],
            $delivery->attemptedHosts,
            'A spent quota is not a failure — the exhausted relay is never even tried.'
        );
    }

    /**
     * A quota counts the provider's whole day, not one lane of it.
     *
     * The mailing is exactly what spends a free relay's allowance, and
     * the sign-in links share that allowance. Counting per lane would
     * make the authentication lane believe a relay was fresh the moment a
     * publipostage had emptied it.
     */
    public function testAQuotaCountsEveryLaneOfThatProvider(): void
    {
        $relay = $this->addRelay('Unique', 'smtp.unique.test', dailyQuota: 1);
        $this->enable(MailLane::Bulk, [$relay, MailProvider::LOCAL_ID]);
        $this->enable(MailLane::Authentication, [$relay, MailProvider::LOCAL_ID]);

        $this->counters->increment($relay, MailLane::Bulk);

        $delivery = $this->recordingTransport();
        $this->chain($delivery)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame([''], $delivery->attemptedHosts, 'The local send took over — the relay is spent.');
    }

    public function testEveryProviderRefusingIsAnError(): void
    {
        $only = $this->addRelay('Unique', 'smtp.unique.test');
        $this->enable(MailLane::Transactional, [$only]);

        $this->expectException(\RuntimeException::class);
        $this->chain($this->recordingTransport(refuseHosts: ['smtp.unique.test']))
            ->deliver($this->message(), MailPurpose::Ordinary);
    }

    // ── the lanes are separate ────────────────────────────────────────

    /**
     * The whole point of three chains: what the mailing lane does cannot
     * reach the authentication one.
     */
    public function testAMailingDoesNotTouchTheAuthenticationChain(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test');
        $this->enable(MailLane::Authentication, [$relay]);
        $this->enable(MailLane::Bulk, [MailProvider::LOCAL_ID]);

        $delivery = $this->recordingTransport();
        $chain = $this->chain($delivery);
        $chain->deliver($this->message(), MailPurpose::Bulk);
        $chain->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(['', 'smtp.relais.test'], $delivery->attemptedHosts);
    }

    /**
     * The cadence is a property of the mailing lane and of nothing else
     * (D6): an authentication or transactional message leaves
     * immediately, whatever a provider's batch size says.
     *
     * Three sign-in links in a row through a provider whose batch size is
     * 1 — all three go out, in the same call sequence, with nothing held
     * back.
     */
    public function testCadenceIsIgnoredOutsideTheMailingLane(): void
    {
        $relay = $this->addRelay('Lent', 'smtp.lent.test', batchSize: 1, batchIntervalMinutes: 60);
        $this->enable(MailLane::Authentication, [$relay]);
        $this->enable(MailLane::Transactional, [$relay]);

        $delivery = $this->recordingTransport();
        $chain = $this->chain($delivery);
        $chain->deliver($this->message(), MailPurpose::MagicLink);
        $chain->deliver($this->message(), MailPurpose::MagicLink);
        $chain->deliver($this->message(), MailPurpose::Ordinary);

        $this->assertCount(3, $delivery->attemptedHosts);
        $this->assertSame(3, $this->counters->totalForProvider($relay));
    }

    // ── degradation ───────────────────────────────────────────────────

    /**
     * A chain that cannot be read is not an empty chain.
     *
     * On an installation whose tables do not exist yet — a setup wizard
     * mid-flight — refusing to send would be the wrong answer: the
     * message goes out through whatever MailService had already
     * configured, exactly as before any of this existed.
     */
    public function testAnInstallationWithNoChainAtAllStillSends(): void
    {
        $delivery = $this->recordingTransport();
        $this->chain($delivery)->deliver($this->message(), MailPurpose::Ordinary);

        $this->assertCount(1, $delivery->attemptedHosts, 'No chain means "send as before", never "refuse".');
    }

    /**
     * The third state of the chain, and the one the other two are easy to
     * confuse it with: the lane IS configured, and nothing in it can
     * carry a message. That is an administrator's mistake rather than an
     * installation mid-flight, so it is an error — never a quiet
     * delegation to the relay the chain was built to stop using.
     */
    public function testALaneWhoseEveryEntryIsDisabledIsAnErrorRatherThanADelegation(): void
    {
        $relay = $this->addRelay('Désactivé partout', 'smtp.off.test');
        $this->chains->append(MailLane::Transactional, $relay, false);
        $this->chains->append(MailLane::Transactional, MailProvider::LOCAL_ID, false);

        $delivery = $this->recordingTransport();

        try {
            $this->chain($delivery)->deliver($this->message(), MailPurpose::Ordinary);
            $this->fail('A lane with no usable entry must refuse rather than send.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Transactionnel', $e->getMessage());
        }

        $this->assertSame([], $delivery->attemptedHosts, 'Nothing was attempted.');
    }

    public function testADisabledEntryIsNeverTried(): void
    {
        $first = $this->addRelay('Désactivé', 'smtp.off.test');
        $second = $this->addRelay('Actif', 'smtp.on.test');
        $this->chains->append(MailLane::Transactional, $first, false);
        $this->chains->append(MailLane::Transactional, $second, true);

        $delivery = $this->recordingTransport();
        $this->chain($delivery)->deliver($this->message(), MailPurpose::Ordinary);

        $this->assertSame(['smtp.on.test'], $delivery->attemptedHosts);
    }

    /**
     * A relay somebody added and never finished configuring is skipped
     * rather than tried: PHPMailer with an empty Host fails slowly, and
     * the lane behind it would pay for it on every message.
     */
    public function testARelayWithNoHostIsSkipped(): void
    {
        $unconfigured = $this->providers->create('Jamais fini', null, 50, 10);
        $this->chains->append(MailLane::Transactional, $unconfigured, true);
        $this->chains->append(MailLane::Transactional, MailProvider::LOCAL_ID, true);

        $delivery = $this->recordingTransport();
        $this->chain($delivery)->deliver($this->message(), MailPurpose::Ordinary);

        $this->assertSame([''], $delivery->attemptedHosts);
    }

    // ── helpers ───────────────────────────────────────────────────────

    /** @var array<string, string> */
    private array $secrets = [];

    // ── routing by recipient domain (roadmap IT-07, D13) ──────────────

    /**
     * A decision taken on the « Boîtes témoins » page reaches the send
     * path, and reaches it on the mailing lane.
     */
    public function testTheMailingLaneTriesThisDomainsPreferredRelayFirst(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);

        $delivery = $this->recordingTransport();
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second))
            ->deliver($this->message('famille@gmail.com'), MailPurpose::Bulk);

        $this->assertSame(['smtp.second.test'], $delivery->attemptedHosts);
    }

    /**
     * **The rule of D13 nothing may relax.** A magic link lives fifteen
     * minutes; a login path that varies with the recipient's provider is
     * a login path nobody can reason about, and this is the seam where
     * relaxing it would be invisible.
     */
    public function testAMagicLinkTakesTheSameRoadWhoeverIsReceivingIt(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Authentication, [$first, $second]);

        $delivery = $this->recordingTransport();
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second))
            ->deliver($this->message('famille@gmail.com'), MailPurpose::MagicLink);

        $this->assertSame(['smtp.premier.test'], $delivery->attemptedHosts);
    }

    /**
     * **A preference costs no fallback.** The preferred relay refuses,
     * and the message still leaves through the one the lane would have
     * used — otherwise routing a domain would quietly spend its second
     * chance.
     */
    public function testAPreferredRelayThatRefusesFallsBackToTheRestOfTheChain(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);

        $delivery = $this->recordingTransport(refuseHosts: ['smtp.second.test']);
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second))
            ->deliver($this->message('famille@gmail.com'), MailPurpose::Bulk);

        $this->assertSame(['smtp.second.test', 'smtp.premier.test'], $delivery->attemptedHosts);
    }

    /**
     * **A preference never outranks a spent quota.** The lane dropped
     * that relay for a reason no routing decision knows better than.
     */
    public function testAPreferenceCannotBringBackARelayTheLaneDropped(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test', dailyQuota: 1);
        $this->enable(MailLane::Bulk, [$first, $second]);
        $this->counters->increment($second, MailLane::Bulk);

        $delivery = $this->recordingTransport();
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second))
            ->deliver($this->message('famille@gmail.com'), MailPurpose::Bulk);

        $this->assertSame(['smtp.premier.test'], $delivery->attemptedHosts);
    }

    /** A domain nobody decided on keeps the lane's own order. */
    public function testAnUndecidedDomainIsNotRouted(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);

        $delivery = $this->recordingTransport();
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second))
            ->deliver($this->message('famille@laposte.net'), MailPurpose::Bulk);

        $this->assertSame(['smtp.premier.test'], $delivery->attemptedHosts);
    }

    /**
     * **A message with several recipients is not one this reading has an
     * opinion about.** A mailing sends one message per member; routing a
     * batch by the first address in it would send the rest through a
     * relay chosen for somebody else's provider.
     */
    public function testAMessageWithSeveralRecipientsIsNotRouted(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);

        $mail = $this->message('famille@gmail.com');
        $mail->addAddress('autre@laposte.net');

        $delivery = $this->recordingTransport();
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second))
            ->deliver($mail, MailPurpose::Bulk);

        $this->assertSame(['smtp.premier.test'], $delivery->attemptedHosts);
    }

    private function preferring(string $domain, int $providerId): DomainPreferences
    {
        $this->settings->register(
            DomainPreferences::SETTING_KEY,
            '',
            'text',
            'Routage par domaine',
            '',
            null,
            null,
            null,
            false,
            60
        );
        $preferences = new DomainPreferences($this->settings);
        $preferences->prefer($domain, $providerId);

        return $preferences;
    }

    private function addRelay(
        string $name,
        string $host,
        ?int $dailyQuota = null,
        int $batchSize = 50,
        int $batchIntervalMinutes = 10
    ): int {
        $id = $this->providers->create($name, $dailyQuota, $batchSize, $batchIntervalMinutes);
        $prefix = ProviderConnections::prefixFor($id);
        $this->secrets[$prefix . '_host'] = $host;
        $this->secrets[$prefix . '_port'] = '587';
        $this->secrets[$prefix . '_user'] = 'user@' . $host;
        $this->secrets[$prefix . '_password'] = 'secret';

        return $id;
    }

    /**
     * @param array<int, int> $providerIds
     */
    private function enable(MailLane $lane, array $providerIds): void
    {
        foreach ($providerIds as $providerId) {
            $this->chains->append($lane, $providerId, true);
        }
    }

    // ── the circuit breaker (D15) ─────────────────────────────────────

    /**
     * Three failures in a row that are the provider's own, and the lane
     * stops trying it — which is the point: one publipostage of four
     * hundred would otherwise retry a dead relay four hundred times.
     */
    public function testAProviderThatKeepsFailingIsSteppedOver(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Authentication, [$first, $second]);
        $health = new ProviderHealthRepository($this->pdo);

        $delivery = $this->recordingTransport(refuseHosts: ['smtp.premier.test']);
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $this->chain($delivery, $health)->deliver($this->message(), MailPurpose::MagicLink);
        }

        $delivery->attemptedHosts = [];
        $this->chain($delivery, $health)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(
            ['smtp.second.test'],
            $delivery->attemptedHosts,
            'The shut-out relay is not even attempted.'
        );
    }

    /**
     * A `550` is the relay working correctly, about one address. Counting
     * it would shut a provider out because somebody mistyped an e-mail —
     * and on the mailing lane, where dead addresses collect, nearly every
     * run would do it.
     */
    public function testARejectedRecipientNeverOpensTheCircuit(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Authentication, [$first, $second]);
        $health = new ProviderHealthRepository($this->pdo);

        $delivery = $this->recordingTransport(
            refuseHosts: ['smtp.premier.test'],
            refusalReason: 'SMTP Error: 550 5.1.1 Recipient address rejected'
        );
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN + 2; $i++) {
            $this->chain($delivery, $health)->deliver($this->message(), MailPurpose::MagicLink);
        }

        $this->assertFalse($health->forProvider($first)->isOpen());

        $delivery->attemptedHosts = [];
        $this->chain($delivery, $health)->deliver($this->message(), MailPurpose::MagicLink);
        $this->assertSame(['smtp.premier.test', 'smtp.second.test'], $delivery->attemptedHosts);
    }

    /**
     * **The imperative rule of D15.** A breaker still armed after the
     * outage was repaired, applied without this exception, locks every
     * member out of the site — including the super-admin who would have
     * come to fix it. That is the exact failure the chain exists to end.
     */
    public function testACircuitOpenOnEveryEntryStillTriesTheLastOne(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Authentication, [$first, $second]);
        $health = new ProviderHealthRepository($this->pdo);

        // Shut both of them out.
        $refusing = $this->recordingTransport(refuseHosts: ['smtp.premier.test', 'smtp.second.test']);
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            try {
                $this->chain($refusing, $health)->deliver($this->message(), MailPurpose::MagicLink);
            } catch (LaneExhaustedException) {
                // Expected: nothing was taking messages at that point.
            }
        }
        $this->assertTrue($health->forProvider($first)->isOpen());
        $this->assertTrue($health->forProvider($second)->isOpen());

        // The relays are repaired. The lane must not stay locked.
        $working = $this->recordingTransport();
        $this->chain($working, $health)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(
            ['smtp.second.test'],
            $working->attemptedHosts,
            'The last entry is tried anyway — and the last is where the local send sits in a real chain.'
        );
    }

    /** A relay that answers is a relay that works, whatever it did before. */
    public function testASuccessClosesTheCircuit(): void
    {
        $only = $this->addRelay('Premier', 'smtp.premier.test');
        $this->enable(MailLane::Authentication, [$only]);
        $health = new ProviderHealthRepository($this->pdo);

        $refusing = $this->recordingTransport(refuseHosts: ['smtp.premier.test']);
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            try {
                $this->chain($refusing, $health)->deliver($this->message(), MailPurpose::MagicLink);
            } catch (LaneExhaustedException) {
                // Expected.
            }
        }

        $this->chain($this->recordingTransport(), $health)->deliver($this->message(), MailPurpose::MagicLink);

        $reopened = $health->forProvider($only);
        $this->assertFalse($reopened->isOpen());
        $this->assertSame(0, $reopened->consecutiveFailures);
    }

    // ── the reserve on the mailing lane (D8) ──────────────────────────

    /**
     * The mailing lane may spend the quota MINUS the reserve; the lanes
     * the reserve protects still see the whole quota.
     */
    public function testTheMailingLaneStopsAtTheReserveWhileTheOthersDoNot(): void
    {
        $shared = $this->addRelay('Partagé', 'smtp.partage.test', dailyQuota: 100);
        $fallback = $this->addRelay('Secours', 'smtp.secours.test');
        $this->enable(MailLane::Bulk, [$shared, $fallback]);
        $this->enable(MailLane::Authentication, [$shared, $fallback]);

        // 60 already sent, a reserve of 50 (the floor is 30, but this site
        // has no history, so the floor applies and is under the cap).
        for ($i = 0; $i < 80; $i++) {
            $this->counters->increment($shared, MailLane::Bulk);
        }

        $reserve = new MailReserve($this->counters, $this->chains);
        $delivery = $this->recordingTransport();
        $this->chain($delivery, null, $reserve)->deliver($this->message(), MailPurpose::Bulk);

        $this->assertSame(
            ['smtp.secours.test'],
            $delivery->attemptedHosts,
            '80 sent against a ceiling of 100 - 30 reserved: the mailing steps over it.'
        );

        $delivery->attemptedHosts = [];
        $this->chain($delivery, null, $reserve)->deliver($this->message(), MailPurpose::MagicLink);
        $this->assertSame(
            ['smtp.partage.test'],
            $delivery->attemptedHosts,
            'The authentication lane sees the whole quota — the reserve is FOR it.'
        );
    }

    /**
     * A lane running out is a different event from one relay refusing,
     * and on the authentication lane it is a different KIND of event:
     * `security`, because nobody can enter the site — including whoever
     * would come and repair it.
     */
    public function testAnExhaustedAuthenticationLaneIsJournaledAsSecurity(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test');
        $this->enable(MailLane::Authentication, [$relay]);

        try {
            $this->chain($this->recordingTransport(refuseHosts: ['smtp.relais.test']))
                ->deliver($this->message(), MailPurpose::MagicLink);
            $this->fail('The lane had nothing left to try.');
        } catch (LaneExhaustedException) {
            // expected
        }

        $entry = $this->lastJournalEntry('mail_lane_exhausted');
        $this->assertSame('security', $entry['level']);
        $this->assertSame('authentication', json_decode((string) $entry['context'], true)['lane']);
    }

    /** The mailing lane running out is a bad day, not an incident. */
    public function testAnExhaustedBulkLaneIsJournaledAsInfo(): void
    {
        $relay = $this->addRelay('Relais', 'smtp.relais.test');
        $this->enable(MailLane::Bulk, [$relay]);

        try {
            $this->chain($this->recordingTransport(refuseHosts: ['smtp.relais.test']))
                ->deliver($this->message(), MailPurpose::Bulk);
            $this->fail('The lane had nothing left to try.');
        } catch (LaneExhaustedException) {
            // expected
        }

        $this->assertSame('info', $this->lastJournalEntry('mail_lane_exhausted')['level']);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastJournalEntry(string $type): array
    {
        $statement = $this->pdo->prepare(
            'SELECT level, context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1'
        );
        $statement->execute([$type]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row, 'No journal entry of type ' . $type . '.');

        return $row;
    }

    private function chain(
        MailTransportInterface $delivery,
        ?ProviderHealthRepository $health = null,
        ?MailReserve $reserve = null,
        ?DomainPreferences $preferences = null
    ): MailTransportChain {
        $connections = new ProviderConnections($this->secrets);

        return new MailTransportChain(
            new MailProviderDirectory($this->providers, $connections, $this->settings),
            $this->chains,
            $this->counters,
            new TransportConfigurator($connections),
            $delivery,
            new JournalService(new JournalRepository($this->pdo)),
            $health,
            $reserve,
            $preferences
        );
    }

    private function message(string $to = ''): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Sujet';
        if ($to !== '') {
            $mail->addAddress($to);
        }

        return $mail;
    }

    /**
     * A transport that records the host it was pointed at and refuses the
     * ones it was told to — the only observable the chain has, since
     * "which provider carried this" is exactly what it decides.
     *
     * @param array<int, string> $refuseHosts
     */
    private function recordingTransport(
        array $refuseHosts = [],
        string $refusalReason = 'SMTP connect() failed.'
    ): MailTransportInterface {
        return new class ($refuseHosts, $refusalReason) implements MailTransportInterface {
            /** @var array<int, string> */
            public array $attemptedHosts = [];

            /** @param array<int, string> $refuseHosts */
            public function __construct(private array $refuseHosts, private string $refusalReason)
            {
            }

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->attemptedHosts[] = $mail->Host;

                if (in_array($mail->Host, $this->refuseHosts, true)) {
                    throw new \RuntimeException($this->refusalReason);
                }
            }
        };
    }
}
