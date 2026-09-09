<?php

declare(strict_types=1);

namespace Tests\Modules\MassMail;

use Core\Module\ModuleManifest;
use PHPUnit\Framework\TestCase;

/**
 * Locks in module.json's RBAC-relevant declarations — module spec:
 * "Envoi de mails" (espace_chefs) is role_min chief; « Listes de
 * diffusion » (espace_admin menu) is role_min admin.
 *
 * That second floor is the reason the page left Configuration.
 * Core\Module\ModuleManifest hard-enforces each menu's own floor
 * (MENU_MIN_ROLES) and refuses at load time any route more permissive than
 * its menu: `configuration` imposes `superadmin`, so `admin` was not
 * declarable there at all, while `espace_admin`'s floor IS `admin`. The
 * tests below are what stops the page drifting back.
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

    public function testTheModuleDeclaresNoConfigurationRouteAnyMore(): void
    {
        $configurationRoutes = array_filter($this->manifest->routes, fn(array $r) => $r['menu'] === 'configuration');

        $this->assertSame([], array_values($configurationRoutes));
    }

    public function testMailingListRoutesLiveInEspaceAdminAtAdminFloor(): void
    {
        $paths = [];
        foreach ($this->manifest->routes as $route) {
            if (!str_starts_with($route['path'], '/admin/listes-de-diffusion')) {
                continue;
            }
            $paths[] = $route['path'];
            $this->assertSame('espace_admin', $route['menu'], "Route {$route['path']} should be in espace_admin");
            $this->assertSame('admin', $route['role_min'], "Route {$route['path']} should be role_min admin");
        }

        $this->assertSame(
            [
                '/admin/listes-de-diffusion',
                '/admin/listes-de-diffusion/preview-count',
                '/admin/listes-de-diffusion/lists',
                '/admin/listes-de-diffusion/lists/{id}',
                '/admin/listes-de-diffusion/lists/{id}/addresses',
                '/admin/listes-de-diffusion/lists/{id}/addresses',
                '/admin/listes-de-diffusion/lists/{id}/addresses/export',
                '/admin/listes-de-diffusion/lists/{id}/addresses/import',
                '/admin/listes-de-diffusion/lists/{id}/addresses/import/confirm',
                '/admin/listes-de-diffusion/addresses/{id}',
                '/admin/listes-de-diffusion/addresses/{id}',
                '/admin/listes-de-diffusion/lists/{id}/toggle',
                '/admin/listes-de-diffusion/lists/{id}',
            ],
            $paths
        );
    }

    public function testTheMailingListPageIsLabelledForWhatItDecides(): void
    {
        $index = array_values(array_filter(
            $this->manifest->routes,
            fn(array $r) => $r['path'] === '/admin/listes-de-diffusion' && $r['method'] === 'GET'
        ))[0] ?? null;

        $this->assertNotNull($index);
        $this->assertSame('Listes de diffusion', $index['label']);
    }

    /**
     * The module's settings are ordinary SettingService rows and
     * Configuration > Réglages already edits them — the module's own
     * duplicate editor (POST /config/mass-mail/settings) is gone, and the
     * settings themselves must survive that removal.
     */
    public function testTheSendingSettingsSurviveTheRemovalOfTheirOwnForm(): void
    {
        $keys = array_map(fn(array $s) => $s['key'], $this->manifest->settings);

        $this->assertSame(
            [
                'batch_size',
                'batch_interval_minutes',
                'merge_retention_months',
                'anciens_min_scout_years',
                'anciens_max_years_since_departure',
                'mass_mail_list_addresses_max',
                'previous_year_active_cutoff',
            ],
            $keys
        );
        $this->assertSame(
            [],
            array_values(array_filter($this->manifest->routes, fn(array $r) => str_ends_with($r['path'], '/settings')))
        );
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

    public function testSchedulesExactlyTwoTaskTypes(): void
    {
        // send_batch (module spec: never one job per recipient) plus the
        // daily mail-merge audience retention purge (ARCHITECTURE.md §8.61).
        $keys = array_map(fn(array $t) => $t['key'], $this->manifest->scheduledTasks);
        $this->assertSame(['send_batch', 'purge_merge_audiences'], $keys);
    }

    public function testMergeRetentionSettingIsReadOnly(): void
    {
        $setting = array_values(array_filter($this->manifest->settings, fn(array $s) => $s['key'] === 'merge_retention_months'))[0] ?? null;
        $this->assertNotNull($setting);
        $this->assertFalse($setting['editable']);
        $this->assertSame('18', $setting['default_value']);
    }

    public function testValidationRegexAndEditableFlowThroughTheManifest(): void
    {
        // previous_year_active_cutoff declares a validation_regex in
        // module.json — it must survive parsing (it used to be silently
        // dropped), and a setting without "editable" defaults to editable.
        $setting = array_values(array_filter($this->manifest->settings, fn(array $s) => $s['key'] === 'previous_year_active_cutoff'))[0] ?? null;
        $this->assertNotNull($setting);
        $this->assertNotNull($setting['validation_regex']);
        $this->assertTrue($setting['editable']);
    }
}
