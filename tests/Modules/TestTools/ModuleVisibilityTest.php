<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use Core\Module\InstallationProfile;
use Core\Module\ModuleManifest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The module exists on the reference installation and on a developer's
 * machine, and nowhere else (ARCHITECTURE.md §8.63). That single fact is
 * what makes the subject-in-clear exception of SECURITY.md admissible, so
 * it is pinned here rather than left to the manifest alone.
 */
class ModuleVisibilityTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $this->manifest = ModuleManifest::fromFile(
            dirname(__DIR__, 3) . '/modules/test_tools/module.json'
        );
    }

    public function testTheManifestGatesTheModuleOnBothFlags(): void
    {
        $this->assertSame(
            [
                InstallationProfile::FLAG_REFERENCE_INSTALLATION,
                InstallationProfile::FLAG_LOCAL_INSTALLATION,
            ],
            $this->manifest->visibleWhen
        );
    }

    public function testTheModuleIsNotEnabledByDefault(): void
    {
        $this->assertFalse($this->manifest->enabledByDefault);
    }

    public function testEveryRouteIsSuperadminOnly(): void
    {
        $this->assertNotSame([], $this->manifest->routes);

        foreach ($this->manifest->routes as $route) {
            $this->assertSame('superadmin', $route['role_min'], $route['path']);
            $this->assertSame('configuration', $route['menu'], $route['path']);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function installationProvider(): array
    {
        return [
            'the reference installation' => ['https://scoutmagic.be'],
            'the reference installation with www.' => ['https://www.scoutmagic.be'],
            'a developer machine' => ['http://localhost:8080'],
            'a .test host' => ['http://scoutmagic.test'],
        ];
    }

    #[DataProvider('installationProvider')]
    public function testTheModuleIsVisibleOnAFlaggedInstallation(string $baseUrl): void
    {
        $profile = InstallationProfile::resolve($baseUrl, '');

        $this->assertTrue($profile->hasAny($this->manifest->visibleWhen), $baseUrl);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function ordinaryInstallationProvider(): array
    {
        return [
            'a deploying unit' => ['https://unite-exemple.be'],
            'the statistics receiver on another host' => ['https://stats.example.be'],
            'a domain merely containing "local"' => ['https://mylocal.be'],
            'an empty base_url' => [''],
        ];
    }

    #[DataProvider('ordinaryInstallationProvider')]
    public function testTheModuleIsInvisibleOnAnOrdinaryInstallation(string $baseUrl): void
    {
        $profile = InstallationProfile::resolve($baseUrl, 'https://scoutmagic.be');

        $this->assertFalse($profile->hasAny($this->manifest->visibleWhen), $baseUrl);
    }
}
