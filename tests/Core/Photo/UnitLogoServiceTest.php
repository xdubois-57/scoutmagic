<?php

declare(strict_types=1);

namespace Tests\Core\Photo;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Photo\UnitLogoProcessor;
use Core\Photo\UnitLogoService;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

class UnitLogoServiceTest extends TestCase
{
    private UnitLogoService $service;
    private SettingService $settingService;
    private string $storagePath;
    private string $defaultIconPath;

    protected function setUp(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $this->settingService = new SettingService(new SettingRepository($pdo));
        $this->settingService->register('pwa_background_color', '#ffffff', 'color', 'x', 'x');
        $this->settingService->register('pwa_icon_version', '1', 'number', 'x', 'x', null, null, null, false);

        $this->storagePath = sys_get_temp_dir() . '/scoutmagic_logo_storage_' . uniqid();
        $this->defaultIconPath = sys_get_temp_dir() . '/scoutmagic_logo_defaults_' . uniqid();
        mkdir($this->defaultIconPath, 0755, true);

        $this->service = new UnitLogoService(
            new UnitLogoProcessor(),
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

    private function solidPngBytes(int $side, int $r, int $g, int $b): string
    {
        $image = imagecreatetruecolor($side, $side);
        $color = imagecolorallocate($image, $r, $g, $b);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        return $bytes;
    }

    public function testIsValidSizeAcceptsTheEightKnownSizes(): void
    {
        foreach (['16', '32', '48', '64', '180', '192', '512', '512-maskable'] as $size) {
            $this->assertTrue($this->service->isValidSize($size), "expected {$size} to be valid");
        }
    }

    public function testIsValidSizeRejectsAnythingElse(): void
    {
        $this->assertFalse($this->service->isValidSize('1024'));
        $this->assertFalse($this->service->isValidSize(''));
        $this->assertFalse($this->service->isValidSize('ico'));
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

    public function testResolveFaviconIcoContentFallsBackToShippedDefault(): void
    {
        $this->writeDefault('favicon.ico');

        $this->assertSame('shipped-default-bytes', $this->service->resolveFaviconIcoContent());
    }

    public function testResolveFaviconIcoContentPrefersUploadedOverride(): void
    {
        $this->writeDefault('favicon.ico');
        mkdir($this->storagePath, 0755, true);
        file_put_contents($this->storagePath . '/favicon.ico', 'custom-ico-bytes');

        $this->assertSame('custom-ico-bytes', $this->service->resolveFaviconIcoContent());
    }

    public function testCurrentVersionReflectsTheRegisteredSettingDefault(): void
    {
        $this->assertSame(1, $this->service->currentVersion());
    }

    public function testHasCustomLogoIsFalseBeforeAnyUpload(): void
    {
        $this->assertFalse($this->service->hasCustomLogo());
    }

    public function testStoreUploadedLogoWritesEveryDerivativePlusIcoAndIncrementsVersion(): void
    {
        $bytes = $this->solidPngBytes(400, 200, 50, 50);

        $this->service->storeUploadedLogo($bytes, 'image/png');

        foreach (['favicon-16.png', 'favicon-32.png', 'favicon-48.png', 'logo-64.png', 'icon-180.png', 'icon-192.png', 'icon-512.png', 'icon-512-maskable.png', 'favicon.ico'] as $filename) {
            $this->assertFileExists($this->storagePath . '/' . $filename, "expected {$filename} to be written");
        }
        $this->assertSame(2, $this->service->currentVersion());
    }

    public function testStoreUploadedLogoRetainsTheOriginalSourceFile(): void
    {
        $bytes = $this->solidPngBytes(400, 10, 20, 30);

        $this->service->storeUploadedLogo($bytes, 'image/png');

        $this->assertFileExists($this->storagePath . '/source.png');
        $this->assertSame($bytes, file_get_contents($this->storagePath . '/source.png'));
        $this->assertTrue($this->service->hasCustomLogo());
    }

    public function testStoreUploadedLogoReplacesAnyPreviousSourceRatherThanAccumulating(): void
    {
        $this->service->storeUploadedLogo($this->solidPngBytes(400, 1, 2, 3), 'image/jpeg');
        $this->service->storeUploadedLogo($this->solidPngBytes(400, 4, 5, 6), 'image/png');

        $this->assertFileDoesNotExist($this->storagePath . '/source.jpg');
        $this->assertFileExists($this->storagePath . '/source.png');
    }

    public function testStoreUploadedLogoMakesTheUploadImmediatelyResolvable(): void
    {
        $bytes = $this->solidPngBytes(400, 10, 20, 30);

        $this->service->storeUploadedLogo($bytes, 'image/png');
        $resolved = $this->service->resolveIconContent('512');

        $this->assertNotNull($resolved);
        $decoded = imagecreatefromstring($resolved);
        $this->assertNotFalse($decoded);
        $this->assertSame(512, imagesx($decoded));
        imagedestroy($decoded);
    }

    public function testRederiveFromSourceIsANoOpWhenNoCustomLogoWasEverUploaded(): void
    {
        $this->assertFalse($this->service->rederiveFromSource());
        $this->assertSame(1, $this->service->currentVersion(), 'version must not bump on a no-op');
    }

    /**
     * The whole point of retaining the source: pwa_background_color
     * changing must re-bake the maskable icon (and icon-180) onto the NEW
     * color without asking the superadmin to re-upload anything.
     */
    public function testRederiveFromSourceAppliesTheCurrentBackgroundColorWithoutReupload(): void
    {
        $this->service->storeUploadedLogo($this->solidPngBytes(400, 255, 0, 0), 'image/png');
        $versionAfterUpload = $this->service->currentVersion();

        $this->settingService->set('pwa_background_color', '#00ff00');
        $rederived = $this->service->rederiveFromSource();

        $this->assertTrue($rederived);
        $this->assertGreaterThan($versionAfterUpload, $this->service->currentVersion());

        $maskable = $this->service->resolveIconContent('512-maskable');
        $decoded = imagecreatefromstring($maskable);
        $corner = imagecolorsforindex($decoded, imagecolorat($decoded, 2, 2));
        $this->assertSame(0, $corner['red']);
        $this->assertSame(255, $corner['green']);
        $this->assertSame(0, $corner['blue']);
        imagedestroy($decoded);
    }

    public function testDeleteUploadedLogoRemovesEveryOverrideAndFallsBackToDefaults(): void
    {
        $this->writeDefault('icon-192.png');
        $this->service->storeUploadedLogo($this->solidPngBytes(400, 9, 9, 9), 'image/png');
        $this->assertTrue($this->service->hasCustomLogo());

        $this->service->deleteUploadedLogo();

        $this->assertFalse($this->service->hasCustomLogo());
        $this->assertSame('shipped-default-bytes', $this->service->resolveIconContent('192'));
    }

    public function testDeleteUploadedLogoBumpsVersionSoCachesInvalidate(): void
    {
        $this->service->storeUploadedLogo($this->solidPngBytes(400, 9, 9, 9), 'image/png');
        $versionAfterUpload = $this->service->currentVersion();

        $this->service->deleteUploadedLogo();

        $this->assertGreaterThan($versionAfterUpload, $this->service->currentVersion());
    }
}
