<?php

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

class MarkdownRendererTest extends TestCase
{
    public function testRendersHeadingsBoldInlineCodeAndParagraphs(): void
    {
        $html = MarkdownRenderer::toHtml("## Résumé\n\nCorrige `--skip-deployment-check` et **améliore** la détection.");

        $this->assertStringContainsString('<h6 class="fw-semibold mt-2 mb-1">Résumé</h6>', $html);
        $this->assertStringContainsString('<code>--skip-deployment-check</code>', $html);
        $this->assertStringContainsString('<strong>améliore</strong>', $html);
        $this->assertStringContainsString('<p>Corrige', $html);
    }

    public function testRendersBulletListsAndHorizontalRules(): void
    {
        $html = MarkdownRenderer::toHtml("- **Sécurité** : vérifié\n- **Tests** : vérifié\n\n---\n\n## Fin");

        $this->assertStringContainsString('<ul class="ps-3 mb-2"><li><strong>Sécurité</strong> : vérifié</li><li><strong>Tests</strong> : vérifié</li></ul>', $html);
        $this->assertStringContainsString('<hr>', $html);
        $this->assertStringContainsString('<h6 class="fw-semibold mt-2 mb-1">Fin</h6>', $html);
    }

    public function testRendersMarkdownLinksWithHttpsSchemeOnly(): void
    {
        $html = MarkdownRenderer::toHtml('Voir [la release](https://github.com/x/y/releases/tag/v1.0.0).');

        $this->assertStringContainsString('<a href="https://github.com/x/y/releases/tag/v1.0.0" target="_blank" rel="noopener">la release</a>', $html);
    }

    public function testDoesNotLinkifyANonHttpScheme(): void
    {
        $html = MarkdownRenderer::toHtml('Voir [ceci](javascript:alert(1)).');

        $this->assertStringNotContainsString('<a href="javascript:', $html);
    }

    /**
     * Every line is HTML-escaped before any Markdown markup is
     * reintroduced (Core\View\MarkdownRenderer's own docblock) — a plain
     * commit message containing raw HTML-looking text must never end up
     * unescaped in the output, regardless of who pushed the commit.
     */
    public function testEscapesRawHtmlInTheSource(): void
    {
        $html = MarkdownRenderer::toHtml('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRendersAPlainCommitMessageAsOrdinaryParagraphs(): void
    {
        $html = MarkdownRenderer::toHtml("Corrige la pagination du Trombinoscope\n\nDétails de la correction.");

        $this->assertSame('<p>Corrige la pagination du Trombinoscope</p><p>Détails de la correction.</p>', $html);
    }

    public function testReturnsEmptyStringForEmptyInput(): void
    {
        $this->assertSame('', MarkdownRenderer::toHtml(''));
        $this->assertSame('', MarkdownRenderer::toHtml("   \n  "));
    }
}
