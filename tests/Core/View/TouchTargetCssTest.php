<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/css/app.css's "MOBILE TOUCH-TARGET BASELINE" block
 * (AGENTS.md's 44px minimum tap zone on coarse-pointer devices). Real bug:
 * .form-check-input itself used to get min-width/min-height:44px, which
 * grows the drawn CONTROL (a checkbox became a 44x44 square next to ~14px
 * of label text, a switch an almost-square lozenge instead of a pill) —
 * the 44px rule is about the tappable zone, not the visible box. These
 * assertions read the raw stylesheet (no browser in PHPUnit) to pin the
 * structural shape of the fix; the actual rendered pixel sizes/label
 * centering/tap-anywhere-on-the-label behavior were verified with a
 * throwaway Playwright script against the real files (not committed —
 * this repo has no JS test runner, see AGENTS.md).
 */
class TouchTargetCssTest extends TestCase
{
    private string $css;

    protected function setUp(): void
    {
        $this->css = file_get_contents(dirname(__DIR__, 3) . '/public/assets/css/app.css');
    }

    private function coarsePointerBlock(): string
    {
        preg_match('/@media \(pointer: coarse\) \{(.*?)\n\}\n\n\/\* Restore compact/s', $this->css, $m);
        $this->assertNotEmpty($m, 'Could not locate the @media (pointer: coarse) touch-target block');
        return $m[1];
    }

    private function revertBlock(): string
    {
        preg_match('/@media \(min-width: 992px\) \{(.*?)\n\}/s', $this->css, $m);
        $this->assertNotEmpty($m, 'Could not locate the @media (min-width: 992px) revert block');
        return $m[1];
    }

    public function testFormCheckInputHasNoSizeOverrideOnCoarsePointers(): void
    {
        $block = $this->coarsePointerBlock();

        $this->assertDoesNotMatchRegularExpression(
            '/\.form-check-input\s*\{[^}]*(min-width|min-height)/s',
            $block
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\.form-switch \.form-check-input\s*\{/',
            $block
        );
    }

    public function testFormCheckLabelCarriesTheTapZoneInstead(): void
    {
        $block = $this->coarsePointerBlock();

        $this->assertMatchesRegularExpression(
            '/\.form-check-label\s*\{[^}]*min-height:\s*44px/s',
            $block
        );
        $this->assertMatchesRegularExpression(
            '/\.form-check-label\s*\{[^}]*align-items:\s*center/s',
            $block
        );
    }

    public function testOtherCoarsePointerRulesStillTargetGenuinelyInteractiveElements(): void
    {
        // .btn-sm / .form-control-sm / .form-select-sm / .pagination
        // .page-link / .list-group-item-action are the interactive element
        // itself (a button, an input, a link) — legitimately grown
        // directly, unlike .form-check-input which decorates a label's
        // real click target.
        $block = $this->coarsePointerBlock();

        foreach (['.btn-sm', '.form-control-sm', '.form-select-sm', '.pagination .page-link', '.list-group-item-action'] as $selector) {
            $this->assertStringContainsString($selector, $block);
        }
    }

    public function testRevertBlockStaysConsistentWithWhatTheCoarsePointerBlockActuallyOverrides(): void
    {
        // No orphaned .form-check-input revert now that the coarse-pointer
        // block no longer touches it — and a matching revert for
        // .form-check-label, which now does.
        $revert = $this->revertBlock();

        $this->assertDoesNotMatchRegularExpression('/\.form-check-input\s*\{/', $revert);
        $this->assertMatchesRegularExpression('/\.form-check-label\s*\{[^}]*display:\s*revert/s', $revert);
        $this->assertMatchesRegularExpression('/\.form-check-label\s*\{[^}]*min-height:\s*revert/s', $revert);
    }
}
