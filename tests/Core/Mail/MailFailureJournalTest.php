<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * An e-mail that does not leave has to be findable afterwards.
 *
 * The complaint this comes from: an administrator sends a support ticket,
 * the test e-mail that goes with it fails, the screen says so — and
 * /admin/journal, the one place anybody looks next and the one thing the
 * diagnostic archive carries, has nothing at all. There are some ninety
 * send() sites; a few journal a failure themselves, several swallow the
 * exception on purpose, and the rest turn it into a flash message that
 * is gone on the next page load. So the entry is written at the single
 * point they all go through, and no call site can forget it.
 */
class MailFailureJournalTest extends TestCase
{
    private string $tempDir;
    private \PDO $pdo;
    private JournalRepository $journalRepository;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/mailfailurejournal_' . uniqid();
        mkdir($this->tempDir, 0700, true);
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->journalRepository = new JournalRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->tempDir);
    }

    /**
     * A transport that refuses, the way a relay does.
     */
    private function refusingTransport(string $message): MailTransportInterface
    {
        return new class ($message) implements MailTransportInterface {
            public function __construct(private string $message)
            {
            }

            public function deliver(PHPMailer $mail): void
            {
                $mail->ErrorInfo = $this->message;

                throw new \PHPMailer\PHPMailer\Exception($this->message);
            }
        };
    }

    private function service(
        MailTransportInterface $transport,
        ?JournalService $journal,
        string $mode = 'local',
        string $fromAddress = 'noreply@example.be'
    ): MailService {
        return new MailService(
            mode: $mode,
            fromAddress: $fromAddress,
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            transport: $transport,
            journal: $journal ?? new JournalService($this->journalRepository)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function entries(): array
    {
        return $this->journalRepository->search();
    }

    public function testAFailedSendIsJournaledAsAnError(): void
    {
        $service = $this->service($this->refusingTransport('SMTP Error: Could not authenticate.'), null);

        $this->expectException(MailException::class);

        try {
            $service->send('destinataire@example.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');
        } finally {
            $entries = $this->entries();
            $this->assertCount(1, $entries);
            $this->assertSame('core', $entries[0]['category']);
            $this->assertSame('mail_send_failed', $entries[0]['event_type']);
            $this->assertSame('error', $entries[0]['level']);

            $context = json_decode((string) $entries[0]['context'], true);
            $this->assertSame('SMTP Error: Could not authenticate.', $context['reason']);
            $this->assertSame('local', $context['mode']);
            $this->assertTrue($context['configured']);
        }
    }

    /**
     * The exception the caller reads is untouched: the journal entry is
     * an addition, never a replacement, and a caller that renders
     * PHPMailer's own words still gets them.
     */
    public function testTheCallerStillSeesTheOriginalFailure(): void
    {
        $service = $this->service($this->refusingTransport('SMTP connect() failed.'), null);

        try {
            $service->send('destinataire@example.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');
            $this->fail('MailException attendue');
        } catch (MailException $e) {
            $this->assertSame('SMTP connect() failed.', $e->getMessage());
        }
    }

    /**
     * SECURITY.md's rule for journal entries has no exception for « it
     * was in the error message »: this journal is readable by every admin
     * and travels to the maintainer inside the diagnostic archive, and
     * PHPMailer names the address that failed right next to the SMTP code
     * that says why. The code is the diagnosis; the address is a member.
     */
    public function testTheRecipientAddressNeverReachesTheJournal(): void
    {
        $service = $this->service(
            $this->refusingTransport(
                'SMTP Error: The following recipients failed: jean.dupont+scouts@exemple.be: 550 5.1.1 User unknown'
            ),
            null
        );

        try {
            $service->send('jean.dupont+scouts@exemple.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');
        } catch (MailException) {
            // The point of this test is what was written down.
        }

        $context = json_decode((string) $this->entries()[0]['context'], true);
        $this->assertStringNotContainsString('jean.dupont', $context['reason']);
        $this->assertStringNotContainsString('exemple.be', $context['reason']);
        // What diagnoses the problem survives intact.
        $this->assertStringContainsString('550 5.1.1 User unknown', $context['reason']);
        $this->assertStringContainsString('[adresse]', $context['reason']);
    }

    /**
     * « Not configured » and « the relay refused » read the same on
     * screen and are completely different problems, so the entry says
     * which one it was without ever naming a host, a user or a password.
     */
    public function testTheEntrySaysWhetherMailWasConfiguredAtAll(): void
    {
        $service = $this->service(
            $this->refusingTransport('Invalid address: (From): '),
            null,
            'smtp',
            ''
        );

        try {
            $service->send('destinataire@example.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');
        } catch (MailException) {
            // Asserted below.
        }

        $context = json_decode((string) $this->entries()[0]['context'], true);
        $this->assertSame('smtp', $context['mode']);
        $this->assertFalse($context['configured']);
    }

    /**
     * send() is told a recipient, a subject and a body and nothing that
     * says why — so « which feature » is read off the call stack rather
     * than added to ninety call sites. It is the difference between « an
     * e-mail failed » and « the support probe's e-mail failed ».
     */
    public function testTheEntryNamesTheFeatureThatWasTryingToSend(): void
    {
        $service = $this->service($this->refusingTransport('SMTP connect() failed.'), null);

        try {
            $service->send('destinataire@example.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');
        } catch (MailException) {
            // Asserted below.
        }

        $context = json_decode((string) $this->entries()[0]['context'], true);
        $this->assertSame(self::class . '::testTheEntryNamesTheFeatureThatWasTryingToSend', $context['origin']);
    }

    /**
     * The journal must never be the reason a caller loses the exception
     * it knows how to read: no table yet, or a database that just went
     * away — the very conditions under which mail also stops working.
     */
    public function testAJournalThatItselfFailsDoesNotMaskTheMailFailure(): void
    {
        $this->pdo->exec('DROP TABLE event_log');

        $service = $this->service($this->refusingTransport('SMTP connect() failed.'), null);

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('SMTP connect() failed.');

        $service->send('destinataire@example.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');
    }

    /**
     * The setup wizard tests the values sitting in its own form, on an
     * installation whose database may not exist yet.
     */
    public function testAServiceBuiltWithoutAJournalStillSendsAndStillThrows(): void
    {
        $service = new MailService(
            mode: 'local',
            fromAddress: 'noreply@example.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager($this->tempDir),
            dkimSelector: 'mail',
            transport: $this->refusingTransport('SMTP connect() failed.')
        );

        $this->expectException(MailException::class);

        $service->send('destinataire@example.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');
    }

    public function testASuccessfulSendWritesNothing(): void
    {
        $transport = new class implements MailTransportInterface {
            public function deliver(PHPMailer $mail): void
            {
            }
        };

        $this->service($transport, null)->send('destinataire@example.be', 'Bonjour', '<p>Bonjour</p>', 'Bonjour');

        $this->assertSame([], $this->entries());
    }
}
