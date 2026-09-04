<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

class FileAccessAuditTest extends TestCase
{
    public function testNoStoragePathsInTemplates(): void
    {
        $templateDir = dirname(__DIR__, 2) . '/core/View/templates';
        $violations = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($templateDir, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'twig') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            $lines = explode("\n", $contents);
            foreach ($lines as $lineNum => $line) {
                // Match /storage/ paths but not inside comments or file_url() calls
                if (preg_match('#(?<![a-z_])(/storage/|href\s*=\s*["\'][^"\']*storage/)#i', $line)) {
                    $relativePath = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname());
                    $violations[] = "{$relativePath}:" . ($lineNum + 1) . ": {$line}";
                }
            }
        }

        $this->assertEmpty(
            $violations,
            "Found direct /storage/ paths in templates (use file_url() instead):\n" . implode("\n", $violations)
        );
    }

    public function testNoDirectStorageRoutesInIndex(): void
    {
        $indexPath = dirname(__DIR__, 2) . '/public/index.php';
        $contents = file_get_contents($indexPath);
        $this->assertNotFalse($contents);

        // No route should serve from storage/ directly
        $this->assertDoesNotMatchRegularExpression(
            '/addRoute\s*\([^)]*storage/i',
            $contents,
            'Found a route that references storage/ directly'
        );
    }

    /**
     * The maintenance operations that overwrite the live PHP tree or wipe
     * the install — installing an update, restoring a backup, and the two
     * resets — must require `superadmin`, never merely `admin`. An `admin`
     * with access to backup restore could otherwise craft an archive and
     * escalate to code execution, effectively past `superadmin` (audit H2).
     * Pinned here as a source-level regression guard because the route
     * table lives in the procedural bootstrap of public/index.php, which no
     * unit test loads directly.
     *
     * @return array<string, array{string}>
     */
    public static function superadminOnlyMaintenanceActions(): array
    {
        return [
            'install update' => ['installUpdate'],
            'restore backup' => ['restoreBackup'],
            'reset settings' => ['resetSettings'],
            'full reset' => ['fullReset'],
        ];
    }

    /**
     * @dataProvider superadminOnlyMaintenanceActions
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('superadminOnlyMaintenanceActions')]
    public function testCodeOverwritingMaintenanceRoutesRequireSuperadmin(string $action): void
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        $this->assertNotFalse($contents);

        // Match the addRoute(...) call that dispatches to this action and
        // capture its trailing role_min argument.
        $this->assertSame(
            1,
            preg_match(
                '/addRoute\s*\([^;]*MaintenanceController::class\s*,\s*[\'"]' . preg_quote($action, '/')
                . '[\'"]\s*,\s*[\'"]([a-z_]+)[\'"]\s*,?\s*\)/',
                $contents,
                $m
            ),
            "No addRoute registration found for MaintenanceController::{$action}()"
        );
        $this->assertSame(
            'superadmin',
            $m[1],
            "MaintenanceController::{$action}() must be registered with role_min 'superadmin' (audit H2), got '{$m[1]}'"
        );
    }
}
