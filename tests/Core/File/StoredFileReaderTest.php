<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\File\StoredFileReader;
use Core\File\UploadHandler;
use Core\Security\EncryptionService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * Reading a file back without knowing who wrote it.
 *
 * The bug this closes was invisible from every screen: an inbound-mail
 * attachment is written by UploadHandler, unencrypted, and finance read it
 * through EncryptedFileStorageService::retrieve(), which handed plaintext
 * to decrypt(). The throw became a null, the null became « pas d'octets »,
 * and the receipt was never created — silently, on every message.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class StoredFileReaderTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private FileRepository $files;
    private EncryptedFileStorageService $encryptedStorage;
    private StoredFileReader $reader;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_reader_' . bin2hex(random_bytes(8));
        mkdir($this->storagePath, 0777, true);

        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->files = new FileRepository($this->pdo);
        $this->encryptedStorage = new EncryptedFileStorageService($this->files, $encryption, $this->storagePath);
        $this->reader = new StoredFileReader($this->files, $this->encryptedStorage, $this->storagePath);
    }

    protected function tearDown(): void
    {
        // Depth-first: the files live two directories down
        // (inbound_mail/attachments, finance/receipts).
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->storagePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }

        @rmdir($this->storagePath);
    }

    public function testAFileWrittenUnencryptedComesBackAsUsableBytes(): void
    {
        // Exactly how MailboxSyncService stores an inbound attachment.
        //
        // NOT byte-identical to the input: UploadHandler re-encodes images
        // to strip EXIF, so what is on disk is its own PNG. What matters is
        // that the bytes come back as the image they are, rather than as a
        // failed decryption.
        $fileId = $this->uploadHandlerWrite(self::onePixelPng());

        $content = $this->reader->read($fileId);

        $this->assertNotNull($content);
        $this->assertSame("\x89PNG\r\n\x1a\n", substr($content, 0, 8), 'the bytes must still be a PNG');

        $file = $this->files->findById($fileId);
        $this->assertNotNull($file);
        $this->assertSame($file->sizeBytes, strlen($content), 'and the whole file, not a truncated read');
    }

    public function testAFileWrittenEncryptedIsDecrypted(): void
    {
        $fileId = $this->encryptedStorage->store(
            'des octets secrets',
            'application/pdf',
            'recu.pdf',
            'finance/receipts',
            'intendant',
            'finance'
        );

        $this->assertSame('des octets secrets', $this->reader->read($fileId));
    }

    /**
     * The regression itself: the encrypted reader cannot read a plain file,
     * and that is exactly what finance was asking it to do.
     */
    public function testTheEncryptedReaderAloneCannotReadAPlainFile(): void
    {
        $fileId = $this->uploadHandlerWrite(self::onePixelPng());

        $failed = false;
        try {
            $this->encryptedStorage->retrieve($fileId);
        } catch (\Throwable) {
            $failed = true;
        }

        $this->assertTrue(
            $failed,
            'If this ever stops throwing, the silent-null the caller wrote around it stops being needed.'
        );
    }

    public function testAnUnknownFileIsNullRatherThanAThrow(): void
    {
        // Every caller is a background pass over somebody else's file: one
        // unreadable attachment must not fail the run that found it.
        $this->assertNull($this->reader->read(999999));
    }

    public function testAFileMissingFromDiskIsNullToo(): void
    {
        $fileId = $this->uploadHandlerWrite(self::onePixelPng());
        $file = $this->files->findById($fileId);
        $this->assertNotNull($file);
        unlink($this->storagePath . '/' . $file->relativePath);

        $this->assertNull($this->reader->read($fileId));
    }

    private function uploadHandlerWrite(string $bytes): int
    {
        $temporary = tempnam(sys_get_temp_dir(), 'reader-test-');
        self::assertIsString($temporary);
        file_put_contents($temporary, $bytes);

        return (new UploadHandler($this->files, $this->storagePath))->handle(
            ['tmp_name' => $temporary, 'name' => 'ticket.png', 'size' => strlen($bytes), 'error' => UPLOAD_ERR_OK],
            'inbound_mail/attachments',
            ['image/png'],
            5 * 1024 * 1024,
            'intendant',
            'inbound_mail'
        );
    }

    /**
     * A real PNG: UploadHandler sniffs the type and re-encodes images to
     * strip EXIF, so a made-up string would be refused.
     */
    private static function onePixelPng(): string
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            true
        );
        self::assertIsString($bytes);

        return $bytes;
    }
}
