<?php

declare(strict_types=1);

namespace Tests\Bootstrap;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('BOOTSTRAP_TEST')) {
    define('BOOTSTRAP_TEST', true);
}
require_once dirname(__DIR__, 2) . '/bootstrap/bootstrap.php';

/**
 * Both install layouts must compress what they serve — same two-source
 * structure as Tests\Security\StaticAssetHeadersTest, and for the same
 * reason: miss either .htaccess and half the installed base ships 348 KB
 * of render-blocking CSS uncompressed without anyone noticing.
 */
class HtaccessCompressionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function htaccessSources(): array
    {
        return [
            'public/.htaccess (Layout A, and the second hop of Layout B)' => ['file', 'public/.htaccess'],
            'the root .htaccess bootstrap.php writes (Layout B)' => ['generated', ''],
        ];
    }

    private function source(string $kind, string $path): string
    {
        if ($kind === 'generated') {
            return \bootstrap_htaccess_content();
        }

        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    #[DataProvider('htaccessSources')]
    public function testCompressionIsEnabledForTextResponses(string $kind, string $path): void
    {
        $source = $this->source($kind, $path);

        $this->assertStringContainsString('AddOutputFilterByType DEFLATE', $source);
        foreach (['text/html', 'text/css', 'application/javascript', 'application/json', 'image/svg+xml'] as $type) {
            $this->assertStringContainsString($type, $source);
        }
    }

    /**
     * Guarded, because mod_deflate is not universal on shared hosting and
     * an unguarded directive is a 500 on every page of a host lacking it.
     */
    #[DataProvider('htaccessSources')]
    public function testTheCompressionBlockIsGuarded(string $kind, string $path): void
    {
        $this->assertStringContainsString('<IfModule mod_deflate.c>', $this->source($kind, $path));
    }

    /**
     * The application streams /files/… with Content-Length and (for
     * gallery video) Range semantics; compressing those would break both
     * for zero gain — WebP, PDF and video are already compressed. The
     * type list must never grow one of them.
     */
    #[DataProvider('htaccessSources')]
    public function testAlreadyCompressedBinaryTypesAreNeverInTheList(string $kind, string $path): void
    {
        preg_match('/AddOutputFilterByType DEFLATE ([^\n]+)/', $this->source($kind, $path), $m);
        $this->assertNotEmpty($m, 'the DEFLATE directive must exist');

        foreach (['image/webp', 'image/png', 'image/jpeg', 'application/pdf', 'video/', 'font/'] as $binaryType) {
            $this->assertStringNotContainsString($binaryType, $m[1]);
        }
    }

    /**
     * The two sources must announce the SAME type list — three copies of
     * a policy is three chances for one to drift (the HSTS pin next door
     * exists for the same reason).
     */
    public function testBothSourcesCompressTheSameTypes(): void
    {
        preg_match('/AddOutputFilterByType DEFLATE ([^\n]+)/', $this->source('file', 'public/.htaccess'), $a);
        preg_match('/AddOutputFilterByType DEFLATE ([^\n]+)/', $this->source('generated', ''), $b);

        $this->assertSame(trim($a[1] ?? ''), trim($b[1] ?? ''));
    }
}
