<?php

declare(strict_types=1);

namespace Tests\Core\Http;

use PHPUnit\Framework\TestCase;

/**
 * Guards the ONE place the RBAC guard can legitimately be switched off.
 *
 * Core\Http\FrontController::setRbacBypassPrefix() removes role_min
 * enforcement for a path prefix. That is correct for exactly one situation —
 * the first-run installer, where there is no database, no account and so
 * nobody who could hold a role. It is wired in public/index.php, which no
 * unit test boots, so the condition is asserted against the source itself.
 *
 * The bug this pins: the bypass used to be armed on
 * `!isInitialized() || $allowSetup`, where $allowSetup came from an
 * undocumented `allow_setup` config key. On a live site that stripped
 * authentication off every /setup route — GET /setup leaks the database,
 * SMTP and admin-email settings to an anonymous visitor and hands them a
 * CSRF token, and POST /setup/save then rewrites database credentials and
 * the admin account. Once initialized, /setup is reachable by its own
 * role_min (superadmin) like any other route, so a bypass there can never
 * enable anything legitimate.
 */
class SetupRbacBypassWiringTest extends TestCase
{
    private string $bootstrap;

    protected function setUp(): void
    {
        $this->bootstrap = (string) file_get_contents(dirname(__DIR__, 3) . '/public/index.php');
    }

    public function testTheBypassIsArmedOnlyWhileTheSiteIsUninitialized(): void
    {
        $this->assertMatchesRegularExpression(
            '/if \(!\$secretManager->isInitialized\(\)\) \{\s*\$frontController->setRbacBypassPrefix\(\'\/setup\'\);\s*\}/',
            $this->bootstrap,
            'the /setup RBAC bypass must be guarded by !isInitialized() and nothing else'
        );
    }

    public function testTheBypassIsArmedExactlyOnceAndOnlyForSetup(): void
    {
        preg_match_all('/setRbacBypassPrefix\(([^)]*)\)/', $this->bootstrap, $matches);

        $this->assertCount(1, $matches[0], 'exactly one call site');
        $this->assertSame("'/setup'", trim($matches[1][0]));
    }

    /**
     * No config key may re-open the bypass on an initialized site.
     */
    public function testNoConfigToggleCanReArmTheBypass(): void
    {
        $this->assertDoesNotMatchExpectedToggle('allow_setup');
        $this->assertDoesNotMatchExpectedToggle('allowSetup');
    }

    private function assertDoesNotMatchExpectedToggle(string $needle): void
    {
        // Only code, not the comment explaining why the toggle was removed.
        $codeOnly = preg_replace('~//[^\n]*~', '', $this->bootstrap) ?? $this->bootstrap;

        $this->assertStringNotContainsString(
            $needle,
            $codeOnly,
            "'{$needle}' must not gate the /setup RBAC bypass — it removed authentication on a live site"
        );
    }
}
