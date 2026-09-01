<?php

declare(strict_types=1);

namespace Tests\Modules\Camps\Mail;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\Security\EncryptionService;
use Modules\Camps\Mail\AttachmentTextReader;
use Modules\InboundMail\Api\InboundAttachment;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * A booking is almost never written in the body of the e-mail that carries
 * it — the real ones are a one-word covering note and a PDF contract.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class AttachmentTextReaderTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private FileRepository $files;
    private AttachmentTextReader $reader;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/campsattachments_' . uniqid();
        mkdir($this->storagePath . '/inbound', 0700, true);
        $this->files = new FileRepository($this->pdo);
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));

        $this->reader = new AttachmentTextReader(new StoredFileReader(
            $this->files,
            new EncryptedFileStorageService($this->files, $encryption, $this->storagePath),
            $this->storagePath
        ));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/inbound/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/inbound');
        @rmdir($this->storagePath);
    }

    public function testAContractsDatesComeOutOfThePdfAndNotOutOfTheBody(): void
    {
        $attachment = $this->store(
            'contrat.pdf',
            'application/pdf',
            (string) file_get_contents(dirname(__DIR__, 3) . '/fixtures/pdf/camp_booking_contract.pdf')
        );

        $text = $this->reader->read([$attachment]);

        $this->assertStringContainsString('Arrivee: 18-09-26', $text);
        $this->assertStringContainsString('LE GRAND PRE', $text);
    }

    public function testAPlainTextPartIsReadAsItIs(): void
    {
        $attachment = $this->store('note.txt', 'text/plain', 'Du 12 au 19 juillet 2028.');

        $this->assertSame('Du 12 au 19 juillet 2028.', $this->reader->read([$attachment]));
    }

    public function testAScannedContractIsNotOcredAndCostsNothing(): void
    {
        // A picture of a contract has no text layer, and rasterising every
        // image of every message to find that out would cost far more than
        // the stays it would win.
        $attachment = $this->store('scan.jpg', 'image/jpeg', 'not text at all');

        $this->assertSame('', $this->reader->read([$attachment]));
    }

    public function testAHugeAttachmentIsNotEvenFetched(): void
    {
        $attachment = new InboundAttachment(
            1,
            1,
            9999,
            'annexes.pdf',
            'application/pdf',
            AttachmentTextReader::MAX_FILE_BYTES + 1,
            'hash'
        );

        // File id 9999 does not exist: reaching the disk at all would be
        // the bug, and the size check is what stops it.
        $this->assertSame('', $this->reader->read([$attachment]));
    }

    public function testOnlyTheFirstFewAttachmentsAreRead(): void
    {
        $attachments = [];
        foreach (range(1, AttachmentTextReader::MAX_ATTACHMENTS + 2) as $n) {
            $attachments[] = $this->store("piece{$n}.txt", 'text/plain', "piece {$n}");
        }

        $text = $this->reader->read($attachments);

        $this->assertStringContainsString('piece 1', $text);
        $this->assertStringNotContainsString(
            'piece ' . (AttachmentTextReader::MAX_ATTACHMENTS + 1),
            $text
        );
    }

    public function testTheTextHandedBackIsBounded(): void
    {
        $attachment = $this->store('long.txt', 'text/plain', str_repeat('a', AttachmentTextReader::MAX_CHARS * 2));

        $this->assertSame(AttachmentTextReader::MAX_CHARS, mb_strlen($this->reader->read([$attachment])));
    }

    public function testAMissingFileIsNotAnError(): void
    {
        $attachment = $this->store('parti.pdf', 'application/pdf', 'x');
        @unlink($this->storagePath . '/inbound/parti.pdf');

        $this->assertSame('', $this->reader->read([$attachment]));
    }

    public function testAMessageWithNoAttachmentsReadsAsNothing(): void
    {
        $this->assertSame('', $this->reader->read([]));
    }

    private function store(string $name, string $mimeType, string $bytes): InboundAttachment
    {
        file_put_contents($this->storagePath . '/inbound/' . $name, $bytes);
        $fileId = $this->files->create(
            'inbound/' . $name,
            $name,
            $mimeType,
            strlen($bytes),
            'chief',
            'inbound_mail',
            null,
            false
        );

        return new InboundAttachment(
            $fileId,
            1,
            $fileId,
            $name,
            $mimeType,
            strlen($bytes),
            hash('sha256', $bytes)
        );
    }
}
