<?php

declare(strict_types=1);

namespace Tests\Core\Mail\Transport;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailPurpose;
use Core\Mail\MailTransportInterface;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
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
 * @group database
 */
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

    private function chain(MailTransportInterface $delivery): MailTransportChain
    {
        $connections = new ProviderConnections($this->secrets);

        return new MailTransportChain(
            new MailProviderDirectory($this->providers, $connections, $this->settings),
            $this->chains,
            $this->counters,
            new TransportConfigurator($connections),
            $delivery,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function message(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = 'Sujet';

        return $mail;
    }

    /**
     * A transport that records the host it was pointed at and refuses the
     * ones it was told to — the only observable the chain has, since
     * "which provider carried this" is exactly what it decides.
     *
     * @param array<int, string> $refuseHosts
     */
    private function recordingTransport(array $refuseHosts = []): MailTransportInterface
    {
        return new class ($refuseHosts) implements MailTransportInterface {
            /** @var array<int, string> */
            public array $attemptedHosts = [];

            /** @param array<int, string> $refuseHosts */
            public function __construct(private array $refuseHosts)
            {
            }

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->attemptedHosts[] = $mail->Host;

                if (in_array($mail->Host, $this->refuseHosts, true)) {
                    throw new \RuntimeException('SMTP connect() failed.');
                }
            }
        };
    }
}
