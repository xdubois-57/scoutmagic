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

    public function testActivateWithAdminRoleSucceeds(): void
    {
        $this->startTestSession();
        $this->assertTrue(ConfigurationMode::activate('admin'));
    }

    public function testActivateWithNonAdminRoleFails(): void
    {
        $this->startTestSession();
        $this->assertFalse(ConfigurationMode::activate('identified'));
        $this->assertFalse(ConfigurationMode::activate('chief'));
        $this->assertFalse(ConfigurationMode::activate('public'));
    }

    public function testIsActiveReturnsTrueAfterActivation(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.com', 'admin');
        ConfigurationMode::activate('admin');
        $this->assertTrue(ConfigurationMode::isActive());
    }

    public function testDeactivateClearsFlag(): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'admin@test.com', 'admin');
        ConfigurationMode::activate('admin');
        ConfigurationMode::deactivate();
        $this->assertFalse(ConfigurationMode::isActive());
    }

    public function testIsActiveReturnsFalseWhenRoleIsNotAdmin(): void
    {
        $this->startTestSession();
        // Set the flag directly but with non-admin role
        $_SESSION['_config_mode'] = true;
        AuthSession::login(1, 'user@test.com', 'identified');
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
