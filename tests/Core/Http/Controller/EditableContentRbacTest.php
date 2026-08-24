<?php

declare(strict_types=1);

namespace Tests\Core\Http\Controller;

use Core\Security\AuthSession;
use Core\Security\RbacGuard;
use Core\Security\Role;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The two editable-content endpoints are role_min: admin, and a chief is
 * refused.
 *
 * Both were superadmin, which contradicted every page that calls them:
 * configuration mode's own enforcement point (ConfigurationMode::isActive())
 * is admin, and the pages embedding partials/rich_text_field.html.twig
 * (banner config, registration config, the leadership module's unit note)
 * are all role_min: admin pages of the Espace chefs d'U menu. A chief
 * d'unité could therefore open the page, type a text, and only discover on
 * save that the endpoint refused them.
 *
 * The role is read out of public/index.php rather than copied here — the
 * route table lives in a procedural bootstrap no unit test loads, so a
 * source-level read is the only way to assert the value the application
 * actually registers (same technique as Tests\Security\FileAccessAuditTest).
 * It is then fed to the real RbacGuard, so this pins the boundary itself
 * (admin passes, chief gets 403) and not merely a string in a file.
 */
class EditableContentRbacTest extends TestCase
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
    public static function editableContentRoutes(): array
    {
        return [
            'configuration-mode in-place editing' => ['/api/editable-content', 'update'],
            'rich text field on an admin page' => ['/api/rich-text-content', 'updateField'],
        ];
    }

    private static function registeredRoleMin(string $path, string $action): string
    {
        $contents = file_get_contents(dirname(__DIR__, 4) . '/public/index.php');
        self::assertNotFalse($contents);

        $matched = preg_match(
            '/addRoute\s*\(\s*[\'"]POST[\'"]\s*,\s*[\'"]' . preg_quote($path, '/') . '[\'"]\s*,'
                . '\s*EditableContentController::class\s*,\s*[\'"]' . preg_quote($action, '/') . '[\'"]\s*,'
                . '\s*[\'"]([a-z_]+)[\'"]\s*\)/',
            $contents,
            $m
        );

        self::assertSame(1, $matched, "No addRoute registration found for POST {$path}");

        return $m[1];
    }

    #[DataProvider('editableContentRoutes')]
    public function testRouteIsRegisteredWithRoleMinAdmin(string $path, string $action): void
    {
        $this->assertSame(
            'admin',
            self::registeredRoleMin($path, $action),
            "POST {$path} must be role_min 'admin': its host pages are all admin pages, "
                . "and at superadmin it refused the chief d'unité they are for."
        );
    }

    #[DataProvider('editableContentRoutes')]
    public function testAdminIsAllowedToSave(string $path, string $action): void
    {
        $this->startTestSession();
        AuthSession::login(1, 'chef-unite@test.be', 'admin');

        $guard = new RbacGuard();

        $this->assertNull(
            $guard->enforce(Role::fromString(self::registeredRoleMin($path, $action))),
            "A chief d'unité (admin) must be able to save through POST {$path}."
        );
    }

    #[DataProvider('editableContentRoutes')]
    public function testChiefIsRefused(string $path, string $action): void
    {
        $this->startTestSession();
        AuthSession::login(2, 'chef@test.be', 'chief');

        $guard = new RbacGuard();
        $response = $guard->enforce(Role::fromString(self::registeredRoleMin($path, $action)));

        $this->assertNotNull($response, "A chief must not reach POST {$path}.");
        $this->assertSame(403, $response->getStatusCode());
    }

    private function startTestSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }
}
