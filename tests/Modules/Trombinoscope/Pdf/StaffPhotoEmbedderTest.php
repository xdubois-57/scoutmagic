<?php

declare(strict_types=1);

namespace Tests\Modules\Trombinoscope\Pdf;

use Core\File\FileRecord;
use Core\File\FileRepository;
use Core\Photo\ImageVariantService;
use Core\Photo\MemberPhotoService;
use Modules\Trombinoscope\Pdf\StaffPhotoEmbedder;
use PHPUnit\Framework\TestCase;

/**
 * The bridge between the site's photo pipeline and dompdf, which cannot
 * fetch a URL and cannot decode WebP.
 */
class StaffPhotoEmbedderTest extends TestCase
{
    private string $storagePath;

    /** @var array<int, ?int> member id => file id */
    private array $photos = [];

    /** @var array<int, ?FileRecord> */
    private array $files = [];

    /** Relative path => absolute path of a `thumb` derivative on disk. */
    private array $thumbs = [];

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/tromb_photos_' . uniqid();
        mkdir($this->storagePath . '/photos', 0777, true);
        $this->photos = [];
        $this->files = [];
        $this->thumbs = [];
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/photos/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->storagePath . '/photos');
        @rmdir($this->storagePath);
    }

    private function embedder(): StaffPhotoEmbedder
    {
        $photos = $this->photos;
        $files = $this->files;
        $thumbs = $this->thumbs;

        $memberPhotos = new class($photos) extends MemberPhotoService {
            /** @param array<int, ?int> $photos */
            public function __construct(private array $photos)
            {
            }

            public function resolveFileId(int $memberId, int $scoutYearId): ?int
            {
                return $this->photos[$memberId] ?? null;
            }
        };

        $fileRepository = new class($files) extends FileRepository {
            /** @param array<int, ?FileRecord> $files */
            public function __construct(private array $files)
            {
            }

            public function findById(int $id): ?FileRecord
            {
                return $this->files[$id] ?? null;
            }
        };

        $variants = new class($thumbs) extends ImageVariantService {
            /** @param array<string, string> $thumbs */
            public function __construct(private array $thumbs)
            {
            }

            public function resolvePath(string $relativePath, string $variant): ?string
            {
                return $variant === 'thumb' ? ($this->thumbs[$relativePath] ?? null) : null;
            }
        };

        return new StaffPhotoEmbedder($memberPhotos, $fileRepository, $variants, $this->storagePath);
    }

    private function record(int $id, string $relativePath, string $mime = 'image/webp', bool $encrypted = false): FileRecord
    {
        return new FileRecord($id, $relativePath, 'photo', $mime, 1000, 'identified', null, $encrypted);
    }

    /** Writes a real image and returns its absolute path. */
    private function writeImage(string $name, string $format): string
    {
        $image = imagecreatetruecolor(192, 192);
        imagefilledrectangle($image, 0, 0, 191, 191, (int) imagecolorallocate($image, 20, 120, 80));
        $path = $this->storagePath . '/photos/' . $name;
        if ($format === 'webp') {
            imagewebp($image, $path);
        } else {
            imagejpeg($image, $path);
        }
        imagedestroy($image);

        return $path;
    }

    public function testAWebpThumbnailComesBackAsAnEmbeddableJpeg(): void
    {
        // dompdf cannot decode WebP, which is exactly what
        // Core\Photo\ImageVariantService generates at upload.
        $this->photos[100] = 7;
        $this->files[7] = $this->record(7, 'photos/original.jpg');
        $this->thumbs['photos/original.jpg'] = $this->writeImage('thumb.webp', 'webp');

        $uri = $this->embedder()->dataUriFor(100, 1);

        $this->assertNotNull($uri);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $uri);

        $bytes = base64_decode(substr($uri, strlen('data:image/jpeg;base64,')), true);
        $this->assertNotFalse($bytes);
        $info = getimagesizefromstring($bytes);
        $this->assertNotFalse($info);
        $this->assertSame(IMAGETYPE_JPEG, $info[2]);
        $this->assertSame(150, $info[0]);
        $this->assertSame(150, $info[1]);
    }

    public function testItFallsBackToTheOriginalWhenNoDerivativeExists(): void
    {
        // ImageVariantService never generates on demand, so a photo
        // uploaded before that pipeline existed simply has no thumb.
        $this->photos[100] = 7;
        $this->files[7] = $this->record(7, 'photos/original.jpg', 'image/jpeg');
        $this->writeImage('original.jpg', 'jpeg');

        $this->assertStringStartsWith('data:image/jpeg;base64,', (string) $this->embedder()->dataUriFor(100, 1));
    }

    public function testTheEmbeddedPhotoIsFarSmallerThanWhatItCameFrom(): void
    {
        $this->photos[100] = 7;
        $this->files[7] = $this->record(7, 'photos/original.jpg', 'image/jpeg');
        $original = $this->writeImage('original.jpg', 'jpeg');

        $uri = (string) $this->embedder()->dataUriFor(100, 1);

        $this->assertLessThan(filesize($original) * 2, strlen($uri));
        $this->assertLessThan(30000, strlen($uri));
    }

    public function testAMemberWithNoPhotoGetsNothingAtAll(): void
    {
        // The document then draws the same initials avatar as the screen.
        $this->assertNull($this->embedder()->dataUriFor(404, 1));
    }

    public function testAStaleFileRowNeverBreaksTheDocument(): void
    {
        $this->photos[100] = 7;
        $this->files[7] = $this->record(7, 'photos/gone.jpg', 'image/jpeg');

        $this->assertNull($this->embedder()->dataUriFor(100, 1));
    }

    public function testAnEncryptedFileIsRefusedRatherThanDecrypted(): void
    {
        // No core photo context is ever encrypted; refusing keeps this
        // class out of the master key's path entirely.
        $this->photos[100] = 7;
        $this->files[7] = $this->record(7, 'photos/original.jpg', 'image/jpeg', true);
        $this->writeImage('original.jpg', 'jpeg');

        $this->assertNull($this->embedder()->dataUriFor(100, 1));
    }

    public function testSomethingThatIsNotAnImageIsRefused(): void
    {
        $this->photos[100] = 7;
        $this->files[7] = $this->record(7, 'photos/broken.jpg', 'image/jpeg');
        file_put_contents($this->storagePath . '/photos/broken.jpg', 'not an image');

        $this->assertNull($this->embedder()->dataUriFor(100, 1));
    }

    public function testAPortraitIsDecodedOnceEvenWhenDrawnTwice(): void
    {
        // A responsable appears on the directory page AND on their own
        // section's page.
        $this->photos[100] = 7;
        $this->files[7] = $this->record(7, 'photos/original.jpg', 'image/jpeg');
        $path = $this->writeImage('original.jpg', 'jpeg');

        $embedder = $this->embedder();
        $first = $embedder->dataUriFor(100, 1);
        unlink($path);
        $second = $embedder->dataUriFor(100, 1);

        $this->assertSame($first, $second);
    }
}
