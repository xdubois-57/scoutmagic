<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\AttachedFileRemover;
use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Import\AgeBranchRepository;
use Core\Photo\AccountPhotoRepository;
use Core\Photo\AccountPhotoService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Photo\ImportedMemberPhotoWriter;
use Core\Photo\LandscapeImageProcessor;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use Core\Photo\PhotoIngestionService;
use Core\Photo\SectionPhotoProcessor;
use Core\Photo\SectionPhotoRepository;
use Core\Photo\SectionPhotoService;
use Core\Photo\UnitLogoProcessor;
use Core\Photo\UnitLogoService;
use Core\View\EditableContentRepository;
use Core\View\EditableContentService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The core's door for member photos brought from another site: what it
 * refuses before anything is stored, and that it never replaces a photo.
 */
#[Group('database')]
final class ImportedMemberPhotoWriterTest extends TestCase
{
    private \PDO $pdo;
    private string $tmpDir;
    private ImportedMemberPhotoWriter $writer;
    private PhotoIngestionService $ingestion;
    private AttachedFileRemover $remover;
    private ImageVariantService $variants;
    private int $memberId;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->tmpDir = sys_get_temp_dir() . '/scoutmagic_imported_photo_' . bin2hex(random_bytes(6));
        mkdir($this->tmpDir, 0755, true);

        $files = new FileRepository($this->pdo);
        $uploadHandler = new class ($files, $this->tmpDir) extends UploadHandler {
            protected function moveFile(string $from, string $to): bool
            {
                return copy($from, $to);
            }
        };
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $settings->register('pwa_background_color', '#ffffff', 'color', 'x', 'x');
        $settings->register('pwa_icon_version', '1', 'number', 'x', 'x', null, null, null, false);
        $this->variants = new ImageVariantService($files, new ImageVariantProcessor(), $this->tmpDir);

        $this->ingestion = $ingestion = new PhotoIngestionService(
            $uploadHandler,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            new MemberPhotoService(new MemberPhotoRepository($this->pdo)),
            new SectionPhotoService(new SectionPhotoRepository($this->pdo)),
            new SectionPhotoProcessor(),
            new LandscapeImageProcessor(),
            new AgeBranchRepository($this->pdo),
            new UnitLogoService(new UnitLogoProcessor(), $settings, $this->tmpDir . '/logo', $this->tmpDir . '/logo_defaults'),
            $this->variants,
            new AccountPhotoService(new AccountPhotoRepository($this->pdo), $files, $this->tmpDir),
        );
        $this->remover = new AttachedFileRemover($files, $this->tmpDir);
        $this->writer = new ImportedMemberPhotoWriter($ingestion, new MemberPhotoRepository($this->pdo), $this->remover);

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2022-2023', '2022-09-01', '2023-08-31')");
        $this->yearId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tmpDir);
    }

    #[DataProvider('acceptedImages')]
    public function testAPhotoGdDecodesIsAccepted(string $type, string $mime): void
    {
        $this->assertNull($this->writer->rejectionReason(self::image($type), $mime));
        $this->assertNull($this->writer->rejectionReason(self::image($type)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function acceptedImages(): array
    {
        return [
            'JPEG' => ['jpg', 'image/jpeg'],
            'PNG' => ['png', 'image/png'],
            'WebP' => ['webp', 'image/webp'],
        ];
    }

    public function testWhatIsNotAMemberPhotoIsRefusedWithItsReason(): void
    {
        $this->assertSame(ImportedMemberPhotoWriter::REASON_UNREADABLE, $this->writer->rejectionReason(''));
        $this->assertSame(
            ImportedMemberPhotoWriter::REASON_TOO_HEAVY,
            $this->writer->rejectionReason(str_repeat('a', ImportedMemberPhotoWriter::MAX_BYTES + 1))
        );
        $this->assertSame(ImportedMemberPhotoWriter::REASON_NOT_ACCEPTED, $this->writer->rejectionReason('just some text'));
        $this->assertSame(ImportedMemberPhotoWriter::REASON_NOT_ACCEPTED, $this->writer->rejectionReason(self::image('gif')));
        // The real type decides, not the name it came with.
        $this->assertSame(
            ImportedMemberPhotoWriter::REASON_TYPE_MISMATCH,
            $this->writer->rejectionReason(self::image('png'), 'image/jpeg')
        );
        // A JPEG header over bytes that are not the rest of a JPEG.
        $this->assertSame(
            ImportedMemberPhotoWriter::REASON_UNREADABLE,
            $this->writer->rejectionReason("\xFF\xD8\xFF\xE0" . str_repeat("\x00", 12) . 'not the rest of a JPEG')
        );
    }

    public function testAnImageTooLargeToDecodeHereIsRefusedFromItsHeaderAlone(): void
    {
        // Headers only: nothing this big is ever decoded, by the test either.
        $this->assertSame(ImportedMemberPhotoWriter::REASON_TOO_LARGE, $this->writer->rejectionReason(self::pngHeader(5000, 4000)));
        $this->assertSame(ImportedMemberPhotoWriter::REASON_TOO_LARGE, $this->writer->rejectionReason(self::pngHeader(12000, 10)));
    }

    public function testThePhotoGoesThroughThePipelineAndIsLinkedToItsYear(): void
    {
        $actorId = $this->account();

        $fileId = $this->writer->write($this->memberId, $this->yearId, self::image('jpg'), 'dossier\\portrait 2022.jpg', $actorId);

        $this->assertNotNull($fileId);
        $photo = $this->pdo->query('SELECT file_id, created_by FROM member_photos')->fetchAll();
        $this->assertSame([['file_id' => $fileId, 'created_by' => $actorId]], array_map(
            static fn (array $row): array => ['file_id' => (int) $row['file_id'], 'created_by' => (int) $row['created_by']],
            $photo
        ));

        $query = $this->pdo->prepare('SELECT relative_path, original_name, role_min FROM files WHERE id = ?');
        $query->execute([$fileId]);
        $file = $query->fetch();
        $this->assertSame('portrait 2022.jpg', $file['original_name']);
        $this->assertSame('identified', $file['role_min']);
        $this->assertStringStartsWith('core/member_photos/', $file['relative_path']);
        $this->assertNotNull($this->variants->resolvePath((string) $file['relative_path'], 'thumb'), 'No thumbnail.');
    }

    public function testAPhotoAlreadyThereIsNeverReplaced(): void
    {
        $first = $this->writer->write($this->memberId, $this->yearId, self::image('jpg'), 'a.jpg', null);
        $filesBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();

        $this->assertTrue($this->writer->hasPhoto($this->memberId, $this->yearId));
        $this->assertNull($this->writer->write($this->memberId, $this->yearId, self::image('png'), 'b.png', null));

        $this->assertSame($first, (int) $this->pdo->query('SELECT file_id FROM member_photos')->fetchColumn());
        $this->assertSame($filesBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(), 'A file was stored for nothing.');
    }

    public function testNoOtherKindOfPhotoIsTouched(): void
    {
        $this->writer->write($this->memberId, $this->yearId, self::image('jpg'), 'a.jpg', $this->account());

        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM user_account_photos')->fetchColumn());
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM section_staff_photos')->fetchColumn());
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM member_photos')->fetchColumn());
    }

    public function testTheTemporaryCopyIsRemoved(): void
    {
        $before = glob(sys_get_temp_dir() . '/member_photo_import_*') ?: [];

        $this->writer->write($this->memberId, $this->yearId, self::image('jpg'), 'a.jpg', null);

        $this->assertSame($before, glob(sys_get_temp_dir() . '/member_photo_import_*') ?: []);
    }

    /**
     * Issue #1158: another path gives the member that year's photo between
     * the inspection of the row and its write. The photo is stored first, and
     * only then does the insert find the slot taken — so the image and its
     * thumbnail are already on disk when the row is refused. They must not
     * stay there: no row points at them, no screen shows them, nothing would
     * ever delete them, and a run of such refusals eats the disk budget.
     */
    public function testALostRaceLeavesNeitherTheImageNorItsThumbnailOnDisk(): void
    {
        // The slot is taken by this photo, stored for another year, a moment
        // before the writer's own insert.
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2021-2022', '2021-09-01', '2022-08-31')");
        $otherYearId = (int) $this->pdo->lastInsertId();
        $taker = $this->writer->write($this->memberId, $otherYearId, self::image('png'), 'autre.png', null);
        $this->assertNotNull($taker);

        $racing = new class ($this->pdo, $taker) extends MemberPhotoRepository {
            public function __construct(private \PDO $handle, private int $takerFileId)
            {
                parent::__construct($handle);
            }

            public function insertIfAbsent(int $memberId, int $scoutYearId, int $fileId, ?int $createdBy): bool
            {
                $this->handle->prepare(
                    'INSERT INTO member_photos (member_id, scout_year_id, file_id, created_by) VALUES (?, ?, ?, NULL)'
                )->execute([$memberId, $scoutYearId, $this->takerFileId]);

                return parent::insertIfAbsent($memberId, $scoutYearId, $fileId, $createdBy);
            }
        };
        $writer = new ImportedMemberPhotoWriter($this->ingestion, $racing, $this->remover);

        $filesOnDiskBefore = self::filesUnder($this->tmpDir);
        $filesRowsBefore = (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn();

        $this->assertNull($writer->write($this->memberId, $this->yearId, self::image('jpg'), 'perdue.jpg', null));

        $this->assertSame($filesOnDiskBefore, self::filesUnder($this->tmpDir), 'The image or its thumbnail stayed on disk.');
        $this->assertSame($filesRowsBefore, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn(), 'A files row was left behind.');
        // The photo that won the slot is untouched.
        $this->assertSame($taker, (int) $this->pdo->query(
            'SELECT file_id FROM member_photos WHERE scout_year_id = ' . $this->yearId
        )->fetchColumn());
        $this->assertNotSame([], $filesOnDiskBefore, 'The winning photo\'s own files are gone: the comparison above proves nothing.');
    }

    public function testAWonSlotKeepsItsFiles(): void
    {
        $fileId = $this->writer->write($this->memberId, $this->yearId, self::image('jpg'), 'a.jpg', null);

        $this->assertNotNull($fileId);
        $this->assertNotSame([], self::filesUnder($this->tmpDir));
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM files')->fetchColumn());
    }

    /**
     * Every file below a directory, as sorted relative paths.
     *
     * @return list<string>
     */
    private static function filesUnder(string $directory): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $found[] = substr($file->getPathname(), strlen($directory) + 1);
            }
        }
        sort($found);

        return $found;
    }

    private function account(): int
    {
        $this->pdo->exec("INSERT INTO user_accounts (email_encrypted, email_blind_index) VALUES ('x', 'importer')");

        return (int) $this->pdo->lastInsertId();
    }

    private static function image(string $type): string
    {
        $image = imagecreatetruecolor(64, 80);
        imagefill($image, 0, 0, imagecolorallocate($image, 46, 94, 140));
        imagefilledellipse($image, 32, 32, 30, 30, imagecolorallocate($image, 235, 242, 250));
        ob_start();
        match ($type) {
            'jpg' => imagejpeg($image),
            'png' => imagepng($image),
            'webp' => imagewebp($image),
            'gif' => imagegif($image),
        };
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /** A PNG signature and IHDR announcing these dimensions, and no pixels. */
    private static function pngHeader(int $width, int $height): string
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', $width, $height, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1a\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }
        @rmdir($directory);
    }
}
