<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use PHPUnit\Framework\TestCase;

/**
 * public/index.php's core `$router->addRoute(...)` breadcrumb declarations
 * (Core\Http\Router::addRoute()'s 6th argument) for "Configuration
 * générale" (Espace chefs d'U) and every page in the Configuration space —
 * previously missing, so those pages showed no breadcrumb trail at all.
 * Structural/wiring test reading the raw file, same precedent as
 * tests/Core/Http/MenuRegistrationOrderTest.php (no PHP unit test can
 * otherwise exercise this giant procedural bootstrap script's literal
 * addRoute() calls).
 */
class ConfigBreadcrumbRoutesTest extends TestCase
{
    private string $indexPhp;

    protected function setUp(): void
    {
        $this->indexPhp = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
    }

    /**
     * @return array{label: string, parentsExpr: string}
     */
    private function breadcrumbForGetRoute(string $path): array
    {
        $pattern = '/\$router->addRoute\(\s*\'GET\'\s*,\s*\'' . preg_quote($path, '/') . '\'\s*,[^;]*?'
            . '\[\s*\'label\'\s*=>\s*\'([^\']+)\'\s*,\s*\'parents\'\s*=>\s*\[([^\]]+)\]\s*,?\s*\]'
            . '\s*,?\s*\)\s*;/';
        $this->assertMatchesRegularExpression($pattern, $this->indexPhp, "No breadcrumb found for GET {$path}");

        preg_match($pattern, $this->indexPhp, $matches);

        return ['label' => $matches[1], 'parentsExpr' => $matches[2]];
    }

    public function testConfigGeneralBreadcrumbParentsEspaceChefsDU(): void
    {
        $breadcrumb = $this->breadcrumbForGetRoute('/config/general');

        $this->assertSame('Édition du site', $breadcrumb['label']);
        $this->assertStringContainsString('MenuBuilder::MENU_ESPACE_ADMIN', $breadcrumb['parentsExpr']);
    }

    /**
     * @return array<string, string[]>
     */
    public static function configurationSpacePages(): array
    {
        return [
            '/setup' => ['/setup', 'Installation & serveur'],
            '/config/modules' => ['/config/modules', 'Modules'],
            '/config/badges' => ['/config/badges', 'Badges'],
            '/config/functions' => ['/config/functions', 'Desk'],
            '/config/settings' => ['/config/settings', 'Réglages'],
            '/config/rgpd' => ['/config/rgpd', 'RGPD'],
            '/config/scheduled' => ['/config/scheduled', 'Actions planifiées'],
            '/config/maintenance' => ['/config/maintenance', 'Maintenance'],
            '/config/notifications' => ['/config/notifications', 'Notifications'],
        ];
    }

    /**
     * @dataProvider configurationSpacePages
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('configurationSpacePages')]
    public function testConfigurationSpacePageBreadcrumbParentsConfiguration(string $path, string $expectedLabel): void
    {
        $breadcrumb = $this->breadcrumbForGetRoute($path);

        $this->assertSame($expectedLabel, $breadcrumb['label']);
        $this->assertStringContainsString('MenuBuilder::MENU_CONFIGURATION', $breadcrumb['parentsExpr']);
    }
}
