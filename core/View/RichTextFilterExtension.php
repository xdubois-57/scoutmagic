<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

use Core\Security\HtmlSanitizer;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * The three filters that hand Twig HTML it must NOT escape.
 *
 * They are together because that is the property they share, and it is
 * the one worth reviewing in a single place: each is declared
 * `is_safe => html`, so whatever comes out of it reaches the page
 * verbatim. Each therefore owes the escaping itself — `MarkdownRenderer`
 * and `TextLinker` escape before they build their markup,
 * `HtmlSanitizer` strips to an allowlist — and a fourth filter added
 * here is a decision to trust a fourth piece of code with the same thing.
 *
 * **An extension rather than closures inside `TwigFactory`**, for the
 * reason `DateFilterExtension` gives, and the stake is higher here: a
 * test environment that stubs `sanitized_html` as a passthrough renders
 * a page that would be an injection in production, and answers green.
 * The two that did re-implement it
 * (`Tests\Modules\Banner\Controller\BannerConfigControllerTest`,
 * `Tests\Modules\Leadership\Controller\LeadershipRbacTest`) each
 * called the real `HtmlSanitizer`, so neither lied — they simply each
 * had to know that they must, which is the part that does not survive
 * the allowlist changing (issue #465).
 */
class RichTextFilterExtension extends AbstractExtension
{
    /**
     * @return array<int, TwigFilter>
     */
    public function getFilters(): array
    {
        return [
            // Renders release/commit notes (see Core\View\MarkdownRenderer)
            // as safe HTML instead of raw Markdown syntax.
            new TwigFilter('markdown', [self::class, 'markdown'], ['is_safe' => ['html']]),
            // Free text a member typed, with its URLs as links and its
            // newlines as <br> (Core\View\TextLinker). Escapes first and
            // builds the anchors around the escaped text, so it replaces
            // `|nl2br` on user content rather than being combined with it —
            // `{{ body|autolink }}`, never `{{ body|autolink|nl2br }}`.
            new TwigFilter('autolink', [self::class, 'autolink'], ['is_safe' => ['html']]),
            // Rich text on its way BACK to a form, through the same
            // allowlist that guards it on its way to the database
            // (Core\Security\HtmlSanitizer).
            //
            // `partials/rich_text_form_field.html.twig` renders its value
            // into a contenteditable surface with `|raw`, which is right on
            // the nominal path — the stored value went through the
            // sanitizer before it was stored. It is wrong on the OTHER
            // path: when a validation fails, the form is re-rendered from
            // the raw POST body, which has been through nothing at all. The
            // sanitizing lived on the success branch only, and `|raw`
            // trusted it on both.
            new TwigFilter('sanitized_html', [self::class, 'sanitizedHtml'], ['is_safe' => ['html']]),
        ];
    }

    public static function markdown(?string $text): string
    {
        return MarkdownRenderer::toHtml((string) $text);
    }

    public static function autolink(?string $text): string
    {
        return TextLinker::toHtml($text);
    }

    public static function sanitizedHtml(?string $html): string
    {
        return (new HtmlSanitizer())->sanitize((string) $html);
    }
}
