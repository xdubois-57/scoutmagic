<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;

/**
 * HTTPS detection has exactly one implementation: Core\Http\RequestScheme.
 *
 * It was duplicated across six call sites for a long time, and one of them
 * had already drifted — Response's HSTS emission read $_SERVER['HTTPS']
 * alone while the five cookie-flag sites also accepted SERVER_PORT === 443,
 * so a host where only the port said HTTPS got Secure session cookies and
 * no Strict-Transport-Security header. The point of converging them was
 * that the two can no longer disagree; this walks the source and keeps it
 * that way, because a seventh copy would restore exactly the same class of
 * bug without failing any behavioural test.
 *
 * bootstrap/ is deliberately out of scope: bootstrap.php is a standalone
 * single-file installer that runs before any of core/ exists on disk and
 * cannot autoload anything from it. It performs no HTTPS detection today;
 * if it ever needs to, it will need its own copy, and that is a different
 * conversation from this one.
 */
class HttpsDetectionConvergenceTest extends TestCase
{
    /**
     * The one file allowed to contain the detection itself.
     */
    private const IMPLEMENTATION = 'core/Http/RequestScheme.php';

    /**
     * Patterns that mean "this file is deciding for itself whether the
     * request is HTTPS". Each is deliberately narrow: the point is to
     * catch a re-implementation, not to ban the words.
     */
    private const FORBIDDEN_PATTERNS = [
        "\$_SERVER['HTTPS']",
        '$_SERVER["HTTPS"]',
        "getServer('HTTPS')",
        'getServer("HTTPS")',
        "\$_SERVER['HTTP_X_FORWARDED_PROTO']",
        "getServer('HTTP_X_FORWARDED_PROTO')",
    ];

    public function testNoFileOutsideRequestSchemeDetectsHttpsItself(): void
    {
        $repoRoot = dirname(__DIR__, 2);
        $offenders = [];
        $files = $this->phpFiles($repoRoot);

        // A scan that walked nothing would pass vacuously — the one way
        // this audit could quietly stop auditing.
        $this->assertGreaterThan(500, count($files), 'the source scan found suspiciously few PHP files');

        foreach ($files as $relativePath) {
            if ($relativePath === self::IMPLEMENTATION) {
                continue;
            }

            $contents = (string) file_get_contents($repoRoot . '/' . $relativePath);
            foreach (self::FORBIDDEN_PATTERNS as $pattern) {
                if (str_contains($contents, $pattern)) {
                    $offenders[] = "{$relativePath} contains {$pattern}";
                }
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "HTTPS detection must go through Core\\Http\\RequestScheme::isHttps() "
            . "(or Request::isHttps()), never a local copy:\n" . implode("\n", $offenders)
        );
    }

    public function testTheSixHistoricalCallSitesAllUseTheHelper(): void
    {
        $repoRoot = dirname(__DIR__, 2);

        $expected = [
            'core/Security/SessionManager.php' => 'RequestScheme::isHttps(',
            'core/Security/LastLoginMethodCookie.php' => 'RequestScheme::isHttps(',
            'core/Cookie/CookieConsentService.php' => 'RequestScheme::isHttps(',
            'core/Http/Response.php' => 'RequestScheme::isHttps(',
            'core/Http/Controller/SetupController.php' => '$request->isHttps()',
            'modules/support_dashboard/src/Controller/StatisticsIntakeController.php' => '$request->isHttps()',
        ];

        foreach ($expected as $relativePath => $needle) {
            $this->assertStringContainsString(
                $needle,
                (string) file_get_contents($repoRoot . '/' . $relativePath),
                "{$relativePath} must resolve the scheme through the shared helper"
            );
        }
    }

    /**
     * @return list<string> repository-relative paths
     */
    private function phpFiles(string $repoRoot): array
    {
        $files = [];

        foreach (['core', 'modules', 'public'] as $directory) {
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($repoRoot . '/' . $directory, \FilesystemIterator::SKIP_DOTS)
            );
            /** @var \SplFileInfo $file */
            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = substr($file->getPathname(), strlen($repoRoot) + 1);
                }
            }
        }

        sort($files);

        return $files;
    }
}
