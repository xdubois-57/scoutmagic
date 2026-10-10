<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * The installed app's native pull-to-refresh is switched off only where
 * pull-to-refresh.js replaces it (issue #842). The offline fallback page
 * (pwa/offline.html.twig) loads app.css without the script: a rule scoped
 * on display-mode alone would leave it with no gesture at all.
 */
class PullToRefreshScopeTest extends TestCase
{
    private function read(string $path): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/' . $path);
    }

    public function testTheNativeGestureIsSuppressedOnlyUnderTheReplacementsClass(): void
    {
        $css = $this->read('public/assets/css/app.css');

        $this->assertSame(1, preg_match_all('/overscroll-behavior-y:\s*contain/', $css));
        $this->assertMatchesRegularExpression(
            '/@media \(display-mode: standalone\) \{\s*html\.has-pull-to-refresh,\s*'
                . 'html\.has-pull-to-refresh body \{\s*overscroll-behavior-y: contain;/',
            $css
        );
    }

    public function testThePagesWithTheScriptCarryTheClassAndTheOfflinePageDoesNot(): void
    {
        $base = $this->read('core/View/templates/base.html.twig');
        $offline = $this->read('core/View/templates/pwa/offline.html.twig');

        $this->assertStringContainsString('<html lang="fr" class="has-pull-to-refresh">', $base);
        $this->assertStringContainsString('/assets/js/pull-to-refresh.js', $base);
        $this->assertStringNotContainsString('has-pull-to-refresh', $offline);
        $this->assertStringNotContainsString('pull-to-refresh.js', $offline);
    }
}
