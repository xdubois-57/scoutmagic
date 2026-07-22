<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Locks in module.json's RBAC-relevant declarations — module spec:
 * "Envoi de mails" (espace_chefs) is role_min chief; "Configuration envoi
 * de mails" (configuration menu) must be role_min superadmin since
 * Core\Module\ModuleManifest hard-enforces that the 'configuration' menu's
 * own floor is superadmin (see ARCHITECTURE.md §7 and
 * ModuleManifest::MENU_MIN_ROLES) — 'admin' would fail manifest
 * validation entirely, which this test also guards against regressing to.
 */
class ModuleManifestTest extends TestCase
{
    private ModuleManifest $manifest;

    protected function setUp(): void
    {
        $this->manifest = ModuleManifest::fromFile(dirname(__DIR__, 3) . '/modules/mass_mail/module.json');
    }

    public function testEspaceChefsRoutesRequireAtLeastChiefRole(): void
    {
        foreach ($this->manifest->routes as $route) {
            if ($route['menu'] === 'espace_chefs') {
                $this->assertSame('chief', $route['role_min'], "Route {$route['path']} should be role_min chief");
            }
        }
    }

    public function testConfigurationRoutesRequireSuperadminRole(): void
    {
        foreach ($this->manifest->routes as $route) {
            if ($route['menu'] === 'configuration') {
                $this->assertSame('superadmin', $route['role_min'], "Route {$route['path']} should be role_min superadmin");
            }
        }
    }

    public function testTrackingRouteHasNoMenuLabel(): void
    {
        $trackingRoute = array_values(array_filter($this->manifest->routes, fn(array $r) => str_ends_with($r['path'], '/tracking')))[0] ?? null;
        $this->assertNotNull($trackingRoute);
        $this->assertSame('', $trackingRoute['label']);
    }

    public function testAttachmentsStorageRequiresChiefRole(): void
    {
        $this->assertSame('chief', $this->manifest->storage['attachments']['role_min']);
    }

    public function testModuleDisabledByDefault(): void
    {
        $this->assertFalse($this->manifest->enabledByDefault);
    }

    public function testSchedulesExactlyOneTaskType(): void
    {
        $this->assertCount(1, $this->manifest->scheduledTasks);
        $this->assertSame('send_batch', $this->manifest->scheduledTasks[0]['key']);
    }
}
