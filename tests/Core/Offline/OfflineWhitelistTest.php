<?php

declare(strict_types=1);

namespace Tests\Core\Offline;

use Core\Offline\OfflineWhitelist;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

class OfflineWhitelistTest extends TestCase
{
    private OfflineWhitelist $whitelist;

    protected function setUp(): void
    {
        $this->whitelist = new OfflineWhitelist();
    }

    public function testEveryCoreEntryHasAPathLabelMatchAndRoleMin(): void
    {
        foreach ($this->whitelist->getAllEntries() as $entry) {
            $this->assertArrayHasKey('path', $entry);
            $this->assertArrayHasKey('label', $entry);
            $this->assertArrayHasKey('match', $entry);
            $this->assertArrayHasKey('role_min', $entry);
            $this->assertNotSame('', $entry['path']);
            $this->assertNotSame('', $entry['label']);
            $this->assertContains($entry['match'], ['exact', 'child']);
        }
    }

    public function testIncludesExactlyTheSpecifiedCorePages(): void
    {
        $paths = array_column($this->whitelist->getAllEntries(), 'path');

        $this->assertSame(
            ['/', '/contact', '/sections', '/rgpd', '/account', '/notifications', '/members/', '/chefs/staffs'],
            $paths
        );
    }

    public function testNeverIncludesFileServingOrPrivateRoutes(): void
    {
        $paths = array_column($this->whitelist->getAllEntries(), 'path');

        foreach ($paths as $path) {
            $this->assertStringNotContainsString('/files/', $path);
            $this->assertStringNotContainsString('/finance', $path);
            $this->assertStringNotContainsString('/mass-mail', $path);
            $this->assertStringNotContainsString('/inscriptions', $path);
            $this->assertStringNotContainsString('/config', $path);
        }
    }

    public function testCorePathsHaveNoDuplicates(): void
    {
        $paths = array_column($this->whitelist->getAllEntries(), 'path');

        $this->assertSame(array_unique($paths), $paths);
    }

    // --- module aggregation ---

    public function testRegisterModuleEntriesAddsThemToAllEntries(): void
    {
        $this->whitelist->registerModuleEntries('calendar', [
            ['path' => '/calendar', 'label' => 'Calendrier', 'match' => 'exact', 'role_min' => 'public'],
        ]);

        $paths = array_column($this->whitelist->getAllEntries(), 'path');
        $this->assertContains('/calendar', $paths);
    }

    public function testDisabledModuleNeverRegisteredMeansItNeverAppears(): void
    {
        // A disabled module's loadModule() is never called (Core\Module\
        // ModuleManager::loadEnabledModules() skips it entirely), so its
        // offline entries simply never reach registerModuleEntries() — no
        // explicit unregister mechanism needed.
        $paths = array_column($this->whitelist->getAllEntries(), 'path');

        $this->assertNotContains('/chiefs/stats', $paths);
        $this->assertNotContains('/previsions', $paths);
    }

    // --- role filtering (§1.3) ---

    public function testGuestRoleGetsOnlyPublicEntries(): void
    {
        $this->whitelist->registerModuleEntries('member_stats', [
            ['path' => '/chiefs/stats', 'label' => 'Statistiques', 'match' => 'exact', 'role_min' => 'chief'],
        ]);

        $paths = array_column($this->whitelist->getEntriesForRole(Role::PUBLIC), 'path');

        $this->assertSame(['/', '/contact', '/sections', '/rgpd'], $paths);
        $this->assertNotContains('/chiefs/stats', $paths);
        $this->assertNotContains('/account', $paths);
        $this->assertNotContains('/chefs/staffs', $paths);
    }

    public function testIdentifiedRoleGetsPublicAndIdentifiedButNotChiefOnlyPaths(): void
    {
        $this->whitelist->registerModuleEntries('registration', [
            ['path' => '/previsions', 'label' => 'Prévisions', 'match' => 'exact', 'role_min' => 'chief'],
        ]);

        $paths = array_column($this->whitelist->getEntriesForRole(Role::IDENTIFIED), 'path');

        $this->assertContains('/account', $paths);
        $this->assertContains('/notifications', $paths);
        $this->assertContains('/members/', $paths);
        $this->assertNotContains('/previsions', $paths);
        $this->assertNotContains('/chefs/staffs', $paths);
    }

    public function testChiefRoleGetsEverythingItsLevelAllows(): void
    {
        $this->whitelist->registerModuleEntries('member_stats', [
            ['path' => '/chiefs/stats', 'label' => 'Statistiques', 'match' => 'exact', 'role_min' => 'chief'],
        ]);

        $paths = array_column($this->whitelist->getEntriesForRole(Role::CHIEF), 'path');

        $this->assertContains('/chiefs/stats', $paths);
        $this->assertContains('/chefs/staffs', $paths);
        $this->assertContains('/account', $paths);
    }

    public function testGetEntriesForRoleStripsRoleMinFromTheReturnedShape(): void
    {
        $entries = $this->whitelist->getEntriesForRole(Role::PUBLIC);

        foreach ($entries as $entry) {
            $this->assertArrayNotHasKey('role_min', $entry);
        }
    }

    // --- child matching (`/members/12` in, `/members/12/emails/5` out) ---

    public function testChildMatchCoversAPathPlusExactlyOneSegment(): void
    {
        $this->assertTrue($this->whitelist->isPathWhitelisted('/members/12'));
    }

    public function testChildMatchExcludesAPathPlusTwoSegments(): void
    {
        $this->assertFalse($this->whitelist->isPathWhitelisted('/members/12/emails/5'));
    }

    public function testChildMatchExcludesTheBarePrefixWithNoSegment(): void
    {
        $this->assertFalse($this->whitelist->isPathWhitelisted('/members/'));
        $this->assertFalse($this->whitelist->isPathWhitelisted('/members'));
    }

    public function testChildMatchExcludesACoincidentalPrefixThatIsNotActuallyAChildSegment(): void
    {
        $this->assertFalse($this->whitelist->isPathWhitelisted('/members-list'));
    }

    public function testExactMatchOnlyMatchesTheLiteralPath(): void
    {
        $this->assertTrue($this->whitelist->isPathWhitelisted('/contact'));
        $this->assertFalse($this->whitelist->isPathWhitelisted('/contact/extra'));
    }

    public function testIsPathWhitelistedIsRoleAgnostic(): void
    {
        $this->whitelist->registerModuleEntries('registration', [
            ['path' => '/previsions', 'label' => 'Prévisions', 'match' => 'exact', 'role_min' => 'chief'],
        ]);

        // Server-side ETag eligibility never re-checks role — the RBAC
        // guard already ran by the time Core\Http\FrontController gets
        // here.
        $this->assertTrue($this->whitelist->isPathWhitelisted('/previsions'));
    }

    public function testIsPathWhitelistedReturnsFalseForAnUnknownPath(): void
    {
        $this->assertFalse($this->whitelist->isPathWhitelisted('/finance'));
    }
}
