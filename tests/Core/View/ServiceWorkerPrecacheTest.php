<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/sw.js's APP_SHELL_URLS whitelist (§8.23) — no JS test runner in
 * this repo (AGENTS.md), so this reads the raw file to pin down the one
 * thing that matters structurally: every icon URL base.html.twig can now
 * link (favicons, footer logo, /favicon.ico) is precached alongside the
 * original four PWA sizes, so an offline-installed app never shows a
 * broken favicon/footer logo.
 */
class ServiceWorkerPrecacheTest extends TestCase
{
    private string $swJs;

    protected function setUp(): void
    {
        $this->swJs = (string) file_get_contents(dirname(__DIR__, 3) . '/public/sw.js');
    }

    private function appShellUrlsBlock(): string
    {
        preg_match('/const APP_SHELL_URLS = \[(.*?)\];/s', $this->swJs, $m);
        $this->assertNotEmpty($m, 'Could not locate APP_SHELL_URLS in public/sw.js');
        return $m[1];
    }

    public function testPrecachesEveryOriginalPwaIconSize(): void
    {
        $block = $this->appShellUrlsBlock();

        foreach (['/pwa/icon-192.png', '/pwa/icon-512.png', '/pwa/icon-512-maskable.png', '/pwa/icon-180.png'] as $url) {
            $this->assertStringContainsString("'{$url}'", $block);
        }
    }

    public function testPrecachesTheNewFaviconAndFooterLogoSizes(): void
    {
        $block = $this->appShellUrlsBlock();

        foreach (['/pwa/icon-16.png', '/pwa/icon-32.png', '/pwa/icon-48.png', '/pwa/icon-64.png'] as $url) {
            $this->assertStringContainsString("'{$url}'", $block);
        }
    }

    public function testPrecachesTheFaviconIcoLegacyFallback(): void
    {
        $block = $this->appShellUrlsBlock();

        $this->assertStringContainsString("'/favicon.ico'", $block);
    }

    public function testStillPrecachesTheOfflineFallbackPage(): void
    {
        $block = $this->appShellUrlsBlock();

        $this->assertStringContainsString("'/offline'", $block);
    }
}
