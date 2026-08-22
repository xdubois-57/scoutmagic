<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Mail\DkimManager;
use Core\Mail\MailException;
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
 * The capture itself (ARCHITECTURE.md §8.61): MailService assembles, the
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

    private function transport(): CaptureTransport
    {
        return new CaptureTransport($this->repository, $this->fileStorage);
    }

    private function service(?DkimManager $dkimManager = null): MailService
    {
        return new MailService(
            mode: 'local',
            fromAddress: 'noreply@example.be',
            fromName: 'Unité Exemple',
            shortName: '25SV',
            dkimManager: $dkimManager ?? new DkimManager($this->tempDir . '/dkim-empty'),
            dkimSelector: 'mail',
            transport: $this->transport()
        );
    }

    public function testCaptureWritesExactlyOneRowAndOneEncryptedFile(): void
    {
        $this->service()->send(
            to: 'destinataire@example.be',
            subject: 'Bonjour',
            bodyHtml: '<p>Bonjour</p>',
            bodyText: 'Bonjour'
        );

        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM captured_emails')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertSame('[25SV] Bonjour', $email->subject);
        $this->assertSame('destinataire@example.be', $email->recipient);
        $this->assertSame('noreply@example.be', $email->fromAddress);
        $this->assertNull($email->errorMessage);
        $this->assertGreaterThan(0, $email->sizeBytes);
        $this->assertNotNull($email->mimeFileId);
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

        // The raw message plus one file per attachment.
        $this->assertSame(3, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());

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
            $this->transport()->deliver($mail);
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

                public function deliver(PHPMailer $mail): void
                {
                    // Same shape as the real failure path: capture, then
                    // rethrow what PHPMailer raised.
                    $this->inner->deliver($mail);
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

        $this->transport()->deliver($mail);

        $email = $this->repository->findPage(10, 0)[0];
        $this->assertNotNull($email->mimeFileId);
        $message = $this->fileStorage->retrieve($email->mimeFileId);

        $this->assertStringContainsString('To: destinataire@example.be', $message);
        $this->assertStringContainsString('Subject: [25SV] Bonjour', $message);
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
