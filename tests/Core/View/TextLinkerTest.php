<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\TextLinker;
use PHPUnit\Framework\TestCase;

/**
 * Free text a member typed, rendered with its URLs as links.
 *
 * Half of these tests are about what must NOT come out: this filter is
 * the one place in the codebase that deliberately emits markup built
 * around something a member wrote, so "escaped first, linked second" is
 * not a detail of the implementation but the property under test.
 */
class TextLinkerTest extends TestCase
{
    public function testLinksAUrlAndLeavesTheSentenceAroundItAlone(): void
    {
        $html = TextLinker::toHtml('Voir https://example.org/page pour la suite');

        $this->assertStringContainsString('Voir <a href="https://example.org/page"', $html);
        $this->assertStringContainsString('>https://example.org/page</a> pour la suite', $html);
    }

    public function testLinksEveryUrlAndNotOnlyTheFirst(): void
    {
        $html = TextLinker::toHtml('https://a.test/x et http://b.test/y');

        $this->assertSame(2, substr_count($html, '<a href='));
    }

    /**
     * A link opening in the same tab loses the conversation; one opening
     * in a new one must not hand the destination a window handle back to
     * it, nor a referrer, nor any ranking — this is the open web, reached
     * from user-generated content.
     */
    public function testEveryLinkIsSafeToFollow(): void
    {
        $html = TextLinker::toHtml('https://example.org/page');

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="nofollow ugc noopener noreferrer"', $html);
    }

    // ---- What must never come out ----

    public function testMarkupInTheTextIsEscapedAndNeverRendered(): void
    {
        $html = TextLinker::toHtml('<img src=x onerror=alert(1)> bonjour');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $html);
    }

    /**
     * The difference between a linkifier and a cross-site-scripting
     * vector. Neither scheme may ever become an href.
     */
    public function testAJavascriptOrDataUrlIsLeftAsPlainText(): void
    {
        $html = TextLinker::toHtml('javascript:alert(1) puis data:text/html,x');

        $this->assertStringNotContainsString('<a href=', $html);
        $this->assertStringContainsString('javascript:alert(1)', $html);
    }

    /**
     * A quote that closed the href attribute early would let the rest of
     * the URL become attributes of the anchor — the classic way a
     * linkifier is turned against the page it is rendering.
     */
    public function testAQuoteInsideAUrlCannotEscapeTheHrefAttribute(): void
    {
        $html = TextLinker::toHtml('https://example.org/"onmouseover="alert(1)');

        $this->assertStringNotContainsString('onmouseover="alert(1)"', $html);
        $this->assertStringContainsString('&quot;onmouseover=&quot;', $html);
    }

    // ---- Reading it back ----

    /**
     * Trailing punctuation belongs to the sentence, not to the link — and
     * still has to be rendered, outside the anchor.
     */
    public function testTrailingSentencePunctuationStaysOutsideTheLink(): void
    {
        $html = TextLinker::toHtml('Voici (https://example.org/a), et https://example.org/b.');

        $this->assertStringContainsString('>https://example.org/a</a>)', $html);
        $this->assertStringContainsString('>https://example.org/b</a>.', $html);
    }

    /**
     * A pasted link can be three hundred characters of tracking
     * parameters. The anchor still points at all of it — only what is
     * SHOWN is elided, and in the middle, because the host says where a
     * link goes and the tail says which page.
     */
    public function testALongUrlIsElidedInTheMiddleButStillPointsAtTheWholeThing(): void
    {
        $url = 'https://example.org/' . str_repeat('a', 120) . '?utm_source=x';

        $html = TextLinker::toHtml($url);

        $this->assertStringContainsString('href="' . $url . '"', $html);
        $this->assertStringContainsString('…', $html);
        $this->assertStringContainsString('?utm_source=x</a>', $html);
        $this->assertStringNotContainsString('>' . $url . '</a>', $html);
    }

    /**
     * This filter replaces |nl2br on user content rather than being
     * combined with it, so it owns the line breaks too.
     */
    public function testLineBreaksSurviveAsBrTags(): void
    {
        $this->assertSame("un<br>\ndeux", TextLinker::toHtml("un\ndeux"));
    }

    public function testEmptyTextRendersNothingAtAll(): void
    {
        $this->assertSame('', TextLinker::toHtml(''));
        $this->assertSame('', TextLinker::toHtml(null));
    }

    public function testTextWithNoUrlComesBackEscapedAndUnlinked(): void
    {
        $html = TextLinker::toHtml('Rendez-vous à 14h & pas de retard');

        $this->assertSame('Rendez-vous à 14h &amp; pas de retard', $html);
    }
}
