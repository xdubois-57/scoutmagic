<?php

declare(strict_types=1);

namespace Tests\Modules\News\Task;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Database\Connection;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Photo\ImageVariantService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\UserAccountRepository;
use Modules\News\Task\GenerateImageVariantsHandler;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The one-shot backfill behind the news templates' /files/{id}/thumb|md
 * URLs: images uploaded before the module generated variants at upload
 * would 404 forever otherwise (FileController::variant() deliberately
 * never falls back to the original).
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class GenerateImageVariantsHandlerTest extends TestCase
{
    private \PDO $pdo;
    private string $storagePath;
    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $this->storagePath = sys_get_temp_dir() . '/scoutmagic-news-variants-' . bin2hex(random_bytes(4));
        mkdir($this->storagePath, 0777, true);

        (new SettingService(new SettingRepository($this->pdo)))->register(
            GenerateImageVariantsHandler::DONE_FLAG, '0', 'boolean', 'Interne', 'Desc', 'news', null, null, false
        );
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/news/images/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->storagePath . '/news/images');
        @rmdir($this->storagePath . '/news');
        @rmdir($this->storagePath);
    }

    public function testItGeneratesTheMissingDerivativesAndFlipsTheFlag(): void
    {
        $files = new FileRepository($this->pdo);
        $fileId = $this->storeLegacyNewsImage($files);
        $variantService = new ImageVariantService($files, new \Core\Photo\ImageVariantProcessor(), $this->storagePath);
        $file = $files->findById($fileId);
        $this->assertNotNull($file);
        $this->assertNull($variantService->resolvePath($file->relativePath, 'thumb'));

        (new GenerateImageVariantsHandler())->handle([], $this->taskContext());

        $this->assertNotNull($variantService->resolvePath($file->relativePath, 'thumb'));
        $this->assertNotNull($variantService->resolvePath($file->relativePath, 'md'));

        $settings = new SettingService(new SettingRepository($this->pdo));
        $this->assertSame('1', $settings->get(GenerateImageVariantsHandler::DONE_FLAG, 'news'));
    }

    /**
     * A one-shot pass whose failure shows up months later as an article
     * image that 404s. Whether it ran, and on how many files, is the
     * whole difference between two very different diagnoses.
     */
    public function testThePassSaysWhatItDid(): void
    {
        $this->storeLegacyNewsImage(new FileRepository($this->pdo));

        (new GenerateImageVariantsHandler())->handle([], $this->taskContext());

        $entry = (new JournalRepository($this->pdo))->search()[0];
        $this->assertSame('news_image_variants_backfilled', $entry['event_type']);
        $context = json_decode((string) $entry['context'], true);
        $this->assertSame(1, $context['images']);
        $this->assertSame(count(\Core\Photo\ImageVariantService::VARIANTS), $context['generated']);
    }

    /**
     * #249. The pass walked the whole library in one go, and re-encoding
     * two derivatives of a photograph is seconds of CPU each: on a large
     * unit it could not finish inside a shared host's time limit, and a
     * run killed halfway left the flag unset and started again from the
     * top — the first images re-examined every pass, the last ones never
     * reached.
     */
    public function testALibraryLargerThanOnePassIsFinishedAcrossSeveral(): void
    {
        $files = new FileRepository($this->pdo);
        $ids = [];
        for ($i = 0; $i < 27; $i++) {
            $ids[] = $this->storeLegacyNewsImage($files);
        }

        $settings = new SettingService(new SettingRepository($this->pdo));
        (new GenerateImageVariantsHandler())->handle([], $this->taskContext());

        // Not finished, and it says so rather than claiming it is.
        $this->assertSame('0', $settings->get(GenerateImageVariantsHandler::DONE_FLAG, 'news'));
        $this->assertSame(
            1,
            (int) $this->pdo->query(
                "SELECT COUNT(*) FROM scheduled_actions
                 WHERE module_id = 'news' AND task_key = 'generate_image_variants' AND status = 'pending'"
            )->fetchColumn(),
            'la reprise ne s\'est pas réarmée',
        );

        // The next pass picks up where this one stopped, and the one
        // after that finds nothing left to do and closes the reprise.
        (new GenerateImageVariantsHandler())->handle([], $this->taskContext());
        (new GenerateImageVariantsHandler())->handle([], $this->taskContext());

        // A fresh reader: SettingService caches what it has already been
        // asked, and the handler wrote through its own instance.
        $this->assertSame(
            '1',
            (new SettingService(new SettingRepository($this->pdo)))
                ->get(GenerateImageVariantsHandler::DONE_FLAG, 'news')
        );

        $variantService = new ImageVariantService($files, new \Core\Photo\ImageVariantProcessor(), $this->storagePath);
        foreach ($ids as $id) {
            $file = $files->findById($id);
            $this->assertNotNull($variantService->resolvePath((string) $file?->relativePath, 'thumb'));
        }
    }

    public function testFilesOutsideTheNewsImagesDirectoryAreLeftAlone(): void
    {
        $files = new FileRepository($this->pdo);
        mkdir($this->storagePath . '/core/member_photos', 0777, true);
        $path = 'core/member_photos/someone.jpg';
        $this->writeJpeg($this->storagePath . '/' . $path);
        $stmt = $this->pdo->prepare(
            "INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min) VALUES (?, 'someone.jpg', 'image/jpeg', 1, 'identified')"
        );
        $stmt->execute([$path]);
        $fileId = (int) $this->pdo->lastInsertId();

        (new GenerateImageVariantsHandler())->handle([], $this->taskContext());

        $variantService = new ImageVariantService($files, new \Core\Photo\ImageVariantProcessor(), $this->storagePath);
        $file = $files->findById($fileId);
        $this->assertNotNull($file);
        $this->assertNull($variantService->resolvePath($file->relativePath, 'thumb'));

        @unlink($this->storagePath . '/' . $path);
        @rmdir($this->storagePath . '/core/member_photos');
        @rmdir($this->storagePath . '/core');
    }

    private function storeLegacyNewsImage(FileRepository $files): int
    {
        $tmp = tempnam(sys_get_temp_dir(), 'news_variant_test_') . '.jpg';
        $this->writeJpeg($tmp);

        $uploadHandler = new UploadHandler($files, $this->storagePath);
        return $uploadHandler->handle(
            ['name' => 'photo.jpg', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp), 'type' => 'image/jpeg'],
            'news/images',
            ['image/jpeg'],
            5 * 1024 * 1024,
            'public',
            'news',
            null
        );
    }

    private function writeJpeg(string $path): void
    {
        $image = imagecreatetruecolor(20, 20);
        imagejpeg($image, $path);
        imagedestroy($image);
    }

    private function taskContext(): TaskContext
    {
        return new TaskContext(
            Connection::withPdo($this->pdo),
            $this->encryption,
            $this->createMock(MailService::class),
            new JournalService(new JournalRepository($this->pdo)),
            new SettingService(new SettingRepository($this->pdo)),
            new UserAccountRepository($this->pdo, $this->encryption),
            $this->storagePath
        );
    }
}
