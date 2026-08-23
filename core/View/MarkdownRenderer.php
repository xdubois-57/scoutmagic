<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

/**
 * Renders the narrow Markdown subset actually produced by the two sources
 * shown on Configuration > Maintenance: GitHub's own `--generate-notes`
 * output and the "Résumé/Nouveautés/Corrections de bugs/Compatibilité
 * ascendante/…/Vérifications effectuées" structure `scripts/release.sh`
 * always appends (AGENTS.md "Releases") — headings, bold, inline code,
 * bullet lists, horizontal rules, paragraphs, and `[text](url)` links —
 * plus, for the development channel, a plain commit message (which this
 * renders as ordinary paragraphs, since it carries no Markdown at all).
 * Not a general-purpose CommonMark implementation (ARCHITECTURE.md §1:
 * "only a small, explicitly justified set of external dependencies is
 * allowed" — a full parser isn't worth a new Composer dependency for
 * this one, well-defined need).
 *
 * Every line is HTML-escaped before any Markdown markup is reintroduced,
 * so the output is safe to print unescaped regardless of what the source
 * (a GitHub release body, ultimately admin/maintainer-authored, or a
 * commit message from anyone with push access) contains.
 *
 * The optional $options parameter exists for the contextual help pages
 * (ARCHITECTURE.md §8.64), whose topics are longer documents than release
 * notes. Its defaults reproduce the historical behaviour EXACTLY — every
 * pre-existing caller (MaintenanceController, the `markdown` Twig filter)
 * keeps rendering byte-identical output, pinned by
 * tests/Core/View/MarkdownRendererOptionsTest.php:
 *
 * - 'heading_base_level' (int, default 6): the <hN> a single-# heading
 *   maps to; each extra # goes one level deeper, capped at <h6>. The
 *   default 6 is the historical "all headings are <h6>" flattening; help
 *   pages pass 2 (their <h1> is the topic title, outside the Markdown).
 * - 'allow_asset_images' (bool, default false): renders ![alt](src) as an
 *   <img>, but ONLY for a src under /assets/ — never an external URL
 *   (the CSP would block it, and a remote image is a privacy leak) and
 *   never any other local path (nothing here may bypass /files/{id}'s
 *   FileAccessGuard). Off by default: release notes have no business
 *   embedding images.
 * - 'blockquotes' (bool, default false): renders consecutive `> ` lines
 *   as one <blockquote> — the help charter's single warning callout per
 *   topic (design.md §7.11). Off by default because a release note
 *   containing a literal `>` line has always rendered it as plain text,
 *   and the no-options output must stay byte-identical.
 */
final class MarkdownRenderer
{
    /**
     * @param array{heading_base_level?: int, allow_asset_images?: bool, blockquotes?: bool} $options
     */
    public static function toHtml(string $markdown, array $options = []): string
    {
        $headingBaseLevel = $options['heading_base_level'] ?? 6;
        $allowAssetImages = $options['allow_asset_images'] ?? false;
        $renderBlockquotes = $options['blockquotes'] ?? false;
        $lines = preg_split('/\r\n|\r|\n/', trim($markdown)) ?: [];
        $html = '';
        $listOpen = false;
        /** @var string[] $paragraph */
        $paragraph = [];
        /** @var string[] $quote */
        $quote = [];

        $flushParagraph = static function () use (&$paragraph, &$html, $allowAssetImages): void {
            if ($paragraph === []) {
                return;
            }
            $html .= '<p>' . self::inline(implode(' ', $paragraph), $allowAssetImages) . '</p>';
            $paragraph = [];
        };
        $flushQuote = static function () use (&$quote, &$html, $allowAssetImages): void {
            if ($quote === []) {
                return;
            }
            $html .= '<blockquote>' . self::inline(implode(' ', $quote), $allowAssetImages) . '</blockquote>';
            $quote = [];
        };
        // Takes and returns the flag rather than capturing it by
        // reference: a by-ref bool captured before it is ever set to true
        // reads as permanently false to static analysis, which flagged the
        // `if` below as dead code. Passing it through keeps the same
        // behaviour and stays honest about the state being threaded.
        $closeList = static function (bool $listOpen) use (&$html): bool {
            if ($listOpen) {
                $html .= '</ul>';
            }

            return false;
        };

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($trimmed === '') {
                $flushParagraph();
                $flushQuote();
                continue;
            }

            if ($renderBlockquotes && preg_match('/^>\s*(.*)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $listOpen = $closeList($listOpen);
                if ($m[1] !== '') {
                    $quote[] = $m[1];
                }
                continue;
            }

            if (preg_match('/^-{3,}$/', $trimmed) === 1) {
                $flushParagraph();
                $flushQuote();
                $listOpen = $closeList($listOpen);
                $html .= '<hr>';
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $flushQuote();
                $listOpen = $closeList($listOpen);
                // With the default base level 6 every depth flattens to
                // <h6> — the historical behaviour, byte for byte. A lower
                // base (help pages use 2) maps # to <h{base}> and each
                // extra # one level deeper, never past <h6>.
                $level = min(6, $headingBaseLevel + strlen($m[1]) - 1);
                $html .= '<h' . $level . ' class="fw-semibold mt-2 mb-1">' . self::inline($m[2], $allowAssetImages) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $flushQuote();
                if (!$listOpen) {
                    $html .= '<ul class="ps-3 mb-2">';
                    $listOpen = true;
                }
                $html .= '<li>' . self::inline($m[1], $allowAssetImages) . '</li>';
                continue;
            }

            $listOpen = $closeList($listOpen);
            $flushQuote();
            $paragraph[] = $trimmed;
        }

        $flushParagraph();
        $flushQuote();
        $listOpen = $closeList($listOpen);

        return $html;
    }

    /**
     * Bold/italic/inline-code/links within a single block of text already
     * split from structural Markdown (headings, list markers, horizontal
     * rules) — escaped first, so the regexes below only ever reintroduce
     * markup this method itself controls.
     */
    private static function inline(string $text, bool $allowAssetImages = false): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

        // ![alt](/assets/…) — opt-in (help topics only), and only for a
        // path under /assets/: never an external URL (CSP + privacy —
        // design.md §7.11) and never any other local path, so nothing
        // rendered here can bypass /files/{id}'s FileAccessGuard. Runs
        // before the link rule so the two bracket syntaxes never overlap.
        if ($allowAssetImages) {
            $escaped = (string) preg_replace_callback(
                '/!\[([^\]]*)\]\((\/assets\/[^\s)]+)\)/',
                static fn (array $m): string => '<img src="' . $m[2] . '" alt="' . $m[1] . '" class="img-fluid rounded my-2">',
                $escaped
            );
        }

        // [text](https://…) — http(s) only, so a stray "javascript:" or
        // similar in a commit message can never become a clickable link.
        $escaped = (string) preg_replace_callback(
            '/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/i',
            static fn (array $m): string => '<a href="' . $m[2] . '" target="_blank" rel="noopener">' . $m[1] . '</a>',
            $escaped
        );

        $escaped = (string) preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $escaped);
        $escaped = (string) preg_replace('/(?<!\*)\*([^*\s][^*]*?)\*(?!\*)/', '<em>$1</em>', $escaped);
        $escaped = (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', $escaped);

        return $escaped;
    }
}
