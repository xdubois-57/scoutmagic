<?php

declare(strict_types=1);

namespace Tests\Core\Mail;

use Core\Mail\DkimManager;
use PHPUnit\Framework\TestCase;

class DkimManagerTest extends TestCase
{
    private string $tempDir;
    private DkimManager $manager;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/dkim_test_' . uniqid();
        mkdir($this->tempDir, 0700, true);
        $this->manager = new DkimManager($this->tempDir);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->tempDir);
    }

    public function testHasKeyReturnsFalseWhenNoKeyExists(): void
    {
        $this->assertFalse($this->manager->hasKey());
    }

    public function testGenerateKeyCreatesPrivateKeyFileAndReturnsPublicKey(): void
    {
        $publicKey = $this->manager->generateKey();

        $this->assertTrue($this->manager->hasKey());
        $this->assertFileExists($this->manager->getPrivateKeyPath());
        $this->assertNotEmpty($publicKey);
        // Public key should be a base64 string
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9+\/=]+$/', $publicKey);
    }

    public function testGenerateKeyThrowsWhenKeyAlreadyExists(): void
    {
        $this->manager->generateKey();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already exists');

        $this->manager->generateKey();
    }

    public function testGetPublicKeyReturnsSameKeyAsGenerate(): void
    {
        $generatedKey = $this->manager->generateKey();
        $retrievedKey = $this->manager->getPublicKey();

        $this->assertSame($generatedKey, $retrievedKey);
    }

    public function testDeleteKeyRemovesFile(): void
    {
        $this->manager->generateKey();
        $this->assertTrue($this->manager->hasKey());

        $this->manager->deleteKey();
        $this->assertFalse($this->manager->hasKey());
    }

    public function testGenerateKeyAfterDeleteKeyWorks(): void
    {
        $key1 = $this->manager->generateKey();
        $this->manager->deleteKey();
        $key2 = $this->manager->generateKey();

        $this->assertTrue($this->manager->hasKey());
        $this->assertNotEmpty($key2);
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
