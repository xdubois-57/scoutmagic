<?php

declare(strict_types=1);

namespace Tests\Core\Config;

use Core\Config\AppConfig;
use PHPUnit\Framework\TestCase;

class AppConfigTest extends TestCase
{
    private string $tempConfigPath;

    protected function setUp(): void
    {
        $this->tempConfigPath = sys_get_temp_dir() . '/test_app_config_' . uniqid() . '.php';
        file_put_contents($this->tempConfigPath, '<?php return [
            "debug" => true,
            "site_name" => "Test Unit",
            "base_url" => "http://localhost:8000",
        ];');
    }

    protected function tearDown(): void
    {
        if (file_exists($this->tempConfigPath)) {
            unlink($this->tempConfigPath);
        }
    }

    public function testLoadsConfigFromFile(): void
    {
        $config = new AppConfig($this->tempConfigPath);

        $this->assertSame('Test Unit', $config->get('site_name'));
    }

    public function testGetReturnsValue(): void
    {
        $config = new AppConfig($this->tempConfigPath);

        $this->assertSame('http://localhost:8000', $config->get('base_url'));
    }

    public function testGetReturnsDefaultForMissingKey(): void
    {
        $config = new AppConfig($this->tempConfigPath);

        $this->assertNull($config->get('nonexistent'));
        $this->assertSame('fallback', $config->get('nonexistent', 'fallback'));
    }

    public function testIsDebugReflectsConfigValue(): void
    {
        $config = new AppConfig($this->tempConfigPath);

        $this->assertTrue($config->isDebug());

        // Test with debug = false
        $noDebugPath = sys_get_temp_dir() . '/test_app_config_nodebug_' . uniqid() . '.php';
        file_put_contents($noDebugPath, '<?php return ["debug" => false];');

        $configNoDebug = new AppConfig($noDebugPath);
        $this->assertFalse($configNoDebug->isDebug());

        unlink($noDebugPath);
    }

    public function testThrowsExceptionForMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);

        new AppConfig('/nonexistent/path/config.php');
    }
}
