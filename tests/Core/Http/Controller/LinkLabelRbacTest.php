<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Security\AuthSession;
use Core\Security\RbacGuard;
use Core\Security\Role;
use PHPUnit\Framework\TestCase;

/**
 * `POST /api/lien/libelle` (#840) is `role_min: identified`: every account
 * that can open a rich-text editor may ask for a link's text, and a visitor
 * who is not signed in may not — the endpoint makes an outbound request,
 * and SECURITY.md §17's per-person budget needs a person to count.
 *
 * Read out of public/index.php, then fed to the real RbacGuard, as
 * HelpAssistantRbacTest does: the route table lives in a procedural
 * bootstrap no unit test loads.
 */
final class LinkLabelRbacTest extends TestCase
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

    private static function registeredRoleMin(): string
    {
        $source = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($source);
        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]POST[\'"]\s*,\s*[\'"]\/api\/lien\/libelle[\'"]\s*,'
                . '\s*\\\\Core\\\\Http\\\\Controller\\\\LinkLabelController::class\s*,'
                . '\s*[\'"]suggest[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]/',
            $source,
            $m
        );
        self::assertSame(1, $matched, 'No addRoute registration found for POST /api/lien/libelle');

        return $m[1];
    }

    public function testTheRouteIsRoleMinIdentified(): void
    {
        $this->assertSame('identified', self::registeredRoleMin());
    }

    public function testASignedInAccountReachesIt(): void
    {
        $this->startTestSession();
        AuthSession::login(3, 'parent@test.be', 'identified');

        $this->assertNull((new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin())));
    }

    public function testAVisitorWhoIsNotSignedInDoesNot(): void
    {
        $this->startTestSession();

        $this->assertNotNull((new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin())));
    }

    private function startTestSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}
