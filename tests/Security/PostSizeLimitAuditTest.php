<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * Audit M2 — public/.user.ini is DOCUMENT-ROOT-WIDE (per-directory limits,
 * every route runs through public/index.php), and PHP buffers a urlencoded
 * POST body into memory before application code runs. A large global
 * post_max_size is therefore an anonymous memory-DoS budget on /login and
 * every other endpoint. Large files (gallery media, backup-restore) go up
 * as ~8 MB chunks instead (Core\File\ChunkedUploadStore); this test pins
 * the global ceiling so it can't quietly creep back up.
 */
class PostSizeLimitAuditTest extends TestCase
{
    private const MAX_POST_MAX_SIZE_MB = 48;
    private const MAX_UPLOAD_MAX_FILESIZE_MB = 32;

    /** @return array<string, string> */
    private function userIni(): array
    {
        $path = dirname(__DIR__, 2) . '/public/.user.ini';
        $this->assertFileExists($path);
        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);
        $this->assertIsArray($parsed);

        return $parsed;
    }

    private static function toBytes(string $iniValue): int
    {
        $value = trim($iniValue);
        $unit = strtoupper(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'G' => $number * 1024 * 1024 * 1024,
            'M' => $number * 1024 * 1024,
            'K' => $number * 1024,
            default => $number,
        };
    }

    public function testPostMaxSizeStaysSmall(): void
    {
        $ini = $this->userIni();
        $this->assertArrayHasKey('post_max_size', $ini);
        $this->assertLessThanOrEqual(
            self::MAX_POST_MAX_SIZE_MB * 1024 * 1024,
            self::toBytes((string) $ini['post_max_size']),
            'post_max_size in public/.user.ini applies to EVERY route (login included) and is buffered '
            . 'into memory before app code runs — large uploads must use chunking, not a bigger global limit.'
        );
    }

    public function testUploadMaxFilesizeStaysSmall(): void
    {
        $ini = $this->userIni();
        $this->assertArrayHasKey('upload_max_filesize', $ini);
        $this->assertLessThanOrEqual(
            self::MAX_UPLOAD_MAX_FILESIZE_MB * 1024 * 1024,
            self::toBytes((string) $ini['upload_max_filesize']),
            'upload_max_filesize in public/.user.ini is document-root-wide — large uploads must use '
            . 'chunking (assets/js/chunked-upload.js + Core\File\ChunkedUploadStore), not a bigger global limit.'
        );
    }

    public function testUploadMaxFilesizeStillCoversTheLargestSinglePostConsumer(): void
    {
        // Section documents allow up to 20M in one classic POST — the global
        // limit must not be lowered below that without also chunking them.
        $ini = $this->userIni();
        $this->assertGreaterThanOrEqual(20 * 1024 * 1024, self::toBytes((string) $ini['upload_max_filesize']));
        $this->assertGreaterThan(
            self::toBytes((string) $ini['upload_max_filesize']),
            self::toBytes((string) $ini['post_max_size']),
            'post_max_size must exceed upload_max_filesize (multipart overhead + other form fields).'
        );
    }
}
