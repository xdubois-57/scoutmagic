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
}
