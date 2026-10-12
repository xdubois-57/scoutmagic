<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Mail\PhpMailerTransport;
use Core\Mail\RecipientFirstSmtp;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * `MailService::send(cc: …)` — a visible copy of one message (chantier
 * #843, IT-06: the unit in copy of what it writes to a family).
 *
 * What is pinned: no copy unless one is asked for, one address or a list,
 * an invalid address stopping the whole message before anything is
 * assembled, and the copy riding inside the one signed, multipart
 * message rather than beside it. The deferred queue's half is in
 * `MailServiceDeferralTest` and `DrainDeferredMailHandlerTest`.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class MailServiceCopyTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mail_copy_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    /** The ~95 call sites that never heard of a copy send none. */
    public function testAMessageSentWithoutACopyCarriesNone(): void
    {
        $transport = $this->recordingTransport();

        $this->service($transport)->send('parent@exemple.test', 'Sujet', '<p>Bonjour</p>', 'Bonjour');

        $this->assertSame(1, $transport->calls);
        $this->assertSame([], $transport->received()->getCcAddresses());
    }

    /** One address, given as a string. */
    public function testOneAddressIsAddedAsACopy(): void
    {
        $transport = $this->recordingTransport();

        $this->service($transport)->send(
            to: 'parent@exemple.test',
            subject: 'Sujet',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            cc: 'unite@exemple.test'
        );

        $mail = $transport->received();
        $this->assertSame([['parent@exemple.test', '']], $mail->getToAddresses());
        $this->assertSame([['unite@exemple.test', '']], $mail->getCcAddresses());
    }

    /**
     * A list, each address once — the same address twice, in another
     * case, is one copy — and an empty list is no copy at all.
     */
    public function testAListOfAddressesIsAddedOnceEach(): void
    {
        $transport = $this->recordingTransport();

        $this->service($transport)->send(
            to: 'parent@exemple.test',
            subject: 'Sujet',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            cc: ['unite@exemple.test', 'staff@exemple.test', 'UNITE@exemple.test']
        );
        $this->assertSame(
            [['unite@exemple.test', ''], ['staff@exemple.test', '']],
            $transport->received()->getCcAddresses()
        );

        $this->service($transport)->send('parent@exemple.test', 'Sujet', '<p>x</p>', 'x', cc: []);
        $this->assertSame([], $transport->received()->getCcAddresses());
    }

    /**
     * A copy of the message to the address it already goes to is nothing:
     * the recipient is not written to twice.
     */
    public function testACopyToTheRecipientItselfIsDropped(): void
    {
        $transport = $this->recordingTransport();

        $this->service($transport)->send('parent@exemple.test', 'Sujet', '<p>x</p>', 'x', cc: 'parent@exemple.test');

        $this->assertSame([['parent@exemple.test', '']], $transport->received()->getToAddresses());
        $this->assertSame([], $transport->received()->getCcAddresses());
    }

    /**
     * @return array<string, array{0: string|list<mixed>}>
     */
    public static function invalidCopies(): array
    {
        return [
            'not an address' => ['pas-une-adresse'],
            'empty string' => [''],
            'header injection' => ["unite@exemple.test\r\nBcc: espion@exemple.test"],
            'one bad address in a list' => [['unite@exemple.test', 'pas-une-adresse']],
            'not a string' => [[42]],
        ];
    }

    /**
     * **An invalid copy address sends nothing at all**, with a clear
     * `MailException` and a journal line that names nobody. A message
     * that went out without the copy it was asked to carry would leave
     * the unit believing it had been told.
     *
     * @param string|list<mixed> $cc
     */
    #[DataProvider('invalidCopies')]
    public function testAnInvalidCopyAddressSendsNothing(string|array $cc): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $transport = $this->recordingTransport();
        $service = $this->service($transport, new JournalService(new JournalRepository($pdo)));

        try {
            $service->send('parent@exemple.test', 'Sujet', '<p>x</p>', 'x', cc: $cc);
            $this->fail('An invalid copy address must refuse the whole message.');
        } catch (MailException $e) {
            $this->assertSame('Invalid Cc address: the message was not sent.', $e->getMessage());
        }

        $this->assertSame(0, $transport->calls, 'nothing may reach the transport.');

        $statement = $pdo->prepare('SELECT context FROM event_log WHERE event_type = ? ORDER BY id DESC LIMIT 1');
        $statement->execute(['mail_send_failed']);
        $context = (string) $statement->fetchColumn();
        $this->assertStringContainsString('Invalid Cc address', $context);
        $this->assertStringNotContainsString('parent@exemple.test', $context);
        $this->assertStringNotContainsString('pas-une-adresse', $context);
    }

    /**
     * **One rule, whatever the process did before.** PHPMailer switches its
     * global validator to a looser one, for the rest of the process, the
     * first time a UTF-8 address is added; the rule a box checks a typed
     * address with must not depend on what an earlier request sent.
     */
    public function testTheAddressRuleDoesNotFollowPhpMailersGlobalSwitch(): void
    {
        $previous = PHPMailer::$validator;
        PHPMailer::$validator = 'eai';

        try {
            $this->assertFalse(MailService::isValidAddress('josé@exemple.test'));
            $this->assertTrue(MailService::isValidAddress('unite@exemple.test'));
        } finally {
            PHPMailer::$validator = $previous;
        }
    }

    /**
     * **The copy is inside the signed message, and the message is still
     * multipart.** PHPMailer signs `Cc` among its default headers; this
     * pins that the header is really there when the signature is computed
     * — assembled exactly as a relay would receive it — so a copy can
     * never be a header a receiving server sees as tampered with.
     */
    public function testTheCopyHeaderIsSignedAndTheMessageStaysMultipart(): void
    {
        $dkim = new DkimManager($this->tempDir);
        $dkim->generateKey();
        $transport = $this->assemblingTransport();

        $this->service($transport, null, $dkim)->send(
            to: 'parent@exemple.test',
            subject: "Demande d'inscription reçue",
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            cc: 'unite@exemple.test'
        );

        $message = $transport->message;
        $this->assertMatchesRegularExpression('/^Cc: unite@exemple\.test\r?$/m', $message);
        $this->assertStringContainsString('multipart/alternative', $message);

        $this->assertSame(1, preg_match('/^DKIM-Signature:(.*?)\r?\n(?=\S)/ms', $message, $match), 'signed.');
        $signature = (string) preg_replace('/\s+/', '', $match[1]);
        $this->assertSame(1, preg_match('/(?:^|;)h=([^;]+)/', $signature, $headers));
        $this->assertContains('cc', array_map('strtolower', explode(':', $headers[1])), 'the copy must be signed.');
    }

    /**
     * **A refused copy is not a refused message.** Over SMTP, PHPMailer
     * hands the DATA to every recipient the relay accepted and only then
     * throws for the others — so a `Cc:` the relay rejects used to turn a
     * message the family already had into a failure: the caller told it
     * did not leave (a decision e-mail keeping a tracking link nobody
     * stored), the provider chain delivering it again from the next relay.
     * The real `PhpMailerTransport`, over a relay that refuses the copy:
     * the send returns, the DATA went once, and the journal says the copy
     * was refused — without an address.
     */
    public function testACopyTheRelayRefusesDoesNotFailAMessageThatLeft(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $relay = $this->fakeRelay(['unite@exemple.test']);

        $this->service($this->overRelay($relay), new JournalService(new JournalRepository($pdo)), null, 'smtp')
            ->send('parent@exemple.test', 'Sujet', '<p>x</p>', 'x', cc: 'unite@exemple.test');

        $this->assertSame(1, $relay->dataSent, 'the message left once.');
        $this->assertSame(1, $this->countEvents($pdo, 'mail_copy_refused'));
        $this->assertSame(0, $this->countEvents($pdo, 'mail_send_failed'));

        $statement = $pdo->prepare('SELECT level, context FROM event_log WHERE event_type = ?');
        $statement->execute(['mail_copy_refused']);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('warning', $row['level'] ?? null);
        $this->assertStringNotContainsString('unite@exemple.test', (string) ($row['context'] ?? ''));
    }

    /**
     * **The other way round, the copy is not even asked for.** A refused
     * recipient with an accepted copy would have had the DATA sent to the
     * copy alone — the unit told of a message the family never got, again
     * from every relay the chain tries. No DATA, a failure, and the
     * relay's answer about the recipient as the reason.
     */
    public function testARefusedRecipientSendsNothingToTheCopy(): void
    {
        $relay = $this->fakeRelay(['parent@exemple.test']);

        try {
            $this->service($this->overRelay($relay), null, null, 'smtp')
                ->send('parent@exemple.test', 'Sujet', '<p>x</p>', 'x', cc: 'unite@exemple.test');
            $this->fail('A refused recipient must fail the message.');
        } catch (MailException $e) {
            $this->assertStringContainsString('550 5.1.1', $e->getMessage());
        }

        $this->assertSame(0, $relay->dataSent, 'nothing may reach the copy.');
        $this->assertSame(['parent@exemple.test'], $relay->asked, 'the copy is never asked for.');
    }

    /** A callback a caller set on the message is still called. */
    public function testACallbackAlreadySetIsStillCalled(): void
    {
        $relay = $this->fakeRelay(['unite@exemple.test']);
        $seen = [];
        $transport = new class ($relay, $seen) implements MailTransportInterface {
            /** @param list<string> $seen */
            public function __construct(private RecipientFirstSmtp $relay, private array &$seen)
            {
            }

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $seen = &$this->seen;
                $mail->action_function = static function (bool $isSent, array $to) use (&$seen): void {
                    $seen[] = $to[0][0] . ($isSent ? ' sent' : ' refused');
                };
                (new PhpMailerTransport(fn (): RecipientFirstSmtp => $this->relay))->deliver($mail, $purpose);
            }
        };

        $this->service($transport, null, null, 'smtp')
            ->send('parent@exemple.test', 'Sujet', '<p>x</p>', 'x', cc: 'unite@exemple.test');

        $this->assertSame(['parent@exemple.test sent', 'unite@exemple.test refused'], $seen);
    }

    /** And without a copy, a refused recipient fails exactly as before. */
    public function testWithoutACopyARefusedRecipientFailsAsBefore(): void
    {
        $relay = $this->fakeRelay(['parent@exemple.test']);

        $this->expectException(MailException::class);

        try {
            $this->service($this->overRelay($relay), null, null, 'smtp')
                ->send('parent@exemple.test', 'Sujet', '<p>x</p>', 'x');
        } finally {
            $this->assertSame(0, $relay->dataSent, 'no viable recipient, no DATA.');
        }
    }

    /**
     * An SMTP relay that accepts everything except the addresses given,
     * and opens no socket — behind the real {@see RecipientFirstSmtp}
     * session, which only its two wire calls are replaced in.
     *
     * @param list<string> $refused
     */
    private function fakeRelay(array $refused): RecipientFirstSmtp
    {
        return new class ($refused) extends RecipientFirstSmtp {
            public int $dataSent = 0;
            /** @var list<string> */
            public array $asked = [];
            private string $lastRefused = '';

            /** @param list<string> $refused */
            public function __construct(private array $refused)
            {
                parent::__construct(['parent@exemple.test']);
            }

            public function connected()
            {
                return true;
            }

            protected function openTransaction(string $from): bool
            {
                return true;
            }

            protected function askRelay(string $address, string $dsn): bool
            {
                $this->asked[] = $address;
                if (in_array($address, $this->refused, true)) {
                    $this->lastRefused = $address;

                    return false;
                }

                return true;
            }

            public function getError()
            {
                return [
                    'error' => 'RCPT TO command failed',
                    'detail' => '550 5.1.1 <' . $this->lastRefused . '>: Recipient address rejected',
                    'smtp_code' => '550',
                    'smtp_code_ex' => '5.1.1',
                ];
            }

            public function data($msg_data)
            {
                $this->dataSent++;

                return true;
            }

            public function quit($close_on_error = true)
            {
                return true;
            }

            public function close()
            {
            }
        };
    }

    /**
     * The real PhpMailerTransport, talking to the given relay: as the
     * session of a message carrying a copy, and preset for one without.
     */
    private function overRelay(RecipientFirstSmtp $relay): MailTransportInterface
    {
        return new class ($relay) implements MailTransportInterface {
            public function __construct(private RecipientFirstSmtp $relay)
            {
            }

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                if ($mail->getCcAddresses() === []) {
                    $mail->setSMTPInstance($this->relay);
                }
                (new PhpMailerTransport(fn (): RecipientFirstSmtp => $this->relay))->deliver($mail, $purpose);
            }
        };
    }

    private function countEvents(\PDO $pdo, string $type): int
    {
        $statement = $pdo->prepare('SELECT COUNT(*) FROM event_log WHERE event_type = ?');
        $statement->execute([$type]);

        return (int) $statement->fetchColumn();
    }

    private function service(
        MailTransportInterface $transport,
        ?JournalService $journal = null,
        ?DkimManager $dkim = null,
        string $mode = 'local'
    ): MailService {
        return new MailService(
            mode: $mode,
            fromAddress: 'noreply@exemple.test',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: $dkim ?? new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            smtpHost: 'relais.exemple.test',
            transport: $transport,
            journal: $journal
        );
    }

    /**
     * @return MailTransportInterface&object{calls: int}
     */
    private function recordingTransport(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            public int $calls = 0;
            private ?PHPMailer $last = null;

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->calls++;
                $this->last = $mail;
            }

            public function received(): PHPMailer
            {
                if ($this->last === null) {
                    throw new \LogicException('Nothing was delivered.');
                }

                return $this->last;
            }
        };
    }

    /**
     * Assembles the message the way a relay would receive it — headers,
     * MIME parts, DKIM signature — without sending anything.
     *
     * @return MailTransportInterface&object{message: string}
     */
    private function assemblingTransport(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            public string $message = '';

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                // SMTP shape, as the mail sandbox does: the full header set
                // in one block. preSend() opens no socket.
                $mail->isSMTP();
                $mail->preSend();
                $this->message = $mail->getSentMIMEMessage();
            }
        };
    }
}
