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

    public function testFetchWrapperRejectsNonGetWhileOffline(): void
    {
        preg_match('/window\.fetch = function \(input, init\) \{(.*?originalFetch\.apply\(window, arguments\);)/s', $this->navJs, $m);
        $this->assertNotEmpty($m, 'Could not locate the fetch() wrapper');

        $this->assertStringContainsString('Promise.reject', $m[1]);
        $this->assertStringContainsString('navigator.onLine', $m[1]);
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
