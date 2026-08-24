<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/css/app.css's .breadcrumb-bar rules — visibility, and the
 * spacing of the « / » between two crumbs. No JS/server-side detection
 * involved (see the partial's own doc comment); this reads the raw CSS
 * structurally, same precedent as BreadcrumbJsTest for the JS side.
 */
class BreadcrumbBarCssTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        $this->css = (string) file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/app.css');
    }

    /**
     * The bar shows at every width now. It used to be hidden at lg and up
     * unless the site ran as an installed PWA, because the desktop nav's
     * permanent sub-menu row already stated where you were; that row is
     * gone (partials/nav.html.twig's mega-menu panels open on click and
     * close again), so this bar is the only thing left on a desktop
     * screen that names the current page's ancestry.
     */
    public function testVisibleAtEveryWidthWithNoMediaQueryGate(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.breadcrumb-bar \{\s*display: flex;\s*\}/',
            $this->css
        );
        $this->assertStringNotContainsString('display-mode: standalone', $this->css);
        $this->assertDoesNotMatchRegularExpression(
            '/@media \(max-width: 991\.98px\) \{\s*\.breadcrumb-bar \{/',
            $this->css
        );
    }

    /**
     * `--has-trail` is still emitted by the template (it marks a bar
     * carrying real ancestor-page links), but it exists only to force
     * visibility the bar now has unconditionally — a duplicate rule
     * kept alive would just be one more thing to keep in sync.
     */
    public function testTheHasTrailModifierNoLongerCarriesAVisibilityRule(): void
    {
        $this->assertStringNotContainsString('.breadcrumb-bar--has-trail {', $this->css);
    }

    /**
     * Bootstrap pads each side of every « / » by 0.5rem — a full rem per
     * separator, which a 375px screen carrying three crumbs cannot
     * afford. The glyph brings its own visual gap. Scoped to this bar so
     * any other breadcrumb keeps Bootstrap's own spacing.
     */
    public function testTheSeparatorSpendsNoHorizontalSpaceOnPadding(): void
    {
        $this->assertMatchesRegularExpression(
            '/\.breadcrumb-bar \.breadcrumb-item \+ \.breadcrumb-item \{\s*padding-left: 0;/',
            $this->css
        );
        $this->assertMatchesRegularExpression(
            '/\.breadcrumb-bar \.breadcrumb-item \+ \.breadcrumb-item::before \{\s*padding-right: 0;/',
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
            '/\.breadcrumb-bar \.breadcrumb-item \+ \.breadcrumb-item::before \{[^}]*float: none;/',
            $this->css,
            'Bootstrap floats the breadcrumb separator; inside this bar it has to stay in the line box.'
        );
    }

    /**
     * And a little air back on the separator's left.
     *
     * The two zeroed paddings above buy width; what they cost is the
     * crumb before the « / » touching it, which is what a phone reported
     * once they shipped. Four pixels go back on that side ALONE: the
     * glyph leans right, so its ink sits hard against the word before it
     * and already carries a gap towards the one after — and giving the
     * space back on both sides would hand the width saving straight
     * back. The three rules are one decision and have to be read
     * together, which is why they sit in one block.
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
