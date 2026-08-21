<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View;

/**
 * Free text a member typed, rendered with its URLs turned into links.
 *
 * The order matters and is the whole point: the text is escaped FIRST,
 * then anchors are built around the escaped spans. Nothing a member typed
 * ever reaches the page as markup — an "<img onerror>" in a message is
 * still just characters when this is done with it, and the only tags in
 * the output are the ones built here.
 *
 * Only http and https are ever linked. "javascript:", "data:" and friends
 * are left as plain text, which is the difference between a linkifier and
 * a cross-site-scripting vector; the pattern below cannot even match
 * them, and isSafeUrl() refuses anything that slips past it.
 *
 * Every link carries rel="nofollow ugc noopener noreferrer" and
 * target="_blank": this is user-generated content pointing at the open
 * web, so the destination is told nothing about where it was linked from
 * and gains no ranking from it, and it opens beside the conversation
 * rather than navigating away from it.
 */
final class TextLinker
{
    /**
     * How much of a URL is shown before it is elided in the middle. A
     * pasted link can be three hundred characters of tracking parameters,
     * and a message is not improved by a wall of them — the anchor still
     * points at the whole thing, and its title attribute still holds it.
     */
    private const MAX_SHOWN_LENGTH = 60;

    /**
     * Trailing characters a SENTENCE carries but a URL never ends with.
     * Deliberately the same set Modules\Groups\Service\PostLinkService
     * trims, so the link a message shows and the preview card it gets are
     * never one character apart.
     */
    private const SENTENCE_TAIL = ".,;:!?)]}'\"";

    /**
     * Escaped HTML for $text, with its URLs as anchors and its newlines
     * as <br>.
     *
     * Newlines are handled here rather than left to a later `|nl2br`
     * because that composition is a trap worth removing: whether nl2br
     * re-escapes already-safe markup depends on how the value reached it,
     * and getting it wrong either double-escapes every link or, far
     * worse, stops escaping the text around them. One filter, one pass,
     * no ordering to get right.
     */
    public static function toHtml(?string $text): string
    {
        $text = (string) $text;
        if ($text === '') {
            return '';
        }

        $out = '';
        $offset = 0;

        // The same shape Modules\Groups\Service\PostLinkService::firstUrlIn()
        // uses to find "the link" in a sentence, applied to every match
        // instead of the first.
        preg_match_all('/https?:\/\/[^\s<>"\']+/u', $text, $matches, PREG_OFFSET_CAPTURE);

        foreach ($matches[0] as $match) {
            [$raw, $at] = $match;

            // Trailing sentence punctuation belongs to the sentence, not
            // to the link — but it still has to be rendered, so it is put
            // back into the plain-text run after the anchor.
            $url = rtrim($raw, self::SENTENCE_TAIL);
            $out .= self::escape(substr($text, $offset, $at - $offset));
            $offset = $at + strlen($raw);

            if ($url === '' || !self::isSafeUrl($url)) {
                // Not something to link: the characters are still the
                // member's text and are rendered as such.
                $out .= self::escape($raw);
                continue;
            }

            $out .= '<a href="' . self::escape($url) . '"'
                . ' target="_blank" rel="nofollow ugc noopener noreferrer"'
                . ' title="' . self::escape($url) . '">'
                . self::escape(self::shorten($url))
                . '</a>';
            $out .= self::escape(substr($raw, strlen($url)));
        }

        $out .= self::escape(substr($text, $offset));

        return nl2br($out, false);
    }

    /**
     * http(s) with a real host, and nothing else. `filter_var` alone
     * accepts a scheme this must never emit, so the scheme is checked
     * explicitly rather than inferred.
     */
    private static function isSafeUrl(string $url): bool
    {
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        if ($scheme !== 'http' && $scheme !== 'https') {
            return false;
        }

        return ((string) parse_url($url, PHP_URL_HOST)) !== ''
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Elided in the MIDDLE, never at the end: the host is what tells a
     * reader where a link goes, and the tail of a path is what tells them
     * which page — a plain "…" after 60 characters would keep the least
     * useful half of both.
     */
    private static function shorten(string $url): string
    {
        if (mb_strlen($url) <= self::MAX_SHOWN_LENGTH) {
            return $url;
        }

        $head = (int) floor((self::MAX_SHOWN_LENGTH - 1) * 0.7);
        $tail = self::MAX_SHOWN_LENGTH - 1 - $head;

        return mb_substr($url, 0, $head) . '…' . mb_substr($url, -$tail);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
