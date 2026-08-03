<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Photo\PwaIconProcessor;
use Core\Photo\PwaIconService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class PwaIconServiceTest extends TestCase
{
    private PwaIconService $service;
    private SettingService $settingService;
    private string $storagePath;
    private string $defaultIconPath;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($pdo));
        $this->settingService->register('pwa_background_color', '#ffffff', 'color', 'x', 'x');
        $this->settingService->register('pwa_icon_version', '1', 'number', 'x', 'x', null, null, null, false);

        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_pwa_storage_' . uniqid();
        $this->defaultIconPath = sys_get_temp_dir() . '/scoutmagic_pwa_defaults_' . uniqid();
        mkdir($this->defaultIconPath, 0755, true);

        $this->service = new PwaIconService(
            new PwaIconProcessor(),
            $this->settingService,
            $this->storagePath,
            $this->defaultIconPath
        );
    }

    protected function tearDown(): void
    {
        foreach ([$this->storagePath, $this->defaultIconPath] as $dir) {
            if (is_dir($dir)) {
                foreach (glob($dir . '/*') ?: [] as $file) {
                    unlink($file);
                }
                rmdir($dir);
            }
        }
    }

    private function writeDefault(string $filename): void
    {
        file_put_contents($this->defaultIconPath . '/' . $filename, 'shipped-default-bytes');
    }

    public function testIsValidSizeAcceptsTheFourKnownSizes(): void
    {
        $this->assertTrue($this->service->isValidSize('192'));
        $this->assertTrue($this->service->isValidSize('512'));
        $this->assertTrue($this->service->isValidSize('512-maskable'));
        $this->assertTrue($this->service->isValidSize('180'));
    }

    public function testIsValidSizeRejectsAnythingElse(): void
    {
        $this->assertFalse($this->service->isValidSize('1024'));
        $this->assertFalse($this->service->isValidSize(''));
        $this->assertFalse($this->service->isValidSize('512-maskable.png'));
    }

    public function testResolveIconContentReturnsNullForInvalidSize(): void
    {
        $this->assertNull($this->service->resolveIconContent('999'));
    }

    public function testResolveIconContentReturnsNullWhenNeitherOverrideNorDefaultExists(): void
    {
        $this->assertNull($this->service->resolveIconContent('192'));
    }

    public function testResolveIconContentFallsBackToShippedDefaultWhenNoUploadExists(): void
    {
        $this->writeDefault('icon-192.png');

        $this->assertSame('shipped-default-bytes', $this->service->resolveIconContent('192'));
    }

    public function testResolveIconContentPrefersUploadedOverrideOverShippedDefault(): void
    {
        $this->writeDefault('icon-192.png');
        mkdir($this->storagePath, 0755, true);
        file_put_contents($this->storagePath . '/icon-192.png', 'custom-uploaded-bytes');

        $this->assertSame('custom-uploaded-bytes', $this->service->resolveIconContent('192'));
    }

    public function testCurrentVersionReflectsTheRegisteredSettingDefault(): void
    {
        $this->assertSame(1, $this->service->currentVersion());
    }

    public function testStoreUploadedLogoWritesAllFourDerivativesAndIncrementsVersion(): void
    {
        $image = imagecreatetruecolor(400, 400);
        $color = imagecolorallocate($image, 200, 50, 50);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $this->service->storeUploadedLogo($bytes, 'image/png');

        $this->assertFileExists($this->storagePath . '/icon-192.png');
        $this->assertFileExists($this->storagePath . '/icon-512.png');
        $this->assertFileExists($this->storagePath . '/icon-512-maskable.png');
        $this->assertFileExists($this->storagePath . '/icon-180.png');
        $this->assertSame(2, $this->service->currentVersion());
    }

    public function testStoreUploadedLogoMakesTheUploadImmediatelyResolvable(): void
    {
        $image = imagecreatetruecolor(400, 400);
        $color = imagecolorallocate($image, 10, 20, 30);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $this->service->storeUploadedLogo($bytes, 'image/png');
        $resolved = $this->service->resolveIconContent('512');

        $this->assertNotNull($resolved);
        $decoded = imagecreatefromstring($resolved);
        $this->assertNotFalse($decoded);
        $this->assertSame(512, imagesx($decoded));
        imagedestroy($decoded);
    }
}
