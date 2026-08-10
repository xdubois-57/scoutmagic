<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/css/app.css's .breadcrumb-bar visibility rule — always
 * visible below the site's lg breakpoint (992px, same as
 * nav.html.twig's own d-lg-none/d-none d-lg-block split), and on desktop
 * only when the site runs as an installed PWA (display-mode: standalone).
 * No JS/server-side detection involved (see the partial's own doc
 * comment) — this reads the raw CSS structurally, same precedent as
 * BreadcrumbJsTest for the JS side.
 */
class BreadcrumbBarCssTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        $this->css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/app.css');
    }

    public function testHiddenByDefault(): void
    {
        $start = strpos($this->css, '.breadcrumb-bar {');
        $this->assertNotFalse($start);
        $this->assertStringContainsString('display: none;', substr($this->css, $start, 60));
    }

    public function testVisibleBelowTheLgBreakpoint(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 991\.98px\) \{\s*\.breadcrumb-bar \{\s*display: flex;/',
            $this->css
        );
    }

    public function testVisibleWhenInstalledAsStandalonePwa(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(display-mode: standalone\) \{\s*\.breadcrumb-bar \{\s*display: flex;/',
            $this->css
        );
    }
}
