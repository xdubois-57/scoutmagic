<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailException;
use Core\Mail\MailPurpose;
use Core\Mail\MailService;
use Core\Mail\MailTransportInterface;
use Core\Security\EncryptionService;
use Modules\TestTools\Mail\CaptureTransport;
use Modules\TestTools\Repository\CapturedEmailRepository;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The capture itself (ARCHITECTURE.md §8.63): MailService assembles, the
 * transport stores, and nothing goes on the wire.
 */
#[Group('database')]
class CaptureTransportTest extends TestCase
{
    private \PDO $pdo;
    private EncryptionService $encryption;
    private CapturedEmailRepository $repository;
    private EncryptedFileStorageService $fileStorage;
    private string $tempDir;
    private string $storagePath;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->pdo = DatabaseTestHelper::createTestDatabase();
        TestToolsTestHelper::createTables($this->pdo);

        $this->encryption = TestToolsTestHelper::encryption();
        $this->repository = new CapturedEmailRepository($this->pdo, $this->encryption);

        $this->tempDir = sys_get_temp_dir() . '/test_tools_capture_' . uniqid();
        $this->storagePath = $this->tempDir . '/storage';
        mkdir($this->storagePath, 0700, true);

        $this->fileStorage = new EncryptedFileStorageService(
            new FileRepository($this->pdo),
            $this->encryption,
            $this->storagePath
        );
    }

    protected function tearDown(): void
    {
        self::removeDir($this->tempDir);
    }

    /**
     * A transport that captures everything, sign-in links included — the
     * default, and what every test below uses unless it says otherwise.
     * Its pass-through refuses to be called at all, which is the assertion
     * that nothing reaches the network on this path.
     */
    private function transport(): CaptureTransport
    {
        return new CaptureTransport($this->repository, $this->fileStorage, $this->refusingPassThrough());
    }

    /**
     * The same transport with the one exemption turned on, and a
     * pass-through that records what it was handed instead of sending it.
     */
    private function exemptingTransport(
        MailTransportInterface $passThrough,
        ?CapturedEmailRepository $repository = null
    ): CaptureTransport {
        return new CaptureTransport(
            $repository ?? $this->repository,
            $this->fileStorage,
            $passThrough,
            true,
            new JournalService(new JournalRepository($this->pdo))
        );
    }

    private function refusingPassThrough(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                throw new \LogicException('Le transport de secours ne doit jamais être appelé ici.');
            }
        };
    }

    private function recordingPassThrough(): MailTransportInterface
    {
        return new class implements MailTransportInterface {
            public int $calls = 0;
            public ?MailPurpose $purpose = null;

            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                $this->calls++;
                $this->purpose = $purpose;
                // What PhpMailerTransport's send() leaves behind, minus
                // the network: the assembled message on the instance.
                $mail->preSend();
            }
        };
    }

    private function service(?DkimManager $dkimManager = null, ?MailTransportInterface $transport = null): MailService
    {
        return new MailService(
            mode: 'local',
            fromAddress: 'noreply@example.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: $dkimManager ?? new DkimManager($this->tempDir . '/dkim-empty'),
            dkimSelector: 'mail',
            transport: $transport ?? $this->transport()
        );
    }

    public function testCaptureWritesExactlyOneRowAndTheThreeMessageFiles(): void
    {
        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM captured_emails')->fetchColumn());
        // The raw message, plus its HTML half and its plain-text half —
        // both read off the PHPMailer instance so the detail page never
        // has to walk MIME boundaries.
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertSame('[25SV] Bonjour', $email->subject);
        $this->assertSame('destinataire@example.be', $email->recipient);
        $this->assertSame('noreply@example.be', $email->fromAddress);
        $this->assertNull($email->errorMessage);
        $this->assertGreaterThan(0, $email->sizeBytes);
        $this->assertNotNull($email->mimeFileId);
        $this->assertNotNull($email->bodyHtmlFileId);
        $this->assertNotNull($email->bodyTextFileId);
        $this->assertSame('<p>Bonjour</p>', $this->fileStorage->retrieve($email->bodyHtmlFileId));
        $this->assertSame('Bonjour', $this->fileStorage->retrieve($email->bodyTextFileId));
    }

    public function testNothingReachesTheRealTransport(): void
    {
        // A MailService whose transport captures cannot also send: there is
        // exactly one transport, and this is it. Asserted by proving the
        // default one is never constructed into the path — the send()
        // below would have to reach a mail server otherwise, and does not.
        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM captured_emails')->fetchColumn());
    }

    public function testTheStoredMessageIsTheRealAssembledMessage(): void
    {
        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour texte',
            replyTo: 'reponse@example.be'
        );

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertNotNull($email->mimeFileId);
        $message = $this->fileStorage->retrieve($email->mimeFileId);

        $this->assertStringContainsString('Subject: [25SV] Bonjour', $message);
        $this->assertStringContainsString('To: destinataire@example.be', $message);
        $this->assertStringContainsString('reponse@example.be', $message);
        $this->assertStringContainsString('Bonjour texte', $message);
        // SMTP shape: CRLF line endings and the full header block in one piece.
        $this->assertStringContainsString("\r\n", $message);
        $this->assertSame('reponse@example.be', $email->replyTo);
    }

    public function testTheStoredMessageCarriesTheDkimSignatureWhenAKeyIsConfigured(): void
    {
        $dkimManager = new DkimManager($this->tempDir . '/dkim');
        $dkimManager->generateKey();

        $this->service($dkimManager)->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertTrue($email->hasDkim);

        $this->assertNotNull($email->mimeFileId);
        $this->assertStringContainsString('DKIM-Signature:', $this->fileStorage->retrieve($email->mimeFileId));
    }

    public function testAMessageWithoutADkimKeyIsFlaggedAsUnsigned(): void
    {
        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertFalse($this->repository->findPage(10, 0)[0]->hasDkim);
    }

    public function testAttachmentsAreStoredIndividually(): void
    {
        $first = $this->tempDir . '/premier.txt';
        $second = $this->tempDir . '/second.txt';
        file_put_contents($first, 'contenu du premier');
        file_put_contents($second, 'contenu du second');

        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Avec pièces jointes',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            attachments: [
                ['path' => $first, 'name' => 'premier.txt'],
                ['path' => $second, 'name' => 'second.txt'],
            ]
        );

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertSame(2, $email->attachmentCount);
        $this->assertCount(2, $email->attachments);
        $this->assertSame('premier.txt', $email->attachments[0]['file_name']);
        $this->assertSame('second.txt', $email->attachments[1]['file_name']);

        // The raw message and its two body parts, plus one file per attachment.
        $this->assertSame(5, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());

        $this->assertNotNull($email->attachments[0]['file_id']);
        $this->assertSame('contenu du premier', $this->fileStorage->retrieve($email->attachments[0]['file_id']));
    }

    /**
     * The source paths are frequently temporary files, which is exactly why
     * the content is copied at capture time rather than referenced.
     */
    public function testAnAttachmentSurvivesItsSourceFileBeingDeleted(): void
    {
        $path = $this->tempDir . '/ephemere.txt';
        file_put_contents($path, 'contenu éphémère');

        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Avec pièce jointe',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour',
            attachments: [['path' => $path, 'name' => 'ephemere.txt']]
        );

        unlink($path);

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertNotNull($email->attachments[0]['file_id']);
        $this->assertSame('contenu éphémère', $this->fileStorage->retrieve($email->attachments[0]['file_id']));
    }

    /**
     * A failure raised by preSend() itself — inside the transport, after
     * the metadata is known but before there is a message to store. The
     * row is written anyway and the exception is rethrown untouched: a test
     * tool that silently swallows a broken mail is worse than useless.
     */
    public function testAnAssemblyFailureIsCapturedAndStillThrows(): void
    {
        $mail = new PHPMailer(true);
        $mail->setFrom('noreply@example.be', 'Unité Exemple');
        $mail->Subject = '[25SV] Sans destinataire';
        $mail->Body = '<p>Bonjour</p>';

        try {
            // preSend() refuses a message with no recipient at all.
            $this->transport()->deliver($mail, MailPurpose::Ordinary);
            $this->fail('Expected the transport to rethrow');
        } catch (\Exception) {
            // Expected: whatever PHPMailer raised comes straight back out.
        }

        $rows = $this->pdo->query('SELECT * FROM captured_emails')->fetchAll(\PDO::FETCH_ASSOC);
        $this->assertCount(1, $rows);
        $this->assertNotNull($rows[0]['error_message']);
        $this->assertNull($rows[0]['mime_file_id']);
        $this->assertSame('[25SV] Sans destinataire', $rows[0]['subject']);

        // No message was assembled, so no message file was stored.
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    /**
     * …and the rethrow reaches the caller as the MailException it would
     * have seen without the sandbox in the path — MailService wraps
     * anything a transport raises, capture transport included.
     */
    public function testARethrownAssemblyFailureSurfacesAsAMailException(): void
    {
        $service = new MailService(
            mode: 'local',
            fromAddress: 'noreply@example.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: new DkimManager($this->tempDir . '/dkim-empty'),
            dkimSelector: 'mail',
            transport: new class ($this->transport()) implements MailTransportInterface {
                public function __construct(private CaptureTransport $inner)
                {
                }

                public function deliver(PHPMailer $mail, MailPurpose $purpose): void
                {
                    // Same shape as the real failure path: capture, then
                    // rethrow what PHPMailer raised.
                    $this->inner->deliver($mail, $purpose);
                    throw new \RuntimeException('assemblage impossible');
                }
            }
        );

        $this->expectException(MailException::class);
        $this->expectExceptionMessage('assemblage impossible');

        $service->send(
            to: 'destinataire@example.be',
            subject: 'Cassé',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );
    }

    /**
     * The recipient is personal data: BLOB + blind index, never in clear.
     */
    public function testTheRecipientIsStoredEncrypted(): void
    {
        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $row = $this->pdo->query('SELECT recipient, recipient_blind_index FROM captured_emails')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($row);
        $this->assertStringNotContainsString('destinataire@example.be', (string) $row['recipient']);
        $this->assertSame(
            $this->encryption->blindIndex('destinataire@example.be', 'email'),
            (string) $row['recipient_blind_index']
        );
    }

    public function testTheStoredFilesAreEncryptedAtRestAndSuperadminOnly(): void
    {
        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour secret</p>',
            bodyText: 'Bonjour'
        );

        $file = $this->pdo->query('SELECT * FROM files')->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($file);
        $this->assertSame(1, (int) $file['encrypted']);
        $this->assertSame('superadmin', $file['role_min']);
        $this->assertSame('test_tools', $file['module_id']);

        $onDisk = (string) file_get_contents($this->storagePath . '/' . $file['relative_path']);
        $this->assertStringNotContainsString('Bonjour secret', $onDisk);
    }

    /**
     * postSend() is the half that talks to the network. It must never be
     * reached from this module, so the source says so too.
     */
    public function testTheCapturePathNeverCallsSendOrPostSend(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/test_tools/src/Mail/CaptureTransport.php');

        // Still true with the sign-in exemption in place: this module does
        // not send anything itself even then — it hands the message to the
        // ordinary transport, which is the only thing in the codebase that
        // calls send().
        $this->assertStringNotContainsString('->postSend(', $source);
        $this->assertStringNotContainsString('$mail->send(', $source);
        $this->assertStringContainsString('->preSend(', $source);
    }

    public function testTheTransportIsAMailTransport(): void
    {
        $this->assertInstanceOf(MailTransportInterface::class, $this->transport());
    }

    public function testForcingSmtpSemanticsKeepsTheHeadersInTheMessage(): void
    {
        // In `mail` mode PHPMailer moves To and Subject into mailHeader,
        // out of the body of getSentMIMEMessage()'s MIMEHeader block. The
        // transport forces SMTP semantics precisely so the captured message
        // is the complete one a mail client would receive.
        $mail = new PHPMailer(true);
        $mail->isMail();
        $mail->setFrom('noreply@example.be', 'Unité Exemple');
        $mail->addAddress('destinataire@example.be');
        $mail->Subject = '[25SV] Bonjour';
        $mail->isHTML(true);
        $mail->Body = '<p>Bonjour</p>';
        $mail->AltBody = 'Bonjour';

        $this->transport()->deliver($mail, MailPurpose::Ordinary);

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertNotNull($email->mimeFileId);
        $message = $this->fileStorage->retrieve($email->mimeFileId);

        $this->assertStringContainsString('To: destinataire@example.be', $message);
        $this->assertStringContainsString('Subject: [25SV] Bonjour', $message);
    }

    // ----------------------------------------------------------------
    // The one exception the capture admits: the sign-in link
    // (ARCHITECTURE.md §8.63). Off by default — these tests pin both
    // states, and that the exemption is a CATEGORY and not an address.
    // ----------------------------------------------------------------

    public function testASignInLinkIsCapturedLikeEverythingElseByDefault(): void
    {
        // The pass-through here throws if it is ever reached, so this
        // asserts the default really is "nothing leaves the server".
        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Votre lien de connexion',
            bodyHtml: '<p>Connectez-vous</p>',
            bodyText: 'Connectez-vous',
            purpose: MailPurpose::MagicLink
        );

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertFalse($email->delivered);
    }

    public function testTheExemptionLetsASignInLinkOutAndStillFilesIt(): void
    {
        $passThrough = $this->recordingPassThrough();

        $this->service(transport: $this->exemptingTransport($passThrough))->send(
            to: 'destinataire@example.be',
            subject: 'Votre lien de connexion',
            bodyHtml: '<p>Connectez-vous</p>',
            bodyText: 'Connectez-vous',
            purpose: MailPurpose::MagicLink
        );

        // It really went out, through the ordinary transport and with the
        // purpose intact.
        $this->assertSame(1, $passThrough->calls);
        $this->assertSame(MailPurpose::MagicLink, $passThrough->purpose);

        // …and it is filed anyway, flagged, so the sandbox stays a
        // complete record of what the site sent.
        $email = $this->repository->findPage(10, 0)[0];
        $this->assertTrue($email->delivered);
        $this->assertSame('[25SV] Votre lien de connexion', $email->subject);
        $this->assertNull($email->errorMessage);
        $this->assertNotNull($email->mimeFileId);
        $this->assertStringContainsString(
            'Votre lien de connexion',
            (string) $this->fileStorage->retrieve($email->mimeFileId)
        );
    }

    /**
     * The exemption is a category, never an address and never "everything
     * while I am testing": an ordinary e-mail is captured exactly as
     * before, even with the exemption on.
     */
    public function testTheExemptionAppliesToSignInLinksAndToNothingElse(): void
    {
        $passThrough = $this->recordingPassThrough();

        $this->service(transport: $this->exemptingTransport($passThrough))->send(
            to: 'destinataire@example.be',
            subject: 'Convocation',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertSame(0, $passThrough->calls);

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertFalse($email->delivered);
    }

    /**
     * A refused delivery on the exempted path behaves like a failed
     * assembly: the row is written with the error, `delivered` stays
     * false, and the exception reaches the caller as a MailException.
     */
    public function testARefusedDeliveryOnTheExemptedPathIsFiledAndRethrown(): void
    {
        $refusing = new class implements MailTransportInterface {
            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                throw new \RuntimeException('serveur de messagerie injoignable');
            }
        };

        try {
            $this->service(transport: $this->exemptingTransport($refusing))->send(
                to: 'destinataire@example.be',
                subject: 'Votre lien de connexion',
                bodyHtml: '<p>Connectez-vous</p>',
                bodyText: 'Connectez-vous',
                purpose: MailPurpose::MagicLink
            );
            $this->fail('Une MailException était attendue.');
        } catch (MailException $e) {
            $this->assertStringContainsString('serveur de messagerie injoignable', $e->getMessage());
        }

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertFalse($email->delivered);
        $this->assertNotNull($email->errorMessage);
        $this->assertNull($email->mimeFileId);
    }

    /**
     * Past the delivery, everything is bookkeeping — and a bookkeeping
     * fault is not a delivery fault. Letting it out would make
     * MailService journal `mail_send_failed` and hand AuthService a
     * MailException for a sign-in link already sitting in an inbox: the
     * site telling a visitor their e-mail could not be sent while they
     * are reading it.
     */
    public function testAFilingFailureAfterDeliveryNeitherFailsTheSendNorGoesUnrecorded(): void
    {
        $passThrough = $this->recordingPassThrough();

        // A repository whose write fails, standing in for the encrypted
        // write or the INSERT giving way after the message has left.
        $breaking = new class ($this->pdo, $this->encryption) extends CapturedEmailRepository {
            public function create(
                \DateTimeImmutable $capturedAt,
                string $subject,
                string $recipient,
                string $fromAddress,
                ?string $replyTo,
                int $sizeBytes,
                bool $hasDkim,
                ?int $mimeFileId,
                ?int $bodyHtmlFileId,
                ?int $bodyTextFileId,
                ?string $errorMessage,
                array $attachments,
                bool $delivered = false
            ): int {
                throw new \RuntimeException('écriture impossible');
            }
        };

        // No exception reaches the caller: the mail did leave.
        $this->service(transport: $this->exemptingTransport($passThrough, $breaking))->send(
            to: 'destinataire@example.be',
            subject: 'Votre lien de connexion',
            bodyHtml: '<p>Connectez-vous</p>',
            bodyText: 'Connectez-vous',
            purpose: MailPurpose::MagicLink
        );

        $this->assertSame(1, $passThrough->calls);

        // …and the gap is written down rather than swallowed.
        $entry = $this->pdo
            ->query("SELECT * FROM event_log WHERE event_type = 'mail_capture_delivery_unfiled'")
            ->fetch(\PDO::FETCH_ASSOC);
        $this->assertIsArray($entry, 'a delivery the sandbox could not file must reach the journal');
        $this->assertSame('error', $entry['level']);
        $this->assertSame('test_tools', $entry['category']);
        $this->assertStringNotContainsString('@', (string) $entry['description']);
    }

    /**
     * `error_message` is a plain TEXT column in the very table that
     * encrypts `recipient` as a BLOB because a recipient is personal
     * data. PHPMailer glues the address that failed to the SMTP code
     * that explains it, and on the exempted path a per-recipient refusal
     * is the ordinary case.
     */
    public function testAFailureReasonIsStoredWithTheAddressesTakenOut(): void
    {
        $refusing = new class implements MailTransportInterface {
            public function deliver(PHPMailer $mail, MailPurpose $purpose): void
            {
                throw new \RuntimeException('SMTP Error: 550 5.1.1 <destinataire@example.be> User unknown');
            }
        };

        try {
            $this->service(transport: $this->exemptingTransport($refusing))->send(
                to: 'destinataire@example.be',
                subject: 'Votre lien de connexion',
                bodyHtml: '<p>Connectez-vous</p>',
                bodyText: 'Connectez-vous',
                purpose: MailPurpose::MagicLink
            );
            $this->fail('Une MailException était attendue.');
        } catch (MailException) {
            // Expected — the delivery really did fail here.
        }

        $stored = (string) $this->pdo->query('SELECT error_message FROM captured_emails')->fetchColumn();

        $this->assertStringNotContainsString('destinataire@example.be', $stored);
        $this->assertStringContainsString('[adresse]', $stored);
        // The half that actually diagnoses the problem survives.
        $this->assertStringContainsString('550 5.1.1', $stored);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
