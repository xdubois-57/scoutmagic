<?php

declare(strict_types=1);

namespace Tests\Core\Security;

use Core\Security\SessionManager;
use PHPUnit\Framework\TestCase;

class SessionManagerTest extends TestCase
{
    protected function setUp(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    public function testStartStartsASession(): void
    {
        $this->assertNotSame(PHP_SESSION_ACTIVE, session_status());

        @SessionManager::start();

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
    }

    public function testIsActiveReturnsCorrectState(): void
    {
        $this->assertFalse(SessionManager::isActive());

        @SessionManager::start();

        $this->assertTrue(SessionManager::isActive());
    }

    public function testStartDoesNotRestartActiveSession(): void
    {
        @SessionManager::start();
        $sessionId = session_id();

        @SessionManager::start();

        $this->assertSame($sessionId, session_id());
    }
}
