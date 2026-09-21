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
 * The two web-server facts the CardDAV server (ARCHITECTURE.md §8.118)
 * depends on, in both install layouts.
 *
 * Neither produces an error anybody can read when it is wrong. A
 * stripped `Authorization` header answers 401 forever with correct
 * credentials; a denied `/.well-known/` answers 403 to the autodiscovery
 * every client starts with. Both look, from a phone, exactly like a
 * wrong password — which is why they are pinned here rather than left to
 * be rediscovered on somebody's hosting.
 *
 * Same two-source structure as the compression pin next door, and for
 * the same reason: miss either `.htaccess` and half the installed base
 * has a feature that silently does not work.
 */
class HtaccessAuthorizationTest extends TestCase
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
            return \bootstrapHtaccessContent();
        }

        return (string) file_get_contents(dirname(__DIR__, 2) . '/' . $path);
    }

    /**
     * Under CGI and FastCGI — most shared hosting, which is this
     * project's target — Apache strips `Authorization` before PHP sees
     * it. A CardDAV client speaks HTTP Basic and nothing else, so
     * without this copy every synchronisation answers 401 with correct
     * credentials and nothing anywhere says why.
     */
    #[DataProvider('htaccessSources')]
    public function testTheAuthorizationHeaderIsPassedThroughToPhp(string $kind, string $path): void
    {
        $source = $this->source($kind, $path);

        $this->assertStringContainsString('RewriteCond %{HTTP:Authorization} .', $source);
        $this->assertStringContainsString(
            'RewriteRule ^ - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]',
            $source
        );
    }

    /**
     * Both sources must spell it the same way. Two copies of a rule is
     * two chances for one to drift, and the one that drifts is the one
     * nobody is running locally.
     */
    public function testBothSourcesPassTheHeaderThroughIdentically(): void
    {
        $pattern = '/RewriteCond %\{HTTP:Authorization\} \.\s*\n'
            . 'RewriteRule \^ - \[E=HTTP_AUTHORIZATION:%\{HTTP:Authorization\}\]/';

        $this->assertMatchesRegularExpression($pattern, $this->source('file', 'public/.htaccess'));
        $this->assertMatchesRegularExpression($pattern, $this->source('generated', ''));
    }

    /**
     * The single-tree layout denies dotfiles at any depth, which is
     * right — and `/.well-known/` is not one. It is a standardised
     * public namespace (RFC 8615) that merely looks like a dotfile, and
     * it carries CardDAV autodiscovery as well as ACME's challenge
     * directory.
     *
     * The deny rule itself must survive: this asserts the exception, not
     * its removal.
     */
    public function testTheDotfileDenyExceptsTheWellKnownNamespace(): void
    {
        $source = $this->source('generated', '');

        $this->assertStringContainsString('RewriteRule (^|/)\\. - [F,L]', $source);
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !^/\\.well-known/', $source);

        // And the exception must come immediately before the rule it
        // excepts: a RewriteCond applies only to the next RewriteRule,
        // so one placed anywhere else is a comment with extra steps.
        $this->assertMatchesRegularExpression(
            '/RewriteCond %\{REQUEST_URI\} !\^\/\\\\\.well-known\/\s*\nRewriteRule \(\^\|\/\)\\\\\. - \[F,L\]/',
            $source
        );
    }

    /**
     * Layout A serves from `public/`, whose own `.htaccess` has never
     * denied dotfiles — so there is nothing to except there, and a test
     * asserting an exception would be asserting a rule that does not
     * exist.
     */
    public function testTheServedDirectoryNeverDeniedDotfilesInTheFirstPlace(): void
    {
        $this->assertStringNotContainsString(
            'RewriteRule (^|/)\\. - [F,L]',
            $this->source('file', 'public/.htaccess')
        );
    }
}
