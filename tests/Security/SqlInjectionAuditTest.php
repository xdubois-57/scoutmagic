<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

class SqlInjectionAuditTest extends TestCase
{
    /** @var string[] */
    private array $allowedFiles = [
        'core/Database/SchemaIntrospector.php',
        'core/Database/MigrationRunner.php',
        'core/Database/Connection.php',
        'core/Database/SchemaComparator.php',
    ];

    public function testNoPdoQueryWithVariables(): void
    {
        $coreDir = dirname(__DIR__, 2) . '/core';
        // Flag pdo->query() that includes variable interpolation or concatenation (unsafe)
        $violations = $this->scanForPattern($coreDir, '/\$(?:this->)?pdo->query\s*\(\s*(?:.*\$|\s*"[^"]*\$)/');

        $filtered = $this->filterAllowed($violations);
        $this->assertEmpty(
            $filtered,
            "Found \$pdo->query() with variable interpolation:\n" . implode("\n", $filtered)
        );
    }

    public function testNoPdoExecWithVariables(): void
    {
        $coreDir = dirname(__DIR__, 2) . '/core';
        // Flag pdo->exec() that includes variable interpolation (unsafe)
        $violations = $this->scanForPattern($coreDir, '/\$(?:this->)?pdo->exec\s*\(\s*(?:.*\$|\s*"[^"]*\$)/');

        $filtered = $this->filterAllowed($violations);
        $this->assertEmpty(
            $filtered,
            "Found \$pdo->exec() with variable interpolation:\n" . implode("\n", $filtered)
        );
    }

    public function testNoSqlStringConcatenation(): void
    {
        $coreDir = dirname(__DIR__, 2) . '/core';
        // Look for string concatenation patterns in SQL: "SELECT ... " . $var or 'SELECT ... ' . $var
        $violations = $this->scanForPattern(
            $coreDir,
            '/["\'](?:SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b.*["\']\s*\.\s*\$/'
        );

        $filtered = $this->filterAllowed($violations);
        $this->assertEmpty(
            $filtered,
            "Found SQL string concatenation:\n" . implode("\n", $filtered)
        );
    }

    /**
     * @return string[]
     */
    private function scanForPattern(string $directory, string $pattern): array
    {
        $violations = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }

            $lines = explode("\n", $contents);
            foreach ($lines as $lineNum => $line) {
                if (preg_match($pattern, $line)) {
                    $relativePath = str_replace(dirname(__DIR__, 2) . '/', '', $file->getPathname());
                    $violations[] = "{$relativePath}:" . ($lineNum + 1) . ": {$line}";
                }
            }
        }

        return $violations;
    }

    /**
     * @param string[] $violations
     * @return string[]
     */
    private function filterAllowed(array $violations): array
    {
        return array_filter($violations, function (string $v): bool {
            foreach ($this->allowedFiles as $allowed) {
                if (str_contains($v, $allowed)) {
                    return false;
                }
            }
            return true;
        });
    }
}
