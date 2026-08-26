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
 * Static asset lifetimes in both install layouts — same two-source
 * structure as HtaccessCompressionTest. The far-future tier is only safe
 * because every template reference is versioned (AssetVersioningTest);
 * the shorter tiers cover what vendored CSS references without a
 * version (fonts, CSS-referenced images).
 */
class HtaccessExpiresTest extends TestCase
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
    public function testVersionedTypesGetAYearAndUnversionedOnesDays(string $kind, string $path): void
    {
        $source = $this->source($kind, $path);

        $this->assertStringContainsString('ExpiresByType text/css "access plus 1 year"', $source);
        $this->assertStringContainsString('ExpiresByType application/javascript "access plus 1 year"', $source);
        // Fonts are referenced from inside vendored CSS with a bare URL —
        // a year would pin a stale font for a year after a vendor upgrade.
        $this->assertStringContainsString('ExpiresByType font/woff2 "access plus 7 days"', $source);
        $this->assertStringNotContainsString('ExpiresByType font/woff2 "access plus 1 year"', $source);
    }

    #[DataProvider('htaccessSources')]
    public function testTheExpiresBlockIsGuarded(string $kind, string $path): void
    {
        $this->assertStringContainsString('<IfModule mod_expires.c>', $this->source($kind, $path));
    }

    public function testBothSourcesDeclareTheSameLifetimes(): void
    {
        preg_match_all('/ExpiresByType [^\n]+/', $this->source('file', 'public/.htaccess'), $a);
        preg_match_all('/ExpiresByType [^\n]+/', $this->source('generated', ''), $b);

        $this->assertNotEmpty($a[0]);
        $this->assertSame($a[0], $b[0]);
    }
}
