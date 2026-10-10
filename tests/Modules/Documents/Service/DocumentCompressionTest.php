<?php

declare(strict_types=1);

namespace Tests\Modules\Documents\Service;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Pdf\PdfCompressor;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\System\CronExecutionFacts;
use Modules\Documents\Service\DocumentService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;
use Tests\Modules\Documents\DocumentsTestHelper;

/**
 * Where a shared document's PDF is compressed (#804): in a task when the
 * CRON's PHP is known to compress, as it always was — in the upload — when
 * it is not. The web PHP of a shared host may launch no program at all, so
 * deciding in the upload alone silently never compressed there.
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
final class DocumentCompressionTest extends TestCase
{
    private const BIG = '%PDF-1.4 ' . "\n" . '%%EOF';

    private \PDO $pdo;
    private string $storage;
    private SettingService $settings;
    private SchedulerRepository $scheduled;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        DocumentsTestHelper::createTables($this->pdo);
        $this->storage = DocumentsTestHelper::storage();
        $this->settings = new SettingService(new SettingRepository($this->pdo));
        $this->settings->register(CronExecutionFacts::SETTING, '', 'text', 'x', 'x', null, null, null, false);
        $this->scheduled = new SchedulerRepository($this->pdo);
    }

    protected function tearDown(): void
    {
        DocumentsTestHelper::removeTree($this->storage);
    }

    private function cronMeasured(?bool $procOpen, ?string $backend): void
    {
        $this->settings->setInternal(CronExecutionFacts::SETTING, (string) json_encode(
            (new CronExecutionFacts(
                1_800_000_000, 'cli', true, true, 'exec', 'code 0', null, null, $procOpen, $backend
            ))->toArray()
        ));
    }

    private function service(?PdfCompressor $compressor = null): DocumentService
    {
        return DocumentsTestHelper::service(
            $this->pdo,
            $this->storage,
            compressor: $compressor,
            scheduler: new SchedulerService($this->scheduled),
            settings: $this->settings
        );
    }

    /** A compressor that finds a tool and shrinks anything to a tiny valid PDF. */
    private function shrinkingCompressor(): PdfCompressor
    {
        return new class ($this->storage . '/temp') extends PdfCompressor {
            public function detectBackend(): string
            {
                return self::BACKEND_GHOSTSCRIPT;
            }

            protected function executeBackend(string $backend, string $inputPath, string $outputPath, string $quality): bool
            {
                return file_put_contents($outputPath, "%PDF-1.4\n%%EOF\n") !== false;
            }
        };
    }

    private static function bigPdf(): string
    {
        return "%PDF-1.4\n" . str_repeat('0 0 0 rg ', 400) . "\n%%EOF\n";
    }

    /** @return list<array<string, mixed>> */
    private function queued(): array
    {
        return $this->scheduled->findByModuleAndTaskKey('documents', DocumentService::COMPRESS_TASK);
    }

    public function testWhenTheCronCanCompressTheUploadOnlyQueuesTheTaskAndKeepsTheOriginal(): void
    {
        $this->cronMeasured(true, 'ghostscript');
        $original = self::bigPdf();

        $document = $this->service($this->shrinkingCompressor())->create(
            'Règlement',
            null,
            'public',
            DocumentsTestHelper::upload('r.pdf', $original),
            null
        );

        $tasks = $this->queued();
        $this->assertCount(1, $tasks);
        $this->assertSame(['file_id' => $document->fileId], json_decode((string) $tasks[0]['payload'], true));
        $file = (new \Core\File\FileRepository($this->pdo))->findById($document->fileId);
        $this->assertNotNull($file);
        $this->assertSame(
            $original,
            file_get_contents($this->storage . '/' . $file->relativePath),
            'served as uploaded until the task has run'
        );
    }

    /**
     * @return iterable<string, array{?bool, ?string}>
     */
    public static function cronsThatCannotCompress(): iterable
    {
        yield 'never measured' => [null, null];
        yield 'measured before PDFs' => [null, null];
        yield 'proc_open off' => [false, 'none'];
        yield 'no tool' => [true, 'none'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('cronsThatCannotCompress')]
    public function testWhenTheCronCannotCompressTheUploadCompressesItAsItAlwaysDid(?bool $procOpen, ?string $backend): void
    {
        if ($backend !== null) {
            $this->cronMeasured($procOpen, $backend);
        }

        $document = $this->service($this->shrinkingCompressor())->create(
            'Règlement',
            null,
            'public',
            DocumentsTestHelper::upload('r.pdf', self::bigPdf()),
            null
        );

        $this->assertSame([], $this->queued(), 'nothing queued for a cron that cannot compress');
        $file = (new \Core\File\FileRepository($this->pdo))->findById($document->fileId);
        $this->assertNotNull($file);
        $this->assertSame("%PDF-1.4\n%%EOF\n", file_get_contents($this->storage . '/' . $file->relativePath));
        $this->assertSame(strlen("%PDF-1.4\n%%EOF\n"), $file->sizeBytes);
    }

    public function testAFileThatIsNotAPdfQueuesNothing(): void
    {
        $this->cronMeasured(true, 'ghostscript');
        // A 1x1 PNG, recognised from its header alone.
        $png = (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );

        $this->service()->create('Logo', null, 'public', DocumentsTestHelper::upload('logo.png', $png), null);

        $this->assertSame([], $this->queued());
    }

    public function testWithoutASchedulerTheUploadStillCompressesItself(): void
    {
        $this->cronMeasured(true, 'ghostscript');
        $service = DocumentsTestHelper::service($this->pdo, $this->storage, compressor: $this->shrinkingCompressor());

        $document = $service->create('Règlement', null, 'public', DocumentsTestHelper::upload('r.pdf', self::bigPdf()), null);

        $file = (new \Core\File\FileRepository($this->pdo))->findById($document->fileId);
        $this->assertNotNull($file);
        $this->assertSame("%PDF-1.4\n%%EOF\n", file_get_contents($this->storage . '/' . $file->relativePath));
    }
}
