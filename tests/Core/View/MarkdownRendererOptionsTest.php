<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use Core\View\MarkdownRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The $options parameter added for the contextual help (ARCHITECTURE.md
 * §8.64). Kept OUT of tests/Core/View/MarkdownRendererTest.php on
 * purpose: that file pins the historical no-options behaviour and the
 * chantier's contract is that it does not change by a single line.
 */
class MarkdownRendererOptionsTest extends TestCase
{
    // --- Non-regression: defaults reproduce the historical output ---

    public function testNoOptionsAndEmptyOptionsRenderIdentically(): void
    {
        $markdown = "# Titre\n\n## Sous-titre\n\n- point **fort**\n\n> citation\n\n![img](/assets/img/x.png)\n\nTexte.";

        $this->assertSame(MarkdownRenderer::toHtml($markdown), MarkdownRenderer::toHtml($markdown, []));
    }

    public function testByDefaultEveryHeadingDepthStaysAnH6(): void
    {
        $html = MarkdownRenderer::toHtml("# Un\n\n### Trois\n\n###### Six");

        $this->assertSame(3, substr_count($html, '<h6 class="fw-semibold mt-2 mb-1">'));
        $this->assertStringNotContainsString('<h1', $html);
        $this->assertStringNotContainsString('<h3', $html);
    }

    public function testByDefaultABlockquoteLineStaysAnEscapedParagraph(): void
    {
        $html = MarkdownRenderer::toHtml('> attention');

        $this->assertSame('<p>&gt; attention</p>', $html);
    }

    public function testByDefaultAnImageStaysPlainText(): void
    {
        $html = MarkdownRenderer::toHtml('![logo](/assets/img/logo.png)');

        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('![logo](/assets/img/logo.png)', $html);
    }

    // --- heading_base_level ---

    public function testABaseLevelOfTwoMapsDepthsFromH2AndCapsAtH6(): void
    {
        $html = MarkdownRenderer::toHtml("# Un\n\n## Deux\n\n###### Profond", ['heading_base_level' => 2]);

        $this->assertStringContainsString('<h2 class="fw-semibold mt-2 mb-1">Un</h2>', $html);
        $this->assertStringContainsString('<h3 class="fw-semibold mt-2 mb-1">Deux</h3>', $html);
        // base 2 + 5 extra # = 7, capped at 6.
        $this->assertStringContainsString('<h6 class="fw-semibold mt-2 mb-1">Profond</h6>', $html);
    }

    // --- allow_asset_images ---

    public function testAnAssetImageRendersWhenAllowed(): void
    {
        $html = MarkdownRenderer::toHtml('![Le logo](/assets/img/logo.png)', ['allow_asset_images' => true]);

        $this->assertStringContainsString('<img src="/assets/img/logo.png" alt="Le logo"', $html);
    }

    public function testAnExternalOrNonAssetImageNeverRendersEvenWhenAllowed(): void
    {
        // Never an external URL (CSP + privacy) and never another local
        // path — nothing here may bypass /files/{id}'s FileAccessGuard.
        $external = MarkdownRenderer::toHtml('![x](https://evil.example/pixel.png)', ['allow_asset_images' => true]);
        $files = MarkdownRenderer::toHtml('![x](/files/12)', ['allow_asset_images' => true]);

        $this->assertStringNotContainsString('<img', $external);
        $this->assertStringNotContainsString('<img', $files);
    }

    // --- ordered_lists ---

    public function testByDefaultANumberedLineStaysAPlainParagraph(): void
    {
        $html = MarkdownRenderer::toHtml("1. premier\n2. second");

        $this->assertStringNotContainsString('<ol', $html);
        $this->assertSame('<p>1. premier 2. second</p>', $html);
    }

    public function testConsecutiveNumberedLinesRenderAsAnOrderedListWhenEnabled(): void
    {
        $html = MarkdownRenderer::toHtml("1. premier\n2. second\n\nParagraphe.", ['ordered_lists' => true]);

        $this->assertSame('<ol class="ps-3 mb-2"><li>premier</li><li>second</li></ol><p>Paragraphe.</p>', $html);
    }

    public function testOrderedAndBulletListsCloseEachOther(): void
    {
        $html = MarkdownRenderer::toHtml("1. étape\n- puce", ['ordered_lists' => true]);

        $this->assertSame('<ol class="ps-3 mb-2"><li>étape</li></ol><ul class="ps-3 mb-2"><li>puce</li></ul>', $html);
    }

    // --- wrapped_list_items ---

    public function testByDefaultAnIndentedLineAfterABulletStillClosesTheList(): void
    {
        // Pins the historical behaviour the option exists to not change.
        $html = MarkdownRenderer::toHtml("- puce longue\n  suite de la puce");

        $this->assertSame('<ul class="ps-3 mb-2"><li>puce longue</li></ul><p>suite de la puce</p>', $html);
    }

    public function testAWrappedBulletJoinsIntoOneItemWhenEnabled(): void
    {
        $html = MarkdownRenderer::toHtml(
            "- une puce **en gras\n  sur deux lignes** et sa fin\n- seconde puce",
            ['wrapped_list_items' => true]
        );

        $this->assertSame(
            '<ul class="ps-3 mb-2"><li>une puce <strong>en gras sur deux lignes</strong> et sa fin</li><li>seconde puce</li></ul>',
            $html
        );
    }

    public function testAWrappedNumberedStepStaysOneOrderedListItem(): void
    {
        $html = MarkdownRenderer::toHtml(
            "1. Entrez votre adresse et touchez «\n   Envoyer ».\n2. La page attend.",
            ['ordered_lists' => true, 'wrapped_list_items' => true]
        );

        $this->assertSame(
            '<ol class="ps-3 mb-2"><li>Entrez votre adresse et touchez « Envoyer ».</li><li>La page attend.</li></ol>',
            $html
        );
    }

    public function testAnUnindentedLineStillEndsTheListEvenWhenEnabled(): void
    {
        $html = MarkdownRenderer::toHtml("- puce\nParagraphe non indenté.", ['wrapped_list_items' => true]);

        $this->assertSame('<ul class="ps-3 mb-2"><li>puce</li></ul><p>Paragraphe non indenté.</p>', $html);
    }

    // --- blockquotes ---

    public function testConsecutiveQuoteLinesRenderAsOneBlockquoteWhenEnabled(): void
    {
        $html = MarkdownRenderer::toHtml("> première ligne\n> seconde ligne", ['blockquotes' => true]);

        $this->assertSame('<blockquote>première ligne seconde ligne</blockquote>', $html);
    }

    public function testAQuoteClosesOnABlankLineOrANewBlock(): void
    {
        $html = MarkdownRenderer::toHtml("> avertissement\n\nParagraphe.", ['blockquotes' => true]);

        $this->assertSame('<blockquote>avertissement</blockquote><p>Paragraphe.</p>', $html);
    }

    public function testQuoteContentIsStillEscapedAndInlineFormatted(): void
    {
        $html = MarkdownRenderer::toHtml('> **fort** et <script>', ['blockquotes' => true]);

        $this->assertStringContainsString('<blockquote><strong>fort</strong> et &lt;script&gt;</blockquote>', $html);
    }
}
