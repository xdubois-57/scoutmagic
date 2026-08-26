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
 * Static asset lifetimes live in public/assets/.htaccess — that ONE
 * directory, deliberately: an ExpiresByType in the root sources applies
 * per content type, so it also stamped Expires/max-age onto the
 * application's own PHP responses (dynamically generated CSS/JS/images),
 * fighting the Cache-Control each controller sets. The assets directory
 * travels with public/ in both install layouts, so a single file covers
 * Layout A and Layout B alike.
 *
 * The far-future tier is only safe because every template reference is
 * versioned (AssetVersioningTest); the shorter tiers cover what vendored
 * CSS references without a version (fonts, CSS-referenced images).
 */
class HtaccessExpiresTest extends TestCase
{
    private function assetsHtaccess(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/.htaccess');
    }

    public function testVersionedTypesGetAYearAndUnversionedOnesDays(): void
    {
        $source = $this->assetsHtaccess();

        $this->assertStringContainsString('ExpiresByType text/css "access plus 1 year"', $source);
        $this->assertStringContainsString('ExpiresByType application/javascript "access plus 1 year"', $source);
        // Fonts are referenced from inside vendored CSS with a bare URL —
        // a year would pin a stale font for a year after a vendor upgrade.
        $this->assertStringContainsString('ExpiresByType font/woff2 "access plus 7 days"', $source);
        $this->assertStringNotContainsString('ExpiresByType font/woff2 "access plus 1 year"', $source);
        $this->assertStringContainsString('ExpiresByType image/png "access plus 30 days"', $source);
    }

    public function testTheExpiresBlockIsGuarded(): void
    {
        $this->assertStringContainsString('<IfModule mod_expires.c>', $this->assetsHtaccess());
    }

    /**
     * The scoping regression guard: a root-level ExpiresByType would leak
     * onto PHP responses of the same content type. Lifetimes belong to
     * public/assets/.htaccess and nowhere else.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rootSources(): array
    {
        return [
            'public/.htaccess (Layout A, and the second hop of Layout B)' => ['file', 'public/.htaccess'],
            'the root .htaccess bootstrap.php writes (Layout B)' => ['generated', ''],
        ];
    }

    #[DataProvider('rootSources')]
    public function testRootSourcesCarryNoExpiresRules(string $kind, string $path): void
    {
        $source = $kind === 'generated'
            ? \bootstrap_htaccess_content()
            : (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);

        $this->assertStringNotContainsString('ExpiresByType', $source);
        $this->assertStringNotContainsString('mod_expires', $source);
    }
}
