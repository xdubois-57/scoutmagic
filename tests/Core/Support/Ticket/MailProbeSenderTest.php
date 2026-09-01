<?php

declare(strict_types=1);

namespace Tests\Core\Support\Ticket;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Security\SecretManager;
use Core\Statistics\InstallationIdentityService;
use Core\Statistics\StatisticsTransportInterface;
use Core\Statistics\StatisticsTransportResponse;
use Core\Support\Ticket\MailProbeSender;
use Core\Support\Ticket\TicketIdentityService;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The instance side of the diagnostic mail probes (roadmap IT-27).
 *
 * The property this file exists for is the one pitfall of the whole
 * mechanism: `MailService` prefixes every subject with `[{short_name}] `,
 * so a correlation key that only survives at the start of a subject is a
 * key the receiver never finds again. The assertion is made on the
 * message `MailService` actually built, not on the string handed to it.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailProbeSenderTest extends TestCase
{
    private \PDO $pdo;
    private SettingService $settings;
    private SecretManager $secretManager;
    private string $projectRoot;
    private CapturingMailTransport $mailTransport;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->settings = new SettingService(new SettingRepository($this->pdo));

        $this->projectRoot = sys_get_temp_dir() . '/scoutmagic-mail-probe-' . bin2hex(random_bytes(6));
        mkdir($this->projectRoot . '/storage/keys', 0700, true);
        mkdir($this->projectRoot . '/storage/config', 0700, true);

        $this->secretManager = new SecretManager(
            $this->projectRoot . '/storage/keys/master.key',
            $this->projectRoot . '/storage/config/secrets.enc'
        );
        $this->secretManager->generateMasterKey();
        $this->secretManager->writeSecrets([]);

        foreach ([
            InstallationIdentityService::INSTALLATION_ID_SETTING,
            MailProbeSender::LAST_SENT_AT_SETTING,
            MailProbeSender::LAST_KEY_SETTING,
        ] as $key) {
            $this->settings->register($key, '', 'text', 'L', 'D', null, null, null, false);
        }
        $this->settings->register('statistics_enabled', '0', 'boolean', 'L', 'D');
        $this->settings->register('statistics_destination', 'https://www.scoutmagic.be', 'url', 'L', 'D');

        $this->mailTransport = new CapturingMailTransport();
    }

    protected function tearDown(): void
    {
        foreach (['/storage/config/secrets.enc', '/storage/keys/master.key'] as $file) {
            @unlink($this->projectRoot . $file);
        }
        foreach (['/storage/config', '/storage/keys', '/storage', ''] as $dir) {
            @rmdir($this->projectRoot . $dir);
        }
    }

    /**
     * The pitfall, pinned: the key is still findable once the subject has
     * been prefixed, and it is findable by exactly the expression the
     * receiver matches with.
     */
    public function testTheKeySurvivesTheSubjectPrefixMailServiceAdds(): void
    {
        $result = $this->sender($this->issuing(['support@scoutmagic.be']))->send($this->now());

        $this->assertTrue($result->sent);
        $this->assertSame('SMP-ABCDEFGHJK', $result->correlationKey);

        $subject = $this->mailTransport->messages[0]->Subject;
        $this->assertStringStartsWith('[Unité test] ', $subject);
        $this->assertSame(1, preg_match('/\b(SMP-[A-Z0-9]{10})\b/', $subject, $m));
        $this->assertSame('SMP-ABCDEFGHJK', $m[1]);
    }

    public function testOneMessageGoesToEachAddressTheReceiverNamed(): void
    {
        $result = $this->sender($this->issuing([
            'support@scoutmagic.be',
            'contact@scoutmagic.be',
        ]))->send($this->now());

        $this->assertSame(2, $result->addressCount);
        $this->assertSame(2, $result->deliveredCount);
        $this->assertCount(2, $this->mailTransport->messages);
        $this->assertSame(
            ['support@scoutmagic.be', 'contact@scoutmagic.be'],
            array_map(
                static fn (PHPMailer $mail): string => $mail->getToAddresses()[0][0],
                $this->mailTransport->messages
            )
        );
    }

    /**
     * A probe carries a key and nothing else — no member, no address book,
     * nothing about anybody.
     */
    public function testTheProbeCarriesTheKeyAndNoPersonalData(): void
    {
        $this->sender($this->issuing(['support@scoutmagic.be']))->send($this->now());

        $mail = $this->mailTransport->messages[0];
        $this->assertStringContainsString('SMP-ABCDEFGHJK', $mail->AltBody);
        $this->assertStringContainsString('aucune donnée personnelle', $mail->AltBody);
    }

    /**
     * One box refusing while another accepts is exactly the asymmetry the
     * probe exists to reveal, so it is counted rather than fatal.
     */
    public function testOneRefusedAddressDoesNotStopTheRun(): void
    {
        $this->mailTransport->failFor = 'contact@scoutmagic.be';

        $result = $this->sender($this->issuing([
            'support@scoutmagic.be',
            'contact@scoutmagic.be',
        ]))->send($this->now());

        $this->assertTrue($result->sent);
        $this->assertSame(2, $result->addressCount);
        $this->assertSame(1, $result->deliveredCount);
    }

    public function testEveryAddressRefusedIsAFailureOfTheLocalMailConfiguration(): void
    {
        $this->mailTransport->failFor = '*';

        $result = $this->sender($this->issuing(['support@scoutmagic.be']))->send($this->now());

        $this->assertFalse($result->sent);
        $this->assertSame(MailProbeSender::FAILURE_MAIL_REFUSED, $result->failureReason);

        $row = $this->pdo->query(
            "SELECT level FROM event_log WHERE event_type = 'support_mail_probe_sent'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertSame('warning', $row['level']);

        // And the reason the box refused, one entry per address, from
        // MailService itself — « la sonde n'est pas partie » without it
        // says nothing anybody can act on.
        $failure = $this->pdo->query(
            "SELECT level FROM event_log WHERE event_type = 'mail_send_failed'"
        )->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($failure);
        $this->assertSame('error', $failure['level']);
    }

    /**
     * A rate limit exists to cap what actually goes out. Capping what did
     * NOT go out is a lock with no key: an administrator whose relay was
     * misconfigured watched the probe fail, fixed the relay, pressed
     * again — and was told « il y en a déjà eu un il y a moins d'une
     * heure », which was untrue and had no way of becoming untrue for an
     * hour.
     */
    public function testARunThatDeliveredNothingIsNotRememberedAsARun(): void
    {
        $this->mailTransport->failFor = '*';
        $sender = $this->sender($this->issuing(['support@scoutmagic.be']));

        $sender->send($this->now());

        $this->assertNull($sender->rateLimitedUntil($this->now()));
        // And no key to go looking for either: the receiver was told to
        // expect messages that never arrived.
        $this->assertNull($sender->lastRun());
    }

    /**
     * The press right after that one has to travel, since the local gate
     * is the only thing that could have stopped it.
     */
    public function testTheNextPressAfterATotalFailureTravelsAgain(): void
    {
        $this->mailTransport->failFor = '*';
        $transport = $this->issuing(['support@scoutmagic.be']);
        $sender = $this->sender($transport);

        $sender->send($this->now());
        $this->mailTransport->failFor = null;
        $result = $sender->send($this->now()->modify('+2 minutes'));

        $this->assertCount(2, $transport->calls);
        $this->assertTrue($result->sent);
    }

    /**
     * The local stamp means « this installation sent probes at T », and a
     * refusal is not a send. Stamping it restarted the local hour from
     * the moment of the refusal, so pressing every few minutes pushed the
     * window ahead of itself and it never reopened at all.
     */
    public function testAReceiverRefusalDoesNotRestartTheLocalWindow(): void
    {
        $sender = $this->sender($this->transport(200, ['status' => 'rate_limited']));

        $sender->send($this->now());

        $this->assertNull($sender->rateLimitedUntil($this->now()->modify('+1 minute')));
    }

    /**
     * @return array<string, array{int, array<string, mixed>|string, string}>
     */
    public static function refusalProvider(): array
    {
        return self::failureProvider() + [
            'it relays no mailbox at all' => [
                200,
                ['status' => 'unavailable', 'addresses' => []],
                MailProbeSender::FAILURE_NO_MAILBOX,
            ],
            'it counted a run of its own' => [
                200,
                ['status' => 'rate_limited'],
                MailProbeSender::FAILURE_RATE_LIMITED,
            ],
        ];
    }

    /**
     * All the administrator sees is « L'e-mail de test n'a pas pu partir »,
     * whichever of these it was. Only the run that DID send used to be
     * journaled, so the event journal — which is also what the diagnostic
     * archive carries — said nothing whatsoever about a probe they had
     * just watched fail.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusalProvider')]
    public function testEveryRefusalIsWrittenDownAndNotOnlyTheRunsThatSent(
        int $status,
        array|string $body,
        string $expectedReason
    ): void {
        $transport = is_string($body)
            ? new RecordingProbeTransport(StatisticsTransportResponse::response($status, $body))
            : $this->transport($status, $body);

        $this->sender($transport)->send($this->now());

        $row = $this->pdo->query(
            "SELECT level, context FROM event_log WHERE event_type = 'support_mail_probe_not_sent'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertSame('warning', $row['level']);
        $this->assertStringContainsString($expectedReason, (string) $row['context']);
    }

    public function testASecondRunWithinTheHourNeverLeaves(): void
    {
        $transport = $this->issuing(['support@scoutmagic.be']);
        $sender = $this->sender($transport);

        $sender->send($this->now());
        $result = $sender->send($this->now()->modify('+30 minutes'));

        $this->assertFalse($result->sent);
        $this->assertSame(MailProbeSender::FAILURE_RATE_LIMITED, $result->failureReason);
        $this->assertCount(1, $transport->calls);
        $this->assertCount(1, $this->mailTransport->messages);
    }

    public function testTheWindowReopensAfterAnHour(): void
    {
        $transport = $this->issuing(['support@scoutmagic.be']);
        $sender = $this->sender($transport);

        $sender->send($this->now());
        $result = $sender->send($this->now()->modify('+61 minutes'));

        $this->assertTrue($result->sent);
        $this->assertCount(2, $transport->calls);
    }

    /**
     * A receiver that relays no mailbox has nothing to probe, and saying
     * so is a complete answer rather than an error.
     */
    public function testAReceiverWithNoMailboxIsSaidPlainly(): void
    {
        $result = $this->sender($this->transport(200, ['status' => 'unavailable', 'addresses' => []]))
            ->send($this->now());

        $this->assertFalse($result->sent);
        $this->assertSame(MailProbeSender::FAILURE_NO_MAILBOX, $result->failureReason);
        $this->assertSame([], $this->mailTransport->messages);
    }

    /**
     * @return array<string, array{int, array<string, mixed>|string, string}>
     */
    public static function failureProvider(): array
    {
        return [
            'the receiver never answered' => [503, 'oops', MailProbeSender::FAILURE_UNREACHABLE],
            'it answered something unreadable' => [200, 'pas du json', MailProbeSender::FAILURE_MALFORMED_ANSWER],
            'it issued nothing usable' => [200, ['status' => 'issued', 'addresses' => []], MailProbeSender::FAILURE_MALFORMED_ANSWER],
            'it refused to say anything' => [200, ['status' => 'rejected'], MailProbeSender::FAILURE_MALFORMED_ANSWER],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failureProvider')]
    public function testNoMessageLeavesUnlessAKeyCameBack(
        int $status,
        array|string $body,
        string $expectedReason
    ): void {
        $transport = is_string($body)
            ? new RecordingProbeTransport(StatisticsTransportResponse::response($status, $body))
            : $this->transport($status, $body);

        $result = $this->sender($transport)->send($this->now());

        $this->assertFalse($result->sent);
        $this->assertSame($expectedReason, $result->failureReason);
        $this->assertSame([], $this->mailTransport->messages);
    }

    /**
     * The guards belong to the report and are called, not copied: a
     * destination that is not HTTPS stops the run before a socket opens
     * and before a single message is composed.
     */
    public function testAGuardStopsTheRunBeforeAnythingLeaves(): void
    {
        $this->settings->setInternal('statistics_destination', 'http://scoutmagic.be');
        $transport = $this->issuing(['support@scoutmagic.be']);

        $result = $this->sender($transport)->send($this->now());

        $this->assertFalse($result->sent);
        $this->assertSame(TicketIdentityService::GUARD_INSECURE_DESTINATION, $result->failureReason);
        $this->assertSame([], $transport->calls);
        $this->assertSame([], $this->mailTransport->messages);
    }

    public function testTheJournalEntryCountsTheBoxesAndNamesNoneOfThem(): void
    {
        $this->sender($this->issuing(['support@scoutmagic.be', 'contact@scoutmagic.be']))->send($this->now());

        $row = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'support_mail_probe_sent'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('"mailboxes":2', (string) $row['context']);
        $this->assertStringNotContainsString('support@scoutmagic.be', (string) $row['context']);
        $this->assertStringNotContainsString('support@scoutmagic.be', (string) $row['description']);
    }

    public function testTheLastRunIsKeptSoThePageCanNameWhatToLookFor(): void
    {
        $sender = $this->sender($this->issuing(['support@scoutmagic.be']));
        $this->assertNull($sender->lastRun());

        $sender->send($this->now());

        $last = $sender->lastRun();
        $this->assertNotNull($last);
        $this->assertSame('SMP-ABCDEFGHJK', $last['key']);
        $this->assertNotSame('', $last['sent_at']);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-04 10:00:00');
    }

    /**
     * @param list<string> $addresses
     */
    private function issuing(array $addresses): RecordingProbeTransport
    {
        return $this->transport(200, [
            'status' => 'issued',
            'correlation_key' => 'SMP-ABCDEFGHJK',
            'addresses' => $addresses,
            'expires_at' => '2026-03-06T10:00:00+00:00',
        ]);
    }

    /**
     * @param array<string, mixed> $answer
     */
    private function transport(int $status, array $answer): RecordingProbeTransport
    {
        return new RecordingProbeTransport(
            StatisticsTransportResponse::response($status, (string) json_encode($answer))
        );
    }

    private function sender(StatisticsTransportInterface $transport): MailProbeSender
    {
        $journal = new JournalService(new JournalRepository($this->pdo));

        return new MailProbeSender(
            $this->settings,
            new TicketIdentityService(
                $this->settings,
                new InstallationIdentityService($this->settings, $this->secretManager),
                $journal
            ),
            $transport,
            new MailService(
                mode: 'local',
                fromAddress: 'unite@example.be',
                fromName: 'Unité test',
                shortName: 'Unité test',
                dkimManager: new DkimManager($this->projectRoot . '/storage/keys'),
                dkimSelector: 'mail',
                transport: $this->mailTransport,
                journal: $journal
            ),
            $journal,
            '1.0.33'
        );
    }
}

/**
 * Records what was sent and answers what the test told it to.
 */
final class RecordingProbeTransport implements StatisticsTransportInterface
{
    /** @var array<int, array{url: string, body: string, token: string}> */
    public array $calls = [];

    public function __construct(private StatisticsTransportResponse $response)
    {
    }

    public function post(string $url, string $jsonBody, string $bearerToken, string $userAgent): StatisticsTransportResponse
    {
        $this->calls[] = ['url' => $url, 'body' => $jsonBody, 'token' => $bearerToken];

        return $this->response;
    }
}

/**
 * Keeps the message MailService actually built, so a test can assert on
 * the subject AFTER the `[{short_name}]` prefix rather than before it.
 */
final class CapturingMailTransport implements MailTransportInterface
{
    /** @var list<PHPMailer> */
    public array $messages = [];

    /** An address to refuse, or `*` for all of them. */
    public ?string $failFor = null;

    public function deliver(PHPMailer $mail): void
    {
        $recipient = $mail->getToAddresses()[0][0] ?? '';
        if ($this->failFor === '*' || ($this->failFor !== null && $this->failFor === $recipient)) {
            throw new \RuntimeException('SMTP refusé');
        }

        $this->messages[] = $mail;
    }
}
