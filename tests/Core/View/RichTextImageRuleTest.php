<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\View;

use PHPUnit\Framework\TestCase;

/**
 * design.md §7.12 — one rule bounds every image inside rich text.
 *
 * Everything a chief writes through a rich-text editor is stored as HTML
 * and printed with `|raw`. Nothing in this site's CSS or in Bootstrap
 * constrains an `<img>` in it — `.img-fluid` is opt-in and no editor here
 * adds it — so a 4000px photo pasted into a news article came out at
 * 4000px and pushed the whole page sideways. The fix is one shared class,
 * `.rich-text`, carried by the containers and bounded once in app.css.
 *
 * A rule like that only holds while every container carries the class,
 * and the failure is invisible until somebody uploads a big enough photo
 * to the one page that lost it — which is exactly the kind of drift a
 * mechanical guard is for. The exclusions are asserted too: a received
 * email's HTML arrives with its own hard-coded widths, and capping its
 * images would degrade a rendering nobody here controls rather than
 * improve it. That is a decision, not an omission, so it is written down
 * where changing it means changing a test.
 */
final class RichTextImageRuleTest extends TestCase
{
    /**
     * Every container the rule covers: template path (repo-relative) =>
     * a fragment of the line that must carry the `rich-text` class.
     *
     * `editable()` is deliberately absent — it is a Twig function, not a
     * template, and it wraps its own output (Core\View\TwigFactory);
     * testEditableWrapsRichTextInTheSharedContainer() covers it.
     *
     * @var array<string, string>
     */
    private const CONTAINERS = [
        // The reported case: a news article's body.
        'modules/news/views/partials/_field.html.twig' => 'news-article-body rich-text',
        // The generic rich-text field preview — banners, the RGPD text,
        // every list item with a rich body.
        'core/View/templates/partials/rich_text_field.html.twig' => 'rich-text-field-preview rich-text',
        'core/View/templates/config/rgpd.html.twig' => 'rich-text-field-preview rich-text',
        // The help pages, and the same content in the side panel.
        'core/View/templates/help/show.html.twig' => 'help-content rich-text',
        'core/View/templates/partials/help_panel.html.twig' => 'help-content rich-text',
        // The public RGPD page, whose whole body is one rich-text block.
        'core/View/templates/pages/rgpd.html.twig' => '<div class="rich-text">{{ rgpd_content|raw }}</div>',
        // A rental's conditions, shown inside the public request form.
        'modules/rental/views/public/request.html.twig' => 'rich-text border rounded',
        // A camp's note.
        'modules/camps/views/camp.html.twig' => 'card-body rich-text',
    ];

    /**
     * The bodies of emails the site RECEIVED, deliberately left alone:
     * template path => the `|raw` print that must NOT be inside a
     * `.rich-text` container.
     *
     * @var array<string, string>
     */
    private const EXCLUDED = [
        'modules/rental/views/management/_communications.html.twig' => '{{ message.bodyHtml|raw }}',
        'modules/camps/views/camp.html.twig' => '{{ message.bodyHtml|raw }}',
        'modules/camps/views/unsorted_mail.html.twig' => '{{ row.message.bodyHtml|raw }}',
    ];

    public function testEveryRichTextContainerCarriesTheSharedClass(): void
    {
        foreach (self::CONTAINERS as $template => $needle) {
            self::assertStringContainsString(
                $needle,
                self::read($template),
                $template . ' must carry the shared .rich-text class (design.md §7.12)'
            );
        }
    }

    public function testReceivedEmailBodiesAreLeftUnconstrained(): void
    {
        foreach (self::EXCLUDED as $template => $print) {
            $source = self::read($template);
            $offset = strpos($source, $print);
            self::assertIsInt($offset, $template . ' no longer prints ' . $print);

            // The element that wraps the print is on the same line or the
            // few before it; 400 characters covers both shapes used here.
            $before = substr($source, max(0, $offset - 400), min(400, $offset));
            self::assertStringNotContainsString(
                'rich-text',
                $before,
                $template . ': a RECEIVED email carries its own widths — capping its images'
                . ' would degrade the rendering, not improve it. Handle it separately if the'
                . ' need is confirmed (design.md §7.12).'
            );
        }
    }

    /**
     * The rule itself, in the one stylesheet every page loads. Both
     * halves matter: the mobile default is what keeps a wide photo inside
     * its column on a phone, and the 992px cap is what stops it reading
     * as a banner on a desktop.
     */
    public function testTheSharedRuleLivesInAppCss(): void
    {
        $css = self::read('public/assets/css/app.css');

        self::assertMatchesRegularExpression(
            '/\.rich-text\s+img\s*\{[^}]*max-width:\s*100%;[^}]*height:\s*auto;[^}]*\}/',
            $css,
            'app.css must bound every image inside a .rich-text container'
        );
        self::assertMatchesRegularExpression(
            '/@media\s*\(min-width:\s*992px\)\s*\{\s*\.rich-text\s+img\s*\{[^}]*max-width:\s*420px;/',
            $css,
            'from 992px up a rich-text image is capped at 420px — the same value as'
            . ' .groups-media-grid (components.css), on purpose'
        );
    }

    /**
     * editable() is the site's other rich-text surface, and the only one
     * with no template of its own: outside configuration mode it used to
     * print the stored HTML bare, straight into whichever page called it.
     * The wrapper lives in the function so a new call site gets the rule
     * without having to know it exists.
     */
    public function testEditableWrapsRichTextInTheSharedContainer(): void
    {
        $source = self::read('core/View/TwigFactory.php');

        self::assertStringContainsString(
            "return '<div class=\"rich-text\">' . \$value . '</div>';",
            $source,
            'editable() must wrap a rich-text block outside configuration mode'
        );
        self::assertStringContainsString(
            '\'<div class="editable-content\' . $richTextClass . \'"',
            $source,
            'editable() must carry the same class in configuration mode'
        );
    }

    private static function read(string $relativePath): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relativePath;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
