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
     * The same three states of the chain, reached the other way: not « no
     * chain laid down » but « the chain cannot be read at all ».
     *
     * A table a migration has not created yet, an account whose SELECT
     * grant was never applied: `candidates()` answers null for that just
     * as it does for an empty chain, so an unreadable chain is « send as
     * before » and not the configured-and-unusable error above. That is
     * the right choice — a sign-in link must not wait for a migration —
     * but it IS a choice, and a test asserting only that the message went
     * out would be equally true of the empty chain covered above.
     *
     * Hence the two halves. The first delivery establishes that this
     * installation has a chain and is routed by it; the second, after the
     * table is gone, records `localhost` — PHPMailer's untouched default,
     * never anything the chain chose — which is the observable signature
     * of the fallback: `TransportConfigurator::apply()` was not reached,
     * so the message left through the transport `MailService` had already
     * configured.
     */
    public function testAChainThatCannotBeReadSendsTheMessageTheWayItWentBeforeTheChainExisted(): void
    {
        $relay = $this->addRelay('Premier', 'smtp.premier.test');
        $this->enable(MailLane::Authentication, [$relay]);

        $delivery = $this->recordingTransport();
        $chain = $this->chain($delivery);
        $chain->deliver($this->message(), MailPurpose::MagicLink);

        // The first statement `candidates()` runs is this lane's own read,
        // so the failure lands there rather than on the directory or the
        // counters. Reusing the chain is safe for that precise reason and
        // no other: `LaneChainRepository` caches nothing, while the
        // directory memoises and the settings cache survives — which is
        // what makes `preferred()`'s own branch reachable further down.
        $this->pdo->prepare('DROP TABLE mail_lane_entries')->execute();
        $chain->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(
            ['smtp.premier.test', 'localhost'],
            $delivery->attemptedHosts,
            'Routed by the chain while it was readable; sent with no relay applied once it was not.'
        );
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

    /**
     * **A family on its own domain, hosted by Google, follows gmail.com's
     * decision** (issue #422) — read from the MX cache, which the send
     * path consults and never fills itself.
     */
    public function testAPersonalDomainFollowsTheDecisionTakenForItsProvider(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);

        $cache = new \Core\Mail\Transport\MailboxProviderRepository(
            $this->pdo,
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $cache->note('famille-dupont.be', new \DateTimeImmutable());
        $cache->recordResolved('famille-dupont.be', 'gmail.com', new \DateTimeImmutable());

        $delivery = $this->recordingTransport();
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second, $cache))
            ->deliver($this->message('prenom@famille-dupont.be'), MailPurpose::Bulk);

        $this->assertSame(['smtp.second.test'], $delivery->attemptedHosts);
    }

    /**
     * **An unknown domain is noted, not resolved.** The message leaves on
     * the lane's own order at once, and the row it leaves behind has no
     * provider and no resolution date: the scheduled task asks later.
     */
    public function testAnUnknownDomainIsOnlyNotedOnTheSendPath(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);

        $cache = new \Core\Mail\Transport\MailboxProviderRepository(
            $this->pdo,
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $delivery = $this->recordingTransport();
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second, $cache))
            ->deliver($this->message('prenom@nouveau-domaine.be'), MailPurpose::Bulk);

        $this->assertSame(['smtp.premier.test'], $delivery->attemptedHosts);
        $statement = $this->pdo->prepare('SELECT domain_encrypted, provider, resolved_at FROM mail_domain_providers');
        $statement->execute();
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        // Noted encrypted (SECURITY.md §5): a personal domain can name a family.
        $this->assertStringNotContainsString('nouveau-domaine.be', (string) $row['domain_encrypted']);
        $row['domain_encrypted'] = (new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32)))
            ->decrypt((string) $row['domain_encrypted'], 'mail_domain_providers.domain');
        $this->assertSame(
            ['domain_encrypted' => 'nouveau-domaine.be', 'provider' => null, 'resolved_at' => null],
            $row
        );
    }

    /** A magic link notes nothing: the authentication lane has no business here. */
    public function testAMagicLinkNotesNoDomain(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $this->enable(MailLane::Authentication, [$first]);

        $cache = new \Core\Mail\Transport\MailboxProviderRepository(
            $this->pdo,
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->chain($this->recordingTransport(), preferences: $this->preferring('gmail.com', $first, $cache))
            ->deliver($this->message('prenom@nouveau-domaine.be'), MailPurpose::MagicLink);

        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM mail_domain_providers');
        $statement->execute();
        $this->assertSame(0, (int) $statement->fetchColumn());
    }

    /**
     * **A cache that cannot be read costs nothing.** The table gone — a
     * deploy that has not migrated yet — and the mailing still leaves.
     */
    public function testAnUnreadableCacheNeverCostsTheMessage(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);
        $this->pdo->prepare('DROP TABLE mail_domain_providers')->execute();

        $delivery = $this->recordingTransport();
        $cache = new \Core\Mail\Transport\MailboxProviderRepository(
            $this->pdo,
            new \Core\Security\EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );
        $this->chain($delivery, preferences: $this->preferring('gmail.com', $second, $cache))
            ->deliver($this->message('famille@gmail.com'), MailPurpose::Bulk);

        $this->assertSame(['smtp.second.test'], $delivery->attemptedHosts);
    }

    /**
     * **A preference is never allowed to cost a message**, and this is the
     * branch that holds that rule when the `settings` read starts failing
     * in the middle of a mailing.
     *
     * A failing `settings` read, precisely, and not « the database went
     * away »: by the time `preferred()` runs, `candidates()` has already
     * read `mail_lane_entries` and `mail_send_counters` uncached, so
     * anything taking the whole connection down is absorbed there instead
     * — a different branch, and a larger degradation, the one
     * `testAChainThatCannotBeRead…` above covers. Hence the staging below
     * drops `settings` alone and leaves the other tables answering.
     *
     * Reachable only once the directory has resolved, which is why it took
     * this chantier two attempts to state correctly: `candidates()` reads
     * `settings` too, through `MailProviderDirectory::local()`, so on the
     * first send of a process even that failure lands one layer earlier.
     * After the directory has memoised it reads no setting at all, and a
     * settings cache invalidated since (every setting write does it)
     * leaves `DomainPreferences::all()` to make the first SETTINGS query
     * of the send, inside `preferred()` — two table reads having already
     * succeeded.
     *
     * That window is widest exactly where it matters: a publipostage of
     * four hundred is hundreds of messages through one chain, and the one
     * in flight when that read starts failing must not be the one that
     * stops the mailing. Both halves below say so — reordered by the
     * preference while the setting could be read, and carried by the
     * lane's own order rather than stopped once it could not.
     */
    public function testAPreferenceThatCannotBeReadMidMailingLeavesTheOrderUntouched(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Bulk, [$first, $second]);

        $delivery = $this->recordingTransport();
        $chain = $this->chain($delivery, preferences: $this->preferring('gmail.com', $second));
        $chain->deliver($this->message('famille@gmail.com'), MailPurpose::Bulk);

        // The directory has resolved, so the next send reads no setting of
        // its own; the cache is invalidated the way a setting write does
        // it; and then `settings` alone stops answering — the other tables
        // must keep working, or `candidates()` would trap this first.
        $this->settings->clearCache();
        $this->pdo->prepare('DROP TABLE settings')->execute();
        $chain->deliver($this->message('famille@gmail.com'), MailPurpose::Bulk);

        $this->assertSame(
            ['smtp.second.test', 'smtp.premier.test'],
            $delivery->attemptedHosts,
            'Reordered while the preference could be read; sent in the lane\'s own order once it could not.'
        );
    }

    private function preferring(
        string $domain,
        int $providerId,
        ?\Core\Mail\Transport\MailboxProviderRepository $cache = null
    ): DomainPreferences {
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
        $preferences = new DomainPreferences($this->settings, $cache);
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

    /**
     * **The breaker must never be the reason a message stops**, its own
     * table included.
     *
     * Unreadable, and every candidate is kept: where the optimisation
     * cannot be consulted, trying and failing is still correct. The
     * assertion that carries this is a comparison rather than a state, so
     * both halves are in one test — the shut-out relay is skipped while
     * the table answers, and tried again the moment it does not. Either
     * half alone would be true of a chain that never applied the breaker
     * at all.
     *
     * The second delivery also enters `recordSuccess()`'s catch, since
     * with the table gone every outcome of a send goes through one of the
     * two `record*` calls. That branch has its own test below, where it
     * is the observable rather than a side effect.
     */
    public function testAnUnreadableBreakerIsNeverTheReasonAMessageStops(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Authentication, [$first, $second]);
        $health = new ProviderHealthRepository($this->pdo);

        $refusing = $this->recordingTransport(refuseHosts: ['smtp.premier.test']);
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $this->chain($refusing, $health)->deliver($this->message(), MailPurpose::MagicLink);
        }
        $this->assertTrue($health->forProvider($first)->isOpen(), 'The breaker is armed.');

        $working = $this->recordingTransport();
        $this->chain($working, $health)->deliver($this->message(), MailPurpose::MagicLink);

        $this->pdo->prepare('DROP TABLE mail_provider_health')->execute();
        $this->chain($working, $health)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(
            ['smtp.second.test', 'smtp.premier.test'],
            $working->attemptedHosts,
            'Skipped while the breaker could be read; tried again as soon as it could not.'
        );
    }

    /**
     * The breaker's own bookkeeping refused, and the lane still moves on.
     *
     * Reads keep working while writes are refused — the shape a
     * half-applied grant takes, and the shape that matters here because
     * `recordFailure()` reads before it writes, to tell « the circuit
     * opened » from « it was already open ». It returns before its journal
     * line, so nothing accumulates and the refusing relay is tried again
     * on every single message.
     *
     * Two things distinguish « swallowed » from « never written at all ».
     * The journal DOES hold the attempt failure, so it was writable and
     * the refusal was recorded; and the second half, whose only
     * difference is that the trigger is gone, opens the circuit and says
     * so — the invariant pinned in both directions rather than holding
     * vacuously over a class that never writes that line.
     */
    public function testABreakerThatCannotBeWrittenNeverStopsTheLane(): void
    {
        $first = $this->addRelay('Premier', 'smtp.premier.test');
        $second = $this->addRelay('Second', 'smtp.second.test');
        $this->enable(MailLane::Authentication, [$first, $second]);
        $health = new ProviderHealthRepository($this->pdo);
        $this->refuseHealthWrites();

        // One message MORE than the threshold, and the extra one is the
        // whole point: the circuit opens at the END of the third failure,
        // so three messages all try the refusing relay whether or not the
        // writes are refused. Only the fourth tells the two worlds apart
        // — with the writes allowed it would skip the relay, as the second
        // half below demonstrates.
        $messages = ProviderHealth::FAILURES_BEFORE_OPEN + 1;
        $delivery = $this->recordingTransport(refuseHosts: ['smtp.premier.test']);
        for ($i = 0; $i < $messages; $i++) {
            $this->chain($delivery, $health)->deliver($this->message(), MailPurpose::MagicLink);
        }

        $this->assertCount(
            $messages,
            array_keys($delivery->attemptedHosts, 'smtp.premier.test', true),
            'Nothing accumulated, so the refusing relay is still tried past the threshold.'
        );
        $this->assertCount(
            $messages,
            array_keys($delivery->attemptedHosts, 'smtp.second.test', true),
            'And every one of those messages still went out.'
        );
        $this->assertContains(
            'mail_provider_attempt_failed',
            $this->journalledTypes(),
            'The journal was writable and the refusal is in it: the silence below is the breaker\'s, not the journal\'s.'
        );
        $this->assertNotContains(
            'mail_provider_circuit_opened',
            $this->journalledTypes(),
            'Nothing was written, so there is no exclusion to announce.'
        );

        // The other direction, and the trigger is the only difference.
        $this->pdo->exec('DROP TRIGGER refuse_health_writes');
        $delivery->attemptedHosts = [];
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            $this->chain($delivery, $health)->deliver($this->message(), MailPurpose::MagicLink);
        }

        $this->assertContains(
            'mail_provider_circuit_opened',
            $this->journalledTypes(),
            'The same failures, written this time, do open the circuit and do say so.'
        );
        // The circuit opens at the END of the third failure, so all three
        // of those messages still try the relay; it is the fourth that
        // shows the exclusion being applied — which is exactly what the
        // refused writes above prevented for good.
        $delivery->attemptedHosts = [];
        $this->chain($delivery, $health)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(
            ['smtp.second.test'],
            $delivery->attemptedHosts,
            'And the next message skips the relay those failures shut out.'
        );
    }

    /**
     * **A second copy in somebody's inbox, one statement earlier than the
     * branch #449's own body cites as its example.**
     *
     * `deliver()` calls `recordSuccess()` after the transport returned and
     * outside any `try` of its own, so this catch is the only thing
     * between a health write that fails and an exception leaving
     * `deliver()`. The consequence is the one the counter's comment ten
     * lines below spells out in as many words: `MailService` would report
     * a send that did happen as failed, `mass_mail` would retry it, and
     * somebody would get the message twice. That counter's branch was
     * already covered — this one, a statement earlier and with the same
     * consequence, was covered by nothing.
     *
     * The observable is therefore not that a message left but that no
     * exception did, AND that the statement after it still ran: the
     * counter is incremented, which is what tells « the catch returned
     * from `recordSuccess()` » from « it returned from `deliver()` ».
     */
    public function testAHealthWriteThatFailsAfterASuccessfulSendNeverReportsItAsFailed(): void
    {
        $relay = $this->addRelay('Unique', 'smtp.unique.test');
        $this->enable(MailLane::Authentication, [$relay]);
        $health = new ProviderHealthRepository($this->pdo);

        // An open circuit, so the success below has something to close and
        // the journal line is really at stake. One relay is enough because
        // a lane's last entry is tried even when shut out (D15).
        $refusing = $this->recordingTransport(refuseHosts: ['smtp.unique.test']);
        for ($i = 0; $i < ProviderHealth::FAILURES_BEFORE_OPEN; $i++) {
            try {
                $this->chain($refusing, $health)->deliver($this->message(), MailPurpose::MagicLink);
            } catch (LaneExhaustedException) {
                // Expected: the only relay was refusing.
            }
        }
        $this->assertTrue($health->forProvider($relay)->isOpen());

        $this->refuseHealthWrites();
        $delivery = $this->recordingTransport();
        $this->chain($delivery, $health)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(
            ['smtp.unique.test'],
            $delivery->attemptedHosts,
            'The relay took the message, so the send must be reported as the success it was.'
        );
        $this->assertSame(
            [$relay => 1],
            $this->counters->totalsForDay(),
            'The statement AFTER recordSuccess() ran: the catch returned from it, not from deliver().'
        );
        $this->assertNotContains(
            'mail_provider_circuit_closed',
            $this->journalledTypes(),
            'Nothing was written, so there is no return to availability to announce.'
        );

        // The other direction, and the trigger is the only difference.
        $this->pdo->exec('DROP TRIGGER refuse_health_writes');
        $this->chain($this->recordingTransport(), $health)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertFalse($health->forProvider($relay)->isOpen());
        $this->assertContains(
            'mail_provider_circuit_closed',
            $this->journalledTypes(),
            'The same success, written this time, does close the circuit and does say so.'
        );
    }

    /**
     * Writes to the breaker's table refused, reads left working.
     *
     * A trigger rather than a dropped table, and the difference is the
     * whole point: `recordFailure()` reads before writing, so a missing
     * table would fail on the read and prove nothing about the write —
     * and `ProviderHealthRepository` is final, so a double is not
     * available even if it were the right instrument, which
     * `docs/chantiers/CHANTIER-revue-des-tests.md` §3 argues it is not.
     *
     * `BEFORE INSERT` covers the update too: `store()` is an upsert, so
     * every write it makes is attempted as an insert first.
     */
    private function refuseHealthWrites(): void
    {
        $this->pdo->exec(
            'CREATE TRIGGER refuse_health_writes BEFORE INSERT ON mail_provider_health
             BEGIN SELECT RAISE(FAIL, \'disjoncteur indisponible\'); END'
        );
    }

    /**
     * Every event type the journal holds, for the assertions whose subject
     * is that one of them is NOT among them.
     *
     * @return array<int, string>
     */
    private function journalledTypes(): array
    {
        $statement = $this->pdo->prepare('SELECT event_type FROM event_log WHERE category = ?');
        $statement->execute(['core']);

        return $statement->fetchAll(\PDO::FETCH_COLUMN);
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

    /**
     * A counter that cannot be written does not turn a delivered message
     * into a failed one.
     *
     * The rule is written where it is enforced, and its reason is a
     * second copy in somebody's inbox: « letting a counter write
     * propagate would have `MailService` report a send that did happen as
     * failed, and the caller — `mass_mail` above all — retry it ». The
     * branch that holds it had never been executed: removing its
     * `try`/`catch` entirely left 1 149 mail tests green.
     *
     * The failure is real rather than simulated — a trigger on the table
     * the counter writes to refuses the insert while leaving reads
     * working, so the repository throws the PDOException it would throw
     * in production. `SendCounterRepository` is final, and a double here
     * would be the thing this chantier spends its §3 on: a stand-in that
     * no longer resembles the subject.
     *
     * **Not by dropping the table**, which was the first attempt and is
     * the reason the trigger is there: the quota is read BEFORE the send,
     * so a missing table makes the lane get skipped and the test proves
     * nothing about the counter at all.
     */
    public function testACounterThatCannotBeWrittenDoesNotFailTheDeliveredMessage(): void
    {
        $relay = $this->addRelay('Unique', 'smtp.unique.test');
        $this->enable(MailLane::Authentication, [$relay]);
        // Writes fail, reads keep working: the quota check happens before
        // the send and must still answer, or the lane would be skipped and
        // the test would prove nothing about the counter at all.
        $this->pdo->exec(
            'CREATE TRIGGER refuse_counter_writes BEFORE INSERT ON mail_send_counters
             BEGIN SELECT RAISE(FAIL, \'compteur indisponible\'); END'
        );

        $delivery = $this->recordingTransport();
        $this->chain($delivery)->deliver($this->message(), MailPurpose::MagicLink);

        $this->assertSame(
            ['smtp.unique.test'],
            $delivery->attemptedHosts,
            'The message was handed to the relay, so the send must be reported as the success it was.'
        );

        $this->assertContains(
            'mail_send_counter_failed',
            $this->journalledTypes(),
            'The bookkeeping failure is swallowed for the caller, not for the operator: it belongs in the journal.'
        );
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
