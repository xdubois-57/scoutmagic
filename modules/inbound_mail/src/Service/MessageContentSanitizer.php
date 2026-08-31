<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Service;

use Core\Security\HtmlSanitizer;

/**
 * Turning an email body into something safe to store and render (§7.9).
 *
 * **Applied once, on the way in.** The sanitised form is what gets stored;
 * the sender's original HTML is never written anywhere. Sanitising on
 * render instead would mean every future template, export and API response
 * has to remember to do it, and one of them eventually will not.
 *
 * Two rules beyond ordinary sanitising:
 *
 * - **No remote images, ever.** `HtmlSanitizer` allows `<img>` with an
 *   http(s) `src`, which is right for content the unit wrote about itself
 *   and wrong here: a 1×1 image in a stranger's email is a read receipt,
 *   and loading it tells the sender when a manager opened their message and
 *   from which network. Remote sources are removed rather than proxied —
 *   proxying still fetches them, just from the server.
 * - **`cid:` images are removed too.** They point at parts of the message
 *   the browser cannot resolve, so they would render as broken images even
 *   if they were harmless.
 */
class MessageContentSanitizer
{
    /**
     * Ceilings applied before encryption, and the marker that says one was
     * hit (A6).
     *
     * A body is a `BLOB` on a row a paginated list reads. Several megabytes
     * of quoted history per message — which a long thread reaches without
     * anybody meaning to — makes every page of the general mailbox
     * expensive on the shared MariaDB a unit is hosted on. The HTML ceiling
     * is the larger of the two because markup costs more per word than the
     * words do.
     *
     * The truncation is MARKED rather than silent: a reader who sees the
     * end of a message missing has to be able to tell that ScoutMagic cut
     * it, not that the sender stopped writing.
     */
    public const MAX_TEXT_BYTES = 256 * 1024;
    public const MAX_HTML_BYTES = 512 * 1024;

    public const TEXT_TRUNCATION_MARK = "\n\n[…] Message tronqué : seul son début a été conservé.";
    public const HTML_TRUNCATION_MARK =
        '<p><em>[…] Message tronqué : seul son début a été conservé.</em></p>';

    public function __construct(private HtmlSanitizer $htmlSanitizer)
    {
    }

    public function sanitizeHtml(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Strip images BEFORE sanitising: the sanitizer's allowlist keeps
        // <img src="https://..."> as legitimate content, so removing them
        // afterwards would mean parsing its own output back.
        $withoutImages = preg_replace('/<img\b[^>]*>/i', '', $html) ?? $html;

        // Some clients set a background image on a table cell; same tracking
        // problem, different attribute.
        $withoutImages = preg_replace('/\sbackground\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $withoutImages)
            ?? $withoutImages;

        return $this->truncateHtml($this->htmlSanitizer->sanitize($withoutImages));
    }

    /**
     * Cut AFTER sanitising, never before: cutting first can leave a half
     * written tag that the sanitizer then has to guess at, and the whole
     * point of sanitising is not to hand anybody a guess.
     *
     * The cut is re-sanitised, because slicing well-formed HTML at a byte
     * offset does not produce well-formed HTML — the sanitizer closes what
     * the cut left open.
     */
    private function truncateHtml(string $html): string
    {
        if (strlen($html) <= self::MAX_HTML_BYTES) {
            return $html;
        }

        $cut = mb_strcut($html, 0, self::MAX_HTML_BYTES, 'UTF-8');

        return $this->htmlSanitizer->sanitize($cut) . self::HTML_TRUNCATION_MARK;
    }

    /**
     * The plain-text part, kept as text.
     *
     * Control characters are stripped — a body carrying a NUL or an ANSI
     * escape sequence is either broken or trying something with whatever
     * terminal eventually prints it — but nothing else is rewritten: this
     * is what a consumer scans for a reference, and normalising it would
     * mean a reference the sender typed no longer matching.
     */
    public function sanitizeText(string $text): string
    {
        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        if (!is_string($cleaned)) {
            // An invalid UTF-8 body makes the /u pattern fail outright.
            // Salvage what is valid rather than storing nothing.
            $cleaned = (string) mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        $cleaned = trim($cleaned);

        if (strlen($cleaned) <= self::MAX_TEXT_BYTES) {
            return $cleaned;
        }

        // mb_strcut rather than substr: cutting at a byte offset inside a
        // multi-byte character would store an invalid UTF-8 sequence, and
        // the column is read back by everything from a template to an
        // export.
        return mb_strcut($cleaned, 0, self::MAX_TEXT_BYTES, 'UTF-8') . self::TEXT_TRUNCATION_MARK;
    }
}
