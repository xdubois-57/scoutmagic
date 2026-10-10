<?php

declare(strict_types=1);

namespace Tests\Modules\Documents\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Pdf\PdfCompressor;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\Documents\Task\CompressDocumentHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Documents\DocumentsTestHelper;

/**
 * The background compression of a shared document (#804): replaces the
 * file in place and atomically, and stops without an error whenever there is
 * nothing to do.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class CompressDocumentHandlerTest extends TestCase
{
    private const SMALL = "%PDF-1.4\n%%EOF\n";

    private \PDO $pdo;
    private string $storage;
    private TaskContext $context;
    private FileRepository $files;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        DocumentsTestHelper::createTables($this->pdo);
        $this->storage = DocumentsTestHelper::storage();
        $this->files = new FileRepository($this->pdo);
        $this->context = new TaskContext(
            Connection::withPdo($this->pdo),
            new EncryptionService(str_repeat('a', 32), str_repeat('b', 32)),
            $this->createStub(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            $this->createStub(UserAccountRepository::class),
            $this->storage
        );
    }

    protected function tearDown(): void
    {
        DocumentsTestHelper::removeTree($this->storage);
    }

    private static function big(): string
    {
        return "%PDF-1.4\n" . str_repeat('0 0 0 rg ', 400) . "\n%%EOF\n";
    }

    /** A document whose file is $content, uploaded through the service so the row is real. */
    private function upload(string $name, string $content): int
    {
        $service = DocumentsTestHelper::service($this->pdo, $this->storage);

        return $service->create('Doc ' . $name, null, 'public', DocumentsTestHelper::upload($name, $content), null)->fileId;
    }

    private function pathOf(int $fileId): string
    {
        $file = $this->files->findById($fileId);
        $this->assertNotNull($file);

        return $this->storage . '/' . $file->relativePath;
    }

    /** @param callable(): ?string $outcome null = the tool fails */
    private function compressor(string $backend, callable $outcome): PdfCompressor
    {
        return new class ($this->storage . '/temp', $backend, $outcome) extends PdfCompressor {
            /** @param callable(): ?string $outcome */
            public function __construct(string $temp, private string $backend, private $outcome)
            {
                parent::__construct($temp);
            }

            public function detectBackend(): string
            {
                return $this->backend;
            }

            protected function executeBackend(string $backend, string $inputPath, string $outputPath, string $quality): bool
            {
                $result = ($this->outcome)();

                return $result !== null && file_put_contents($outputPath, $result) !== false;
            }
        };
    }

    private function compress(int $fileId, PdfCompressor $compressor): void
    {
        (new CompressDocumentHandler($compressor))->handle(['file_id' => $fileId], $this->context);
    }

    public function testTheFileIsReplacedInPlaceWithTheSameIdentifier(): void
    {
        $fileId = $this->upload('r.pdf', self::big());
        $path = $this->pathOf($fileId);

        $this->compress($fileId, $this->compressor('ghostscript', static fn(): string => self::SMALL));

        $this->assertSame(self::SMALL, file_get_contents($path));
        $this->assertSame($path, $this->pathOf($fileId), 'same file, same address');
        $this->assertSame(strlen(self::SMALL), $this->files->findById($fileId)?->sizeBytes);
    }

    /** Written beside the file then renamed over it: no temporary file is left behind. */
    public function testNoTemporaryFileIsLeftNextToTheDocument(): void
    {
        $fileId = $this->upload('r.pdf', self::big());
        $directory = dirname($this->pathOf($fileId));
        $before = scandir($directory);

        $this->compress($fileId, $this->compressor('ghostscript', static fn(): string => self::SMALL));

        $this->assertSame($before, scandir($directory));
    }

    public function testAFileDeletedInTheMeantimeIsNotAnError(): void
    {
        $fileId = $this->upload('r.pdf', self::big());
        unlink($this->pathOf($fileId));

        $this->compress($fileId, $this->compressor('ghostscript', static fn(): string => self::SMALL));
        (new CompressDocumentHandler())->handle(['file_id' => 999999], $this->context);
        (new CompressDocumentHandler())->handle([], $this->context);

        $this->addToAssertionCount(1);
    }

    public function testAFileThatIsNotAPdfIsLeftAlone(): void
    {
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
        $fileId = $this->upload('logo.png', $png);
        // The upload re-encodes an image: what is on disk is what must survive.
        $stored = file_get_contents($this->pathOf($fileId));

        $this->compress($fileId, $this->compressor('ghostscript', static fn(): string => self::SMALL));

        $this->assertSame($stored, file_get_contents($this->pathOf($fileId)));
    }

    public function testAPastVersionIsNotWorthCompressing(): void
    {
        $fileId = $this->upload('r.pdf', self::big());
        $this->pdo->prepare(
            'INSERT INTO document_versions (document_id, version_number, file_id, size_bytes, uploaded_at) VALUES (1, 1, ?, 10, ?)'
        )->execute([$fileId, '2026-10-01 10:00:00']);

        $this->compress($fileId, $this->compressor('ghostscript', static fn(): string => self::SMALL));

        $this->assertSame(self::big(), file_get_contents($this->pathOf($fileId)));
    }

    public function testWithoutAToolTheFileIsUntouched(): void
    {
        $fileId = $this->upload('r.pdf', self::big());

        $this->compress($fileId, $this->compressor(PdfCompressor::BACKEND_NONE, static fn(): string => self::SMALL));

        $this->assertSame(self::big(), file_get_contents($this->pathOf($fileId)));
    }

    public function testAToolThatFailsOrDoesNotShrinkLeavesTheFileUntouched(): void
    {
        $fileId = $this->upload('r.pdf', self::big());

        $this->compress($fileId, $this->compressor('ghostscript', static fn(): ?string => null));
        $this->assertSame(self::big(), file_get_contents($this->pathOf($fileId)), 'the tool failed');

        $this->compress($fileId, $this->compressor('ghostscript', static fn(): string => self::big() . 'x'));
        $this->assertSame(self::big(), file_get_contents($this->pathOf($fileId)), 'no gain');
        $this->assertSame(strlen(self::big()), $this->files->findById($fileId)?->sizeBytes);
    }
}
