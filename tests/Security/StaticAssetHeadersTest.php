<?php

declare(strict_types=1);

namespace Tests\Security;

use Core\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

if (!defined('BOOTSTRAP_TEST')) {
    define('BOOTSTRAP_TEST', true);
}
require_once dirname(__DIR__, 2) . '/bootstrap/bootstrap.php';

/**
 * SECURITY.md § 9: every response carries the baseline headers. A static
 * file never reaches PHP, so `Core\Http\Response` cannot be the one to
 * put them there — the web server has to, and both of this project's
 * install layouts need saying separately:
 *
 *   - Layout A ("natural") merges `public/`'s contents into the document
 *     root, so `public/.htaccess` IS the root one.
 *   - Layout B ("single-tree") keeps everything under one root and
 *     bootstrap.php writes its own `.htaccess` there
 *     (`bootstrap_htaccess_content()`), which is what forwards a static
 *     request into `public/`.
 *
 * Miss either and half the installed base is uncovered, which is exactly
 * the sort of thing nobody notices: the site works, the pages look
 * right, and only a scanner reading response headers on a `.css` ever
 * says otherwise.
 *
 * The HSTS value is the second thing pinned here. It is written in three
 * places now — Response, and the two .htaccess sources — and three
 * copies of a max-age is three chances for one of them to drift into
 * announcing a different policy than its neighbours.
 *
 * bootstrap/bootstrap.php declares no namespace (it must run standalone,
 * before vendor/autoload.php exists), hence the leading backslash — same
 * as Tests\Bootstrap\BootstrapTest.
 */
class StaticAssetHeadersTest extends TestCase
{
    /**
     * The two files that have to carry the block, and what each is for.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function headerSources(): array
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

    #[DataProvider('headerSources')]
    public function testStaticFilesAreServedWithNosniff(string $kind, string $path): void
    {
        $this->assertStringContainsString(
            'Header always set X-Content-Type-Options "nosniff"',
            $this->source($kind, $path)
        );
    }

    #[DataProvider('headerSources')]
    public function testTheHstsValueMatchesTheOneTheApplicationEmits(string $kind, string $path): void
    {
        $emitted = (new Response(''))->setHttps(true)->getSecurityHeaders()['Strict-Transport-Security'];

        $this->assertStringContainsString(
            'Header always set Strict-Transport-Security "' . $emitted . '"',
            $this->source($kind, $path),
            'the web server and the application must announce the SAME HSTS policy'
        );
    }

    /**
     * `env=HTTPS` is not decoration. Behind a separate TLS terminator
     * mod_ssl sets nothing, and announcing HSTS for an origin whose TLS
     * the server cannot see is how a site locks its own visitors out.
     * The PHP side covers that deployment through
     * Core\Http\RequestScheme's opt-in instead.
     */
    #[DataProvider('headerSources')]
    public function testHstsIsOnlyAnnouncedOverAConnectionTheServerKnowsIsEncrypted(string $kind, string $path): void
    {
        $source = $this->source($kind, $path);

        $this->assertMatchesRegularExpression(
            '/Header always set Strict-Transport-Security "[^"]+" env=HTTPS/',
            $source
        );
    }

    /**
     * Guarded, because mod_headers is not universal on shared hosting
     * and an unguarded `Header` directive is a 500 on every page of a
     * host that lacks it — a far worse outcome than the missing header.
     */
    #[DataProvider('headerSources')]
    public function testTheDirectivesAreGuardedSoAHostWithoutModHeadersStillServes(string $kind, string $path): void
    {
        $this->assertStringContainsString('<IfModule mod_headers.c>', $this->source($kind, $path));
    }

    /**
     * Scoped to static extensions, never applied blanket. A blanket rule
     * would also hit PHP responses and overwrite what the application
     * built for that request — the Content-Security-Policy carries a
     * per-request nonce, and a fixed copy in a config file would both go
     * stale and silently disable the nonce it no longer matches.
     */
    #[DataProvider('headerSources')]
    public function testTheHeadersDoNotApplyToPhpResponses(string $kind, string $path): void
    {
        $source = $this->source($kind, $path);

        $this->assertMatchesRegularExpression('/<FilesMatch "[^"]*css[^"]*">/', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/<FilesMatch "[^"]*\bphp\b[^"]*">\s*\n\s*Header/',
            $source
        );
    }
}
