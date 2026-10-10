<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * Proposes the text of a link from its address — the « baguette magique »
 * beside « Texte du lien » in every rich-text editor's link dialog
 * (issue #840).
 *
 * The page's Open Graph title first, then its <title>, then — for a page
 * that could not be read, or says nothing about itself — the name of the
 * file the address ends with, which is what a link to a PDF or an image
 * deserves. Null when none of the three exists: the dialog says so, and
 * the visitor types the text.
 *
 * The page is read through Core\Http\LinkPreviewService::metadata(), so it
 * shares the scraper's SSRF rules and its cache (SECURITY.md §17) and never
 * downloads the page's image. Every request counts against a per-account
 * budget (LinkLabelFetchLogRepository), checked before anything leaves the
 * server: the endpoint is what turns a click into an outbound request, and
 * SECURITY.md §17 throttles every such request per person.
 */
class LinkLabelResolver
{
    public const MAX_URL_LENGTH = 2048;

    private const WINDOW_MINUTES = 10;
    private const LIMIT = 30;
    private const RETENTION_MINUTES = 60;

    public function __construct(
        private LinkPreviewService $previews,
        private LinkLabelFetchLogRepository $log
    ) {
    }

    /**
     * Whether $url is an address this resolver will look at: an absolute
     * http(s) URL of reasonable length. Anything else — a mailto:, a path
     * on this site, a typo — has no page to read and no file to name.
     */
    public static function isFetchable(string $url): bool
    {
        if ($url === '' || mb_strlen($url) > self::MAX_URL_LENGTH) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Records the request and says whether this account is still within
     * its budget — recorded first, so a burst at the edge of the window
     * cannot let more than LIMIT requests each see « still under ».
     */
    public function allow(int $userAccountId): bool
    {
        $this->log->purgeOlderThan(self::minutesAgo(self::RETENTION_MINUTES));
        $withinLimit = $this->log->countSince($userAccountId, self::minutesAgo(self::WINDOW_MINUTES)) < self::LIMIT;
        $this->log->record($userAccountId);

        return $withinLimit;
    }

    /**
     * The proposed text for $url, or null when there is none. $url must
     * already have passed isFetchable().
     */
    public function labelFor(string $url): ?string
    {
        $metadata = $this->previews->metadata($url);

        return self::usable($metadata?->ogTitle)
            ?? self::usable($metadata?->htmlTitle)
            ?? self::fileName($url);
    }

    /**
     * The last segment of the address's path, decoded, when it looks like
     * a file name — something with an extension. `/fr/agenda` names a
     * page, not a file, and « agenda » would be a guess rather than the
     * file's own name.
     */
    public static function fileName(string $url): ?string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $segment = rawurldecode(basename($path));
        if (!preg_match('/^.+\.[A-Za-z0-9]{1,5}$/u', $segment)) {
            return null;
        }

        return self::usable($segment);
    }

    private static function usable(?string $text): ?string
    {
        if ($text === null) {
            return null;
        }
        // A title is one line of text: control characters and runs of
        // whitespace go, and so does a title long enough to be a paragraph.
        $text = trim((string) preg_replace('/[\p{Cc}\s]+/u', ' ', $text));

        return $text === '' ? null : mb_substr($text, 0, 200);
    }

    private static function minutesAgo(int $minutes): string
    {
        return (new \DateTimeImmutable('-' . $minutes . ' minutes'))->format('Y-m-d H:i:s');
    }
}
