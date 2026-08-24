<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\File\FileRepository;
use Core\File\UploadHandler;
use Core\Import\AgeBranchRepository;
use Core\Photo\AccountPhotoRepository;
use Core\Photo\AccountPhotoService;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
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
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The image-upload pipeline, driven without a request.
 *
 * Tests\Core\Http\Controller\UploadControllerTest still covers the web
 * boundary — the CSRF token, the authorization, the flash, the redirect — and
 * drives the real service behind it. This file covers what the extraction was
 * FOR: the same pipeline called with no session, no CSRF token and no
 * `$_FILES`, which is how the reference dataset's builder puts photos on
 * members and sections.
 *
 * The interesting assertion is the null actor. Every collaborator the service
 * calls already accepts a nullable author; it was the controller that could
 * not imagine one, because on the web there is always somebody logged in.
 */
#[Group('database')]
final class PhotoIngestionServiceTest extends TestCase
{
    private \PDO $pdo;
    private string $tmpDir;
    private PhotoIngestionService $service;
    private ImageVariantService $imageVariantService;
    private MemberPhotoService $memberPhotoService;
    private SectionPhotoService $sectionPhotoService;
    private int $memberId;
    private int $sectionId;
    private int $scoutYearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->tmpDir = sys_get_temp_dir() . '/scoutmagic_ingestion_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        $fileRepository = new FileRepository($this->pdo);

        // The real UploadHandler, with move_uploaded_file() swapped for a
        // copy: nothing here came through a real HTTP upload, which is the
        // whole point.
        $uploadHandler = new class ($fileRepository, $this->tmpDir) extends UploadHandler {
            protected function moveFile(string $from, string $to): bool
            {
                return copy($from, $to);
            }
        };

        $settingService = new \Core\Config\SettingService(new \Core\Config\SettingRepository($this->pdo));
        $settingService->register('pwa_background_color', '#ffffff', 'color', 'x', 'x');
        $settingService->register('pwa_icon_version', '1', 'number', 'x', 'x', null, null, null, false);

        $this->memberPhotoService = new MemberPhotoService(new MemberPhotoRepository($this->pdo));
        $this->sectionPhotoService = new SectionPhotoService(new SectionPhotoRepository($this->pdo));
        $this->imageVariantService = new ImageVariantService($fileRepository, new ImageVariantProcessor(), $this->tmpDir);

        $this->service = new PhotoIngestionService(
            $uploadHandler,
            new EditableContentService(new EditableContentRepository($this->pdo)),
            $this->memberPhotoService,
            $this->sectionPhotoService,
            new SectionPhotoProcessor(),
            new LandscapeImageProcessor(),
            new AgeBranchRepository($this->pdo),
            new UnitLogoService(
                new UnitLogoProcessor(),
                $settingService,
                $this->tmpDir . '/logo',
                $this->tmpDir . '/logo_defaults',
            ),
            $this->imageVariantService,
            new AccountPhotoService(new AccountPhotoRepository($this->pdo), $fileRepository, $this->tmpDir),
        );

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('DESK1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO age_branches (desk_code, label, sort_order) VALUES ('BR1', 'Louveteaux', 20)");
        $branchId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO sections (desk_code, age_branch_id, name) VALUES ('LOU01', {$branchId}, 'Louveteaux')");
        $this->sectionId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec("INSERT INTO scout_years (label, start_date, end_date) VALUES ('2025-2026', '2025-09-01', '2026-08-31')");
        $this->scoutYearId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        self::removeDirectory($this->tmpDir);
    }

    public function testAMemberPhotoIsLinkedWithNoActorAtAll(): void
    {
        $result = $this->service->ingest(
            $this->uploadedFile(900, 1200),
            PhotoIngestionService::CONTEXT_MEMBER_PHOTO,
            "{$this->memberId}:{$this->scoutYearId}",
            null,
        );

        self::assertNotNull($result->fileId);
        self::assertTrue($result->linked, 'Une photo de membre doit être rattachée même sans compte auteur.');
        self::assertSame(
            $result->fileId,
            $this->memberPhotoService->resolveFileId($this->memberId, $this->scoutYearId),
        );
        self::assertSame(
            ['member_id' => $this->memberId, 'scout_year_id' => $this->scoutYearId],
            $result->journalContext,
        );
    }

    public function testASectionPhotoIsCroppedToFourThirdsBeforeBeingStored(): void
    {
        // The crop happens BEFORE the file is stored, so what lands on disk —
        // and the size recorded in the files table — is already the final
        // rendition. A 3:2 source loses its sides.
        $result = $this->service->ingest(
            $this->uploadedFile(1500, 1000),
            PhotoIngestionService::CONTEXT_SECTION_PHOTO,
            "{$this->sectionId}:{$this->scoutYearId}",
            null,
        );

        self::assertTrue($result->linked);
        self::assertSame($result->fileId, $this->sectionPhotoService->resolveFileId($this->sectionId, $this->scoutYearId));

        $stored = $this->storedPathOf((int) $result->fileId);
        $size = getimagesize($stored);
        self::assertNotFalse($size);
        self::assertEqualsWithDelta(4 / 3, $size[0] / $size[1], 0.01, 'La photo de groupe n\'a pas été recadrée en 4:3.');
    }

    public function testAStoredPhotoCarriesTheRoleFloorItsContextRequires(): void
    {
        // member_photo is somebody's face and is gated to identified;
        // section_photo is rendered on the public Contact and Sections pages,
        // so a stricter floor would make FileAccessGuard deny it to exactly
        // the visitors those pages assume can see it.
        $member = $this->service->ingest($this->uploadedFile(600, 600), PhotoIngestionService::CONTEXT_MEMBER_PHOTO, "{$this->memberId}:{$this->scoutYearId}", null);
        $section = $this->service->ingest($this->uploadedFile(600, 600), PhotoIngestionService::CONTEXT_SECTION_PHOTO, "{$this->sectionId}:{$this->scoutYearId}", null);

        self::assertSame('identified', $this->roleMinOf((int) $member->fileId));
        self::assertSame('public', $this->roleMinOf((int) $section->fileId));
    }

    public function testEachContextGetsExactlyOneDerivative(): void
    {
        $member = $this->service->ingest($this->uploadedFile(800, 800), PhotoIngestionService::CONTEXT_MEMBER_PHOTO, "{$this->memberId}:{$this->scoutYearId}", null);
        $section = $this->service->ingest($this->uploadedFile(800, 600), PhotoIngestionService::CONTEXT_SECTION_PHOTO, "{$this->sectionId}:{$this->scoutYearId}", null);

        // Resolved through the service that reads derivatives in production,
        // rather than by rebuilding the path here: the naming convention
        // (always .webp, whatever the original extension) belongs to it.
        self::assertNotNull($this->variantPathOf((int) $member->fileId, 'thumb'), 'La photo de membre n\'a pas de vignette.');
        self::assertNotNull($this->variantPathOf((int) $section->fileId, 'md'), 'La photo de groupe n\'a pas de dérivé « md ».');

        // And nothing generates the variant the other context uses.
        self::assertNull($this->variantPathOf((int) $member->fileId, 'md'));
        self::assertNull($this->variantPathOf((int) $section->fileId, 'thumb'));
    }

    public function testAMalformedKeyStoresTheFileAndLinksNothing(): void
    {
        // Not an error: the bytes are kept, and the caller is simply told
        // nothing was pointed at them. This is what the controller has always
        // done with a key it could not parse.
        $result = $this->service->ingest($this->uploadedFile(400, 400), PhotoIngestionService::CONTEXT_MEMBER_PHOTO, 'pas-une-cle', null);

        self::assertNotNull($result->fileId);
        self::assertFalse($result->linked);
        self::assertSame([], $result->journalContext);
    }

    public function testAnAccountPhotoRefusesAnActorWhoIsNotTheAccount(): void
    {
        // Nobody sets somebody else's face, configuration mode included. The
        // caller's own authorization enforces this too; this is the second
        // lock, and it is why this one context refuses a null actor outright.
        $other = $this->service->ingest($this->uploadedFile(400, 400), PhotoIngestionService::CONTEXT_ACCOUNT_PHOTO, '42', 7);
        $none = $this->service->ingest($this->uploadedFile(400, 400), PhotoIngestionService::CONTEXT_ACCOUNT_PHOTO, '42', null);

        self::assertFalse($other->linked);
        self::assertFalse($none->linked);
    }

    public function testTheUnitLogoNeverBecomesAFilesRow(): void
    {
        // Every /files/{id} download is fetched with no session, so the unit
        // logo — which the favicon, the installed-app icons and the footer all
        // read — deliberately never becomes one.
        $before = (int) $this->pdo->query('SELECT COUNT(*) AS n FROM files')?->fetch()['n'];

        $result = $this->service->ingest($this->uploadedFile(512, 512), PhotoIngestionService::CONTEXT_UNIT_LOGO, '', null);

        self::assertNull($result->fileId);
        self::assertTrue($result->linked);
        self::assertSame($before, (int) $this->pdo->query('SELECT COUNT(*) AS n FROM files')?->fetch()['n']);
    }

    // ----------------------------------------------------------------- outils

    /**
     * A `$_FILES`-shaped entry built from a generated PNG — the exact shape a
     * command-line caller has to produce.
     *
     * @return array<string, mixed>
     */
    private function uploadedFile(int $width, int $height): array
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($image, 40, 90, 160));

        $path = $this->tmpDir . '/source_' . uniqid() . '.png';
        imagepng($image, $path);
        imagedestroy($image);

        return [
            'name' => basename($path),
            'type' => 'image/png',
            'tmp_name' => $path,
            'error' => UPLOAD_ERR_OK,
            'size' => (int) filesize($path),
        ];
    }

    private function storedPathOf(int $fileId): string
    {
        $row = $this->pdo->query('SELECT relative_path FROM files WHERE id = ' . $fileId)?->fetch();
        self::assertNotFalse($row);

        return $this->tmpDir . '/' . (string) $row['relative_path'];
    }

    private function variantPathOf(int $fileId, string $variant): ?string
    {
        $row = $this->pdo->query('SELECT relative_path FROM files WHERE id = ' . $fileId)?->fetch();
        self::assertNotFalse($row);

        return $this->imageVariantService->resolvePath((string) $row['relative_path'], $variant);
    }

    private function roleMinOf(int $fileId): string
    {
        $row = $this->pdo->query('SELECT role_min FROM files WHERE id = ' . $fileId)?->fetch();
        self::assertNotFalse($row);

        return (string) $row['role_min'];
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

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
