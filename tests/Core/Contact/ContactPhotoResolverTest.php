<?php

declare(strict_types=1);

namespace Tests\Core\Contact;

use Core\Contact\ContactPhotoResolver;
use Core\File\FileRepository;
use Core\Photo\ImageVariantProcessor;
use Core\Photo\ImageVariantService;
use Core\Photo\MemberPhotoRepository;
use Core\Photo\MemberPhotoService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The portrait a vCard's PHOTO property carries — and the four ways it
 * comes back as nothing instead, none of which may break an export.
 *
 * @group database
 */
#[\PHPUnit\Framework\Attributes\Group('database')]
class ContactPhotoResolverTest extends TestCase
{
    private \PDO $pdo;
    private ContactPhotoResolver $resolver;
    private MemberPhotoService $photoService;
    private string $storagePath;
    private int $memberId;
    private int $yearId;

    protected function setUp(): void
    {
        $this->pdo = DatabaseTestHelper::createTestDatabase();
        $this->storagePath = sys_get_temp_dir() . '/contact_photo_' . bin2hex(random_bytes(6));
        mkdir($this->storagePath . '/core/member_photos', 0755, true);

        $fileRepository = new FileRepository($this->pdo);
        $this->photoService = new MemberPhotoService(new MemberPhotoRepository($this->pdo));
        $this->resolver = new ContactPhotoResolver(
            $this->photoService,
            $fileRepository,
            new ImageVariantService($fileRepository, new ImageVariantProcessor(), $this->storagePath),
            $this->storagePath
        );

        $stmt = $this->pdo->prepare('INSERT INTO scout_years (label, start_date, end_date) VALUES (?, ?, ?)');
        $stmt->execute(DatabaseTestHelper::scoutYear());
        $this->yearId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec("INSERT INTO members (desk_id) VALUES ('D-1')");
        $this->memberId = (int) $this->pdo->lastInsertId();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->storagePath . '/core/member_photos/*') ?: [] as $file) {
            unlink($file);
        }
    }

    private function imageBytes(int $width, int $height, string $format = 'jpeg'): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefilledrectangle($image, 0, 0, $width, $height, imagecolorallocate($image, 10, 120, 200));
        ob_start();
        $format === 'webp' ? imagewebp($image) : imagejpeg($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        return $bytes;
    }

    /**
     * Registers a `files` row and its bytes, then points the member's
     * portrait at it.
     */
    private function givePhoto(string $relativePath, string $bytes, bool $encrypted = false, bool $writeFile = true): int
    {
        if ($writeFile) {
            file_put_contents($this->storagePath . '/' . $relativePath, $bytes);
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO files (relative_path, original_name, mime_type, size_bytes, role_min, encrypted)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$relativePath, 'portrait.jpg', 'image/jpeg', strlen($bytes), 'identified', $encrypted ? 1 : 0]);
        $fileId = (int) $this->pdo->lastInsertId();

        $this->photoService->setPhoto($this->memberId, $this->yearId, $fileId, null);

        return $fileId;
    }

    public function testAPortraitComesBackAsASmallSquareJpeg(): void
    {
        $this->givePhoto('core/member_photos/a.jpg', $this->imageBytes(600, 400));

        $jpeg = $this->resolver->jpegFor($this->memberId, $this->yearId);

        $this->assertNotNull($jpeg);
        $info = getimagesizefromstring($jpeg);
        $this->assertNotFalse($info);
        $this->assertSame('image/jpeg', $info['mime']);
        $this->assertSame($info[0], $info[1], 'The portrait is square.');
        $this->assertLessThan(strlen($this->imageBytes(600, 400)) * 4, strlen($jpeg));
    }

    /**
     * The `thumb` derivative is already cropped and already small, so it
     * is preferred; the original is only a fallback for a photo uploaded
     * before that pipeline existed.
     */
    public function testTheThumbDerivativeIsPreferredOverTheOriginal(): void
    {
        $this->givePhoto('core/member_photos/b.jpg', $this->imageBytes(600, 400));
        // A derivative whose colour differs from the original, so the
        // answer says which one was read.
        $thumb = imagecreatetruecolor(192, 192);
        imagefilledrectangle($thumb, 0, 0, 192, 192, imagecolorallocate($thumb, 250, 0, 0));
        imagewebp($thumb, $this->storagePath . '/core/member_photos/b.thumb.webp');
        imagedestroy($thumb);

        $jpeg = $this->resolver->jpegFor($this->memberId, $this->yearId);
        $this->assertNotNull($jpeg);

        $decoded = imagecreatefromstring($jpeg);
        $this->assertNotFalse($decoded);
        $colour = imagecolorsforindex($decoded, imagecolorat($decoded, 96, 96));
        imagedestroy($decoded);
        $this->assertGreaterThan(200, $colour['red'], 'The thumb derivative is what was read.');
    }

    public function testAMemberWithNoPhotoAtAllGetsNothing(): void
    {
        $this->assertNull($this->resolver->jpegFor($this->memberId, $this->yearId));
    }

    /**
     * No core photo context is ever encrypted; refusing one rather than
     * reaching for the master key keeps this class out of that path.
     */
    public function testAnEncryptedFileIsRefusedRatherThanDecrypted(): void
    {
        $this->givePhoto('core/member_photos/c.jpg', $this->imageBytes(200, 200), encrypted: true);

        $this->assertNull($this->resolver->jpegFor($this->memberId, $this->yearId));
    }

    public function testAFileRowWhoseBytesAreGoneGetsNothingRatherThanAnError(): void
    {
        $this->givePhoto('core/member_photos/d.jpg', $this->imageBytes(200, 200), writeFile: false);

        $this->assertNull($this->resolver->jpegFor($this->memberId, $this->yearId));
    }

    public function testBytesThatAreNotAnImageGetNothing(): void
    {
        $this->givePhoto('core/member_photos/e.jpg', 'not an image at all');

        $this->assertNull($this->resolver->jpegFor($this->memberId, $this->yearId));
    }

    /**
     * An address book of thirty staff resolves every portrait's file id in
     * one query rather than thirty lookups — `prime()` is what IT-03 will
     * call before asking member by member.
     */
    public function testPrimingResolvesEveryPortraitUpFront(): void
    {
        $this->givePhoto('core/member_photos/f.jpg', $this->imageBytes(200, 200));

        $this->resolver->prime([$this->memberId], $this->yearId);

        $this->assertNotNull($this->resolver->jpegFor($this->memberId, $this->yearId));
        // Priming a member who has no portrait is a no-op, never an error.
        $this->resolver->prime([$this->memberId, 999999], $this->yearId);
        $this->assertNull($this->resolver->jpegFor(999999, $this->yearId));
    }
}
