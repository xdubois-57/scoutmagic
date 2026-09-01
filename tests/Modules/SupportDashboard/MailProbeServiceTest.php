<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Security\EncryptionService;
use Modules\InboundMail\Api\InboundMailInterface;
use Modules\SupportDashboard\Mail\SupportMessageConsumer;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportMailProbeRepository;
use Modules\SupportDashboard\Service\MailProbeService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The receiver side of the diagnostic mail probes (roadmap IT-27).
 *
 * Three properties are the whole of it: nobody who cannot prove they are
 * an installation gets this receiver's mailbox addresses; a key is only
 * claimable once and only while it is valid; and « jamais reçue » is a
 * recorded state rather than a silence.
 */
class MailProbeServiceTest extends TestCase
{
    private \PDO $pdo;
    private SupportInstallationRepository $installations;
    private SupportMailProbeRepository $probes;
    private string $secret = 'a-secret-nobody-else-holds';

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        SupportDashboardTestHelper::createTables($this->pdo);

        $this->installations = new SupportInstallationRepository($this->pdo);
        $this->probes = new SupportMailProbeRepository(
            $this->pdo,
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32))
        );

        $this->installations->register(
            'unite-de-test',
            password_hash($this->secret, PASSWORD_DEFAULT),
            '{}',
            []
        );
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function refusalProvider(): array
    {
        return [
            'no bearer at all' => ['unite-de-test', '', true],
            'a bearer that is not the secret' => ['unite-de-test', 'Bearer pas-le-bon', true],
            'an installation nobody registered' => ['inconnue', 'Bearer a-secret-nobody-else-holds', true],
            'no installation named' => ['', 'Bearer a-secret-nobody-else-holds', true],
            'the whole call in cleartext' => ['unite-de-test', 'Bearer a-secret-nobody-else-holds', false],
        ];
    }

    /**
     * The answer is a list of this receiver's own mailbox addresses. An
     * open route here would hand them to anybody who asked, and every
     * refusal has to look the same so a caller cannot learn which
     * installation ids exist by watching which one comes back.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusalProvider')]
    public function testNobodyGetsTheAddressesWithoutProvingWhoTheyAre(
        string $installationId,
        string $header,
        bool $secure
    ): void {
        $answer = $this->service(['support@scoutmagic.be'])
            ->issueFor($installationId, $header, $secure, $this->now());

        $this->assertSame(['status' => MailProbeService::STATUS_REJECTED], $answer);
        $this->assertSame(0, $this->probeCount());
    }

    public function testAnAuthenticatedCallerGetsAKeyAndOneRowPerMailbox(): void
    {
        $answer = $this->service(['support@scoutmagic.be', 'contact@scoutmagic.be'])
            ->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $this->assertSame(MailProbeService::STATUS_ISSUED, $answer['status']);
        $this->assertMatchesRegularExpression('/^SMP-[A-Z0-9]{10}$/', (string) $answer['correlation_key']);
        $this->assertSame(['support@scoutmagic.be', 'contact@scoutmagic.be'], $answer['addresses']);
        $this->assertSame(2, $this->probeCount());
    }

    /**
     * A button that writes to N mailboxes is an amplifier: one run an
     * hour, counted from this receiver's own clock.
     */
    public function testASecondRunWithinTheHourIsRefusedWithoutWritingARow(): void
    {
        $service = $this->service(['support@scoutmagic.be']);
        $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $answer = $service->issueFor(
            'unite-de-test',
            'Bearer ' . $this->secret,
            true,
            $this->now()->modify('+30 minutes')
        );

        $this->assertSame(['status' => MailProbeService::STATUS_RATE_LIMITED], $answer);
        $this->assertSame(1, $this->probeCount());
    }

    /**
     * With `inbound_mail` absent — or configured with no mailbox — the
     * whole feature answers « aucune boîte », which is the truth and not
     * an error.
     */
    public function testAReceiverWithNoMailboxAnswersPlainlyAndWritesNothing(): void
    {
        $answer = $this->service([])->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $this->assertSame(MailProbeService::STATUS_UNAVAILABLE, $answer['status']);
        $this->assertSame([], $answer['addresses']);
        $this->assertSame(0, $this->probeCount());
    }

    public function testWithoutInboundMailThereIsNothingToProbe(): void
    {
        $service = new MailProbeService(
            $this->probes,
            $this->installations,
            new JournalService(new JournalRepository($this->pdo)),
            null
        );

        $this->assertSame([], $service->probeAddresses());
        $this->assertSame(
            MailProbeService::STATUS_UNAVAILABLE,
            $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now())['status']
        );
    }

    /**
     * The one pitfall of the mechanism, from the receiving end: the key
     * is matched wherever it sits, because `Core\Mail\MailService`
     * prefixes every subject it sends.
     */
    public function testTheKeyIsFoundBehindTheSubjectPrefix(): void
    {
        $this->assertSame('SMP-ABCDEFGHJK', MailProbeService::keyIn('[Unité test] Sonde de diagnostic SMP-ABCDEFGHJK'));
        $this->assertSame('SMP-ABCDEFGHJK', MailProbeService::keyIn('Re: [Unité] Fwd: SMP-ABCDEFGHJK (auto)'));
        $this->assertNull(MailProbeService::keyIn('[Unité test] Une question de parent'));
        $this->assertNull(MailProbeService::keyIn('SMP-trop-court'));
    }

    public function testAnArrivingMessageIsAttachedToTheProbeItAnswers(): void
    {
        $service = $this->service(['support@scoutmagic.be']);
        $issued = $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());
        $key = (string) $issued['correlation_key'];

        $claimed = $service->claim(
            '[Unité test] Sonde de diagnostic ' . $key,
            "Received: from mx.example.be by mail.scoutmagic.be\r\n"
                . "Authentication-Results: mail.scoutmagic.be; spf=pass; dkim=pass; dmarc=fail\r\n",
            $this->now()->modify('+90 seconds'),
            $this->now()->modify('+90 seconds')
        );

        $this->assertTrue($claimed);

        $results = $service->resultsFor($this->installationRowId());
        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]['received_at']);
        $this->assertSame(90, $results[0]['delay_seconds']);
        $this->assertSame('pass', $results[0]['authentication']['spf']);
        $this->assertSame('pass', $results[0]['authentication']['dkim']);
        $this->assertSame('fail', $results[0]['authentication']['dmarc']);
        $this->assertCount(1, $results[0]['authentication']['relays']);
    }

    /**
     * A reading is not evidence. « SPF non renseigné » is a claim about
     * what a server wrote down, and the only way to tell a right reading
     * from a wrong one is to look at what was written — which is exactly
     * how « les trois absents » turned out to be an IMAP client that
     * passed no header block at all.
     */
    public function testTheHeaderBlockIsKeptBesideTheReadingItProduced(): void
    {
        $service = $this->service(['support@scoutmagic.be']);
        $issued = $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());
        $headers = "Received: from mx.example.be by mail.scoutmagic.be\r\n"
            . "Authentication-Results: mail.scoutmagic.be; spf=pass\r\n";

        $service->claim(
            '[Unité test] Sonde de diagnostic ' . (string) $issued['correlation_key'],
            $headers,
            $this->now(),
            $this->now()
        );

        $this->assertSame($headers, $service->resultsFor($this->installationRowId())[0]['raw_headers']);
    }

    /**
     * The block carries hosts and IP addresses, like the parsed chain it
     * is read from, and it is stored the same way.
     */
    public function testTheHeaderBlockNeverSitsInTheTableInCleartext(): void
    {
        $service = $this->service(['support@scoutmagic.be']);
        $issued = $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $service->claim(
            '[Unité test] Sonde de diagnostic ' . (string) $issued['correlation_key'],
            "Received: from mx.example.be [198.51.100.7]\r\n",
            $this->now(),
            $this->now()
        );

        $stored = (string) $this->pdo->query('SELECT raw_headers_encrypted FROM support_mail_probes')->fetchColumn();
        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('198.51.100.7', $stored);
    }

    /**
     * A client that supplies none is a complete answer, and a different
     * one from « pas encore reçue » — the page has to be able to say so.
     */
    public function testAProbeReceivedWithNoHeadersAtAllKeepsNone(): void
    {
        $service = $this->service(['support@scoutmagic.be']);
        $issued = $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $service->claim(
            '[Unité test] Sonde de diagnostic ' . (string) $issued['correlation_key'],
            null,
            $this->now(),
            $this->now()
        );

        $result = $service->resultsFor($this->installationRowId())[0];
        $this->assertNotNull($result['received_at']);
        $this->assertNull($result['raw_headers']);
    }

    /**
     * A probe nobody ever answered stays a row saying so — which is the
     * whole reason a key is issued per address rather than counted.
     */
    public function testAProbeNobodyAnsweredStaysUnreceived(): void
    {
        $service = $this->service(['support@scoutmagic.be']);
        $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $results = $service->resultsFor($this->installationRowId());

        $this->assertCount(1, $results);
        $this->assertNull($results[0]['received_at']);
        $this->assertNull($results[0]['delay_seconds']);
        $this->assertNull($results[0]['authentication']);
    }

    /**
     * Past its expiry a key is a key this receiver has stopped waiting
     * for: a message carrying it is somebody else's mail now.
     */
    public function testAnExpiredKeyIsNoLongerClaimable(): void
    {
        $service = $this->service(['support@scoutmagic.be']);
        $issued = $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());
        $key = (string) $issued['correlation_key'];

        $tooLate = $this->now()->modify('+' . (MailProbeService::VALIDITY_HOURS + 1) . ' hours');

        $this->assertFalse($service->claim('[Unité] ' . $key, null, $tooLate, $tooLate));
        $this->assertNull($service->resultsFor($this->installationRowId())[0]['received_at']);

        // And the purge is what finally removes it.
        $this->assertSame(1, $this->probes->deleteExpired($tooLate));
        $this->assertSame(0, $this->probeCount());
    }

    public function testAKeyThisReceiverNeverIssuedIsNotClaimed(): void
    {
        $service = $this->service(['support@scoutmagic.be']);
        $service->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $this->assertFalse($service->claim('[Unité] SMP-ZZZZZZZZZZ', null, $this->now(), $this->now()));
    }

    /**
     * The consumer wants the headers — that is the diagnosis — and
     * refuses the body, which is nothing anybody needs to keep.
     */
    public function testTheConsumerWantsHeadersAndRefusesTheBody(): void
    {
        $consumer = new SupportMessageConsumer($this->service(['support@scoutmagic.be']));

        $this->assertTrue($consumer->wantsRawHeaders());
        $this->assertFalse($consumer->wantsBody());
        $this->assertFalse($consumer->canRead('SMP-ABCDEFGHJK', [], 'superadmin'));
    }

    public function testTheJournalEntryCountsTheMailboxesAndNamesNoneOfThem(): void
    {
        $this->service(['support@scoutmagic.be', 'contact@scoutmagic.be'])
            ->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $row = $this->pdo->query(
            "SELECT description, context FROM event_log WHERE event_type = 'support_mail_probe_issued'"
        )->fetch(\PDO::FETCH_ASSOC);

        $this->assertIsArray($row);
        $this->assertStringContainsString('"mailboxes":2', (string) $row['context']);
        $this->assertStringNotContainsString('support@scoutmagic.be', (string) $row['context']);
    }

    /**
     * SECURITY.md §5: the address and the relay chain are written and
     * read only by the repository, so nothing readable sits in the table.
     */
    public function testTheAddressNeverSitsInTheTableInCleartext(): void
    {
        $this->service(['support@scoutmagic.be'])
            ->issueFor('unite-de-test', 'Bearer ' . $this->secret, true, $this->now());

        $stored = (string) $this->pdo->query('SELECT mailbox_address_encrypted FROM support_mail_probes')->fetchColumn();

        $this->assertNotSame('', $stored);
        $this->assertStringNotContainsString('support@scoutmagic.be', $stored);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-03-04 10:00:00');
    }

    private function probeCount(): int
    {
        return (int) $this->pdo->query('SELECT COUNT(*) FROM support_mail_probes')->fetchColumn();
    }

    private function installationRowId(): int
    {
        return (int) $this->pdo->query("SELECT id FROM support_installations WHERE installation_id = 'unite-de-test'")
            ->fetchColumn();
    }

    /**
     * @param list<string> $addresses
     */
    private function service(array $addresses): MailProbeService
    {
        return new MailProbeService(
            $this->probes,
            $this->installations,
            new JournalService(new JournalRepository($this->pdo)),
            new FixedMailboxes($addresses)
        );
    }
}

/**
 * An `inbound_mail` that relays exactly the boxes the test named.
 */
final class FixedMailboxes implements InboundMailInterface
{
    use \Tests\Modules\InboundMail\InertInboundMail;

    /**
     * @param list<string> $addresses
     */
    public function __construct(private array $addresses)
    {
    }

    /**
     * @return list<string>
     */
    public function probeAddressesFor(string $consumerId): array
    {
        return $consumerId === SupportMessageConsumer::CONSUMER_ID ? $this->addresses : [];
    }
}
