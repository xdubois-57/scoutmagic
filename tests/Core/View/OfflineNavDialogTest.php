<?php

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * public/assets/js/offline-nav.js's three interception layers (Part 2 of
 * the offline-mode work) — no JS test runner in this repo (AGENTS.md), so
 * this reads the raw file to pin down the structural things that matter:
 * capture-phase click/submit listeners, the fetch() wrapper that lets GET
 * through untouched, and the generic dialog markup in base.html.twig.
 */
class OfflineNavDialogTest extends TestCase
{
    private string $navJs;
    private string $baseHtml;

    protected function setUp(): void
    {
        $root = dirname(__DIR__, 3);
        $this->navJs = (string) file_get_contents($root . '/public/assets/js/offline-nav.js');
        $this->baseHtml = (string) file_get_contents($root . '/core/View/templates/base.html.twig');
    }

    public function testClickListenerIsRegisteredOnTheCapturePhase(): void
    {
        $this->assertMatchesRegularExpression(
            "/document\\.addEventListener\\('click', function \\(event\\) \\{.*?\\}, true\\);/s",
            $this->navJs
        );
    }

    public function testSubmitListenerIsRegisteredOnTheCapturePhase(): void
    {
        $this->assertMatchesRegularExpression(
            "/document\\.addEventListener\\('submit', function \\(event\\) \\{.*?\\}, true\\);/s",
            $this->navJs
        );
    }

    public function testSubmitListenerBlocksEveryFormUnconditionallyWithNoWhitelistCheck(): void
    {
        preg_match("/document\\.addEventListener\\('submit', function \\(event\\) \\{(.*?)\\}, true\\);/s", $this->navJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the submit capture listener');

        $this->assertStringContainsString('preventDefault', $m[1]);
        $this->assertStringNotContainsString('isWhitelisted', $m[1]);
    }

    public function testFetchIsWrappedExactlyOnce(): void
    {
        $this->assertSame(1, substr_count($this->navJs, 'window.fetch = function'));
    }

    public function testFetchWrapperLetsGetRequestsThroughUntouched(): void
    {
        preg_match('/window\.fetch = function \(input, init\) \{(.*?originalFetch\.apply\(window, arguments\);)/s', $this->navJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the fetch() wrapper');

        // A GET (the default when no method is given) must reach
        // originalFetch — never the dialog/rejection branch.
        $this->assertStringContainsString("method.toUpperCase() !== 'GET'", $m[1]);
        $this->assertStringContainsString('originalFetch.apply', $m[1]);
    }

    /**
     * The wrapper refuses a non-GET while offline — and asks the right
     * question about « offline ».
     *
     * It used to read `navigator.onLine` directly. Issue #353: on iOS an
     * installed application reports itself offline for a while after the
     * network is back, so that flag alone refused writes on a working
     * connection. `isOffline()` is the same question answered by a probe
     * that actually reached the server, and the flag is only what makes
     * it ask.
     *
     * Asserted in both directions, because the bare flag reappearing
     * here is precisely the regression: it would pass a test that only
     * looked for « some offline check ».
     */
    public function testFetchWrapperRejectsNonGetWhileConfirmedOffline(): void
    {
        preg_match('/window\.fetch = function \(input, init\) \{(.*?originalFetch\.apply\(window, arguments\);)/s', $this->navJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the fetch() wrapper');

        $this->assertStringContainsString('Promise.reject', $m[1]);
        $this->assertStringContainsString('isOffline()', $m[1]);
        $this->assertStringNotContainsString('navigator.onLine', $m[1]);
    }

    /**
     * And no layer reads the browser's flag as a verdict any more.
     *
     * `isOffline()` and `watchConnectivity()` are allowed to — that is
     * where the flag belongs, as the trigger for asking — but a click,
     * a submit or a fetch that consults it directly is issue #353
     * coming back. Counted rather than located, so the rule survives the
     * lines moving around.
     */
    public function testOnlyTheConnectivityVerdictReadsTheBrowsersFlag(): void
    {
        // Code lines only: the flag is discussed at length in the
        // comments around it — that is where the reasoning for issue
        // #353 lives — and counting those would make this test track
        // prose rather than behaviour.
        $reads = 0;
        foreach (explode("\n", $this->navJs) as $line) {
            $trimmed = ltrim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                || str_starts_with($trimmed, '/*')) {
                continue;
            }
            $reads += substr_count($line, 'navigator.onLine');
        }

        $this->assertSame(
            2,
            $reads,
            'navigator.onLine is read in code somewhere new. It is a hint, not a verdict: an '
            . 'installed iOS application reports itself offline while the network is fine, and every '
            . 'layer that acted on it directly is what issue #353 was. Exactly two reads are right — '
            . 'isOffline(), where it opens the question, and watchConnectivity(), where it decides '
            . 'whether to keep asking.'
        );
    }

    public function testChildMatchLogicMirrorsExactlyOneExtraSegment(): void
    {
        $this->assertStringContainsString("entry.match === 'child'", $this->navJs);
        $this->assertStringContainsString("!remainder.includes('/')", $this->navJs);
    }

    public function testAppliedStateNeverForcesTabindex(): void
    {
        $this->assertStringNotContainsString("setAttribute('tabindex'", $this->navJs);
        $this->assertStringNotContainsString('removeAttribute(\'tabindex\'', $this->navJs);
    }

    public function testTwoFrenchMessagesAreDeclared(): void
    {
        $this->assertStringContainsString("n'est pas disponible hors ligne", $this->navJs);
        $this->assertStringContainsString('nécessite une connexion', $this->navJs);
    }

    // --- base.html.twig dialog markup ---

    public function testBaseTemplateDeclaresTheOfflineDialogModal(): void
    {
        $this->assertStringContainsString('id="offline-dialog"', $this->baseHtml);
        $this->assertStringContainsString('id="offline-dialog-message"', $this->baseHtml);
    }

    public function testOfflineDialogHasNoInlineOnclickHandler(): void
    {
        preg_match('/<div class="modal fade" id="offline-dialog".*?<\/div>\s*<\/div>\s*<\/div>/s', $this->baseHtml, $m);
        $this->assertNotEmpty($m, 'Could not locate the offline-dialog modal markup');

        $this->assertStringNotContainsString('onclick', $m[0]);
    }

    public function testBaseTemplateLoadsTheRenamedPrefetchScript(): void
    {
        $this->assertStringContainsString('/assets/js/offline-prefetch.js', $this->baseHtml);
        $this->assertStringNotContainsString('/assets/js/offline-photos.js', $this->baseHtml);
    }
}
