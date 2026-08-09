<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\Security\AuthSession;
use Core\View\ConfigurationMode;
use PHPUnit\Framework\TestCase;

class ConfigurationModeTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    /**
     * Configuration mode was widened from superadmin-only to admin (Chef
     * d'Unité) alongside its toggle page's move from the Configuration
     * menu to "Espace chefs d'U" — RBAC boundary tested at both ends:
     * allowed at admin, denied one level below at chief.
     */
    public function testActivateWithAdminRoleSucceeds(): void
    {
        $this->startTestSession();
        $this->assertTrue(ConfigurationMode::activate('admin'));
    }

    public function testActivateWithSuperadminRoleStillSucceeds(): void
    {
        $this->startTestSession();
        $this->assertTrue(ConfigurationMode::activate('superadmin'));
    }

    public function testActivateWithChiefRoleFails(): void
    {
        $this->startTestSession();
        // "chief" is one level below "admin" (Chef d'Unité) — denied, the
        // actual RBAC boundary now that this widened from superadmin.
        $this->assertFalse(ConfigurationMode::activate('chief'));
    }

    public function testActivateWithLowerRolesFails(): void
    {
        $this->startTestSession();
        $this->assertFalse(ConfigurationMode::activate('identified'));
        $this->assertFalse(ConfigurationMode::activate('intendant'));
        $this->assertFalse(ConfigurationMode::activate('public'));
    }

    public function testIsActiveReturnsTrueAfterActivation(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief-unite@test.com', 'admin');
        ConfigurationMode::activate('admin');
        $this->assertTrue(ConfigurationMode::isActive());
    }

    public function testDeactivateClearsFlag(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chief-unite@test.com', 'admin');
        ConfigurationMode::activate('admin');
        ConfigurationMode::deactivate();
        $this->assertFalse(ConfigurationMode::isActive());
    }

    public function testIsActiveReturnsFalseWhenRoleIsBelowAdmin(): void
    {
        $this->startTestSession();
        // Set the flag directly but with a role below admin — isActive()
        // re-checks the CURRENT role on every call, not just at activation
        // time (e.g. a session demoted after activating stays revoked
        // immediately, not just on next activate()).
        $_SESSION['_config_mode'] = true;
        AuthSession::login(1, 'user@test.com', 'chief');
        $this->assertFalse(ConfigurationMode::isActive());
    }

    public function testIsActiveReturnsFalseWithoutSession(): void
    {
        $this->startTestSession();
        $this->assertFalse(ConfigurationMode::isActive());
    }

    private function startTestSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.use_cookies', '0');
            ini_set('session.cache_limiter', '');
            session_start();
        }
    }
}
