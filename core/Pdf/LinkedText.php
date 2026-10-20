<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Pdf;

/**
 * A line of text in which one web address is a real link (issue #977).
 *
 * dompdf turns an `<a href>` into a link annotation (`/URI`) the reader can
 * click, and prints the anchor's text like any other: so the address stays
 * readable on paper and is clickable on screen, which an address escaped
 * as plain text never is.
 *
 * **Everything is escaped, the address included**, and only an `http(s)`
 * address becomes a link: anything else — a `javascript:` URL a setting
 * somehow carried, an empty string — is printed as text and nothing more.
 * The address is linked where the text spells it, and only there; a text
 * that does not contain it is printed unlinked rather than given a link
 * the reader cannot see.
 */
final class LinkedText
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $url
    ) {
    }

    /** The escaped HTML of the line, the address wrapped in `<a href>` when it may be. */
    public function toHtml(): string
    {
        $escaped = htmlspecialchars($this->text, ENT_QUOTES, 'UTF-8');
        $url = $this->url;
        if ($url === null || preg_match('~^https?://[^\s<>"\']+$~i', $url) !== 1 || !str_contains($this->text, $url)) {
            return $escaped;
        }

        $escapedUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
        $position = strpos($escaped, $escapedUrl);
        if ($position === false) {
            return $escaped;
        }

        return substr($escaped, 0, $position)
            . '<a href="' . $escapedUrl . '">' . $escapedUrl . '</a>'
            . substr($escaped, $position + strlen($escapedUrl));
    }
}
