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
 */
final class MarkdownRenderer
{
    public static function toHtml(string $markdown): string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($markdown)) ?: [];
        $html = '';
        $listOpen = false;
        /** @var string[] $paragraph */
        $paragraph = [];

        $flushParagraph = static function () use (&$paragraph, &$html): void {
            if ($paragraph === []) {
                return;
            }
            $html .= '<p>' . self::inline(implode(' ', $paragraph)) . '</p>';
            $paragraph = [];
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
                continue;
            }

            if (preg_match('/^-{3,}$/', $trimmed) === 1) {
                $flushParagraph();
                $listOpen = $closeList($listOpen);
                $html .= '<hr>';
                continue;
            }

            if (preg_match('/^#{1,6}\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                $listOpen = $closeList($listOpen);
                $html .= '<h6 class="fw-semibold mt-2 mb-1">' . self::inline($m[1]) . '</h6>';
                continue;
            }

            if (preg_match('/^[-*]\s+(.+)$/', $trimmed, $m) === 1) {
                $flushParagraph();
                if (!$listOpen) {
                    $html .= '<ul class="ps-3 mb-2">';
                    $listOpen = true;
                }
                $html .= '<li>' . self::inline($m[1]) . '</li>';
                continue;
            }

            $listOpen = $closeList($listOpen);
            $paragraph[] = $trimmed;
        }

        $flushParagraph();
        $listOpen = $closeList($listOpen);

        return $html;
    }

    /**
     * Bold/italic/inline-code/links within a single block of text already
     * split from structural Markdown (headings, list markers, horizontal
     * rules) — escaped first, so the regexes below only ever reintroduce
     * markup this method itself controls.
     */
    private static function inline(string $text): string
    {
        $escaped = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');

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
