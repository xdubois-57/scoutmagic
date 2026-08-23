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

    /**
     * The « / » between two crumbs must sit on the text's baseline, not
     * float to the top of the tallest crumb in the row.
     *
     * Bootstrap floats it. That is invisible while every crumb is plain
     * text of the same height — and wrong the moment one is not. A
     * `parents` entry matching a menu renders as a real <button>
     * (partials/breadcrumb_bar.html.twig), and app.css's touch baseline
     * gives every .btn `min-height: 44px` under `(pointer: coarse)`. So
     * on a phone that crumb's <li> is 44px tall next to 24px neighbours,
     * and the separator floated ten pixels above the words it separates.
     *
     * Only a coarse pointer shows it, which is why a desktop review pass
     * and every screenshot taken with a mouse missed it — reported from
     * a real phone instead.
     */
    public function testTheSeparatorDoesNotFloatAboveATallerCrumb(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.breadcrumb-bar \.breadcrumb-item \+ \.breadcrumb-item::before \{\s*float: none;/',
            $this->css,
            'Bootstrap floats the breadcrumb separator; inside this bar it has to stay in the line box.'
        );
    }

    /**
     * And a little air on the separator's left.
     *
     * Bootstrap spaces it symmetrically — 0.5rem on each side, from
     * `--bs-breadcrumb-item-padding-x` — but the glyph is not
     * symmetric: "/" leans right, so its ink sits hard against the crumb
     * before it and drifts away from the one after. The margin goes on
     * the separator rather than into a wider padding on the crumb
     * precisely so the space AFTER it stays Bootstrap's.
     */
    public function testTheSeparatorHasAirOnItsLeft(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.breadcrumb-bar \.breadcrumb-item \+ \.breadcrumb-item::before \{.*?margin-left: 0\.25rem;/s',
            $this->css,
            'The « / » needs a little more room on its left than the glyph leaves it.'
        );
    }

    /**
     * The condition the rule above exists to survive. If the touch
     * baseline ever stops inflating .btn, the separator is no longer at
     * risk — but while it does, the two belong together, and a reader of
     * either should find the other.
     */
    public function testTheTouchBaselineStillInflatesButtons(): void
    {
        $this->assertMatchesRegularExpression(
            '/@media \(pointer: coarse\) \{.*?\.btn \{\s*min-height: 44px;/s',
            $this->css
        );
    }
}
