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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The assistant's two routes: `role_min: chief`, and `/aide/assistant`
 * registered BEFORE `/aide/{topic}`.
 *
 * The role floor is a locked decision of this feature (D4): the assistant
 * answers from documentation a chief may read, and every step downstream —
 * the catalogue it is shown, each topic id it is allowed to name, the body
 * it is allowed to open — is filtered by that same role. Lowering the
 * floor here would silently widen all three, since the role travels from
 * the session and not from the request.
 *
 * The local search deliberately does NOT sit behind it (D2): it stays
 * `public`, works offline, and answers with no provider configured. This
 * test pins the difference.
 *
 * The route ORDER is the other half. Core\Http\Router::resolve() keeps the
 * first match in registration order, so `/aide/{topic}` registered first
 * would swallow `/aide/assistant` and answer 404 for a help topic that
 * does not exist. Core\Help\HelpFrontMatterParser reserves the id
 * `assistant` against the same collision from the other side; this checks
 * the side the parser cannot see.
 *
 * The values are read out of public/index.php rather than copied here: the
 * route table lives in a procedural bootstrap no unit test loads, so a
 * source-level read is the only way to assert what the application really
 * registers (same technique as Tests\Core\Http\Controller\EditableContentRbacTest).
 * The role is then fed to the real RbacGuard, so this pins the boundary
 * itself and not merely a string in a file.
 */
class HelpAssistantRbacTest extends TestCase
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
     * @return array<string, array{string, string}>
     */
    public static function assistantRoutes(): array
    {
        return [
            'the full page' => ['GET', '/aide/assistant'],
            'the endpoint both surfaces post to' => ['POST', '/api/aide/assistant'],
        ];
    }

    private static function source(): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents);

        return $contents;
    }

    private static function registeredRoleMin(string $method, string $path): string
    {
        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]' . $method . '[\'"]\s*,\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*,'
                . '\s*\\\\Core\\\\Http\\\\Controller\\\\HelpAssistantController::class\s*,'
                . '\s*[\'"][a-zA-Z]+[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]/',
            self::source(),
            $m
        );

        self::assertSame(1, $matched, "No addRoute registration found for {$method} {$path}");

        return $m[1];
    }

    #[DataProvider('assistantRoutes')]
    public function testRouteIsRegisteredWithRoleMinChief(string $method, string $path): void
    {
        $this->assertSame(
            'chief',
            self::registeredRoleMin($method, $path),
            "{$method} {$path} must be role_min 'chief' (locked decision D4)."
        );
    }

    #[DataProvider('assistantRoutes')]
    public function testAChiefReachesTheAssistant(string $method, string $path): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chef@test.be', 'chief');

        $this->assertNull(
            (new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path))),
            "A chief must reach {$method} {$path}."
        );
    }

    #[DataProvider('assistantRoutes')]
    public function testAnIntendantIsRefused(string $method, string $path): void
    {
        $this->startTestSession();
        AuthSession::login(2, 'intendant@test.be', 'intendant');

        $response = (new RbacGuard())->enforce(Role::fromString(self::registeredRoleMin($method, $path)));

        $this->assertNotNull($response, "An intendant must not reach {$method} {$path}.");
        $this->assertSame(403, $response->getStatusCode());
    }

    public function testTheLocalSearchStaysPublic(): void
    {
        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]GET[\'"]\s*,\s*[\'"]\/aide[\'"]\s*,'
                . '[^;]*?[\'"]index[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]/s',
            self::source(),
            $m
        );

        self::assertSame(1, $matched, 'No addRoute registration found for GET /aide');
        $this->assertSame(
            'public',
            $m[1],
            "The help index carries the local search, which must keep working "
                . "logged out and offline (locked decision D2) — only the assistant is chief."
        );
    }

    /**
     * Byte offset of the GET registration for $path, or null if there is none.
     */
    private static function offsetOfRoute(string $source, string $path): ?int
    {
        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]GET[\'"]\s*,\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*,/',
            $source,
            $m,
            PREG_OFFSET_CAPTURE
        );

        return $matched === 1 ? (int) $m[0][1] : null;
    }

    public function testTheAssistantPageIsRegisteredBeforeTheTopicRoute(): void
    {
        $source = self::source();

        // Offset of each registration, found whitespace-tolerantly: the
        // composition root wraps long addRoute() calls one argument per line,
        // and this test is about ORDER, never about layout.
        $assistant = self::offsetOfRoute($source, '/aide/assistant');
        $topic = self::offsetOfRoute($source, '/aide/{topic}');

        $this->assertIsInt($assistant, 'GET /aide/assistant is not registered.');
        $this->assertIsInt($topic, 'GET /aide/{topic} is not registered.');
        $this->assertLessThan(
            $topic,
            $assistant,
            "Core\\Http\\Router::resolve() keeps the FIRST route that matches, in registration "
                . "order: /aide/{topic} registered first swallows /aide/assistant and the "
                . "assistant becomes a 404 for a help topic nobody wrote."
        );
    }

    private function startTestSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}
