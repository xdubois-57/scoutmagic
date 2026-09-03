<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Service;

/**
 * Whether a request deserves a write at all.
 *
 * This class is the answer to the one technical unknown of this whole
 * feature — the cost of a database write per page view — and it answers it
 * by not doing most of them. A unit's traffic is overwhelmingly made of
 * things that are not somebody reading a page: the offline manifest poll,
 * the notification badge, image derivatives, a redirect after a form post,
 * a crawler walking the public calendar. None of that is « qu'est-ce qui
 * sert », and none of it is counted.
 *
 * Pure, static, and deliberately free of any dependency: it is called on
 * every request (after the response, but still), and it has to be cheap
 * enough that the answer « no » costs a few string comparisons and no
 * connection, no service, no allocation of note.
 */
final class PageViewPolicy
{
    /**
     * Substrings, lower-cased, matched against `User-Agent`.
     *
     * **This header is READ to decide, and never stored.** That is the
     * whole difference with the site this replaces, which kept a
     * `STATS_USER_AGENT` table nobody ever had a use for. A comparison
     * costs a few microseconds and saves the write entirely, which is why
     * it happens here rather than being filtered out at reading time.
     *
     * The list is short on purpose. It catches what actually reaches a
     * scout unit's site — search engines, social-network link unfurlers,
     * uptime monitors, script runtimes — and makes no attempt to be an
     * exhaustive crawler database, which would be a dependency to keep up
     * to date for a counter nobody reads to three significant figures. A
     * crawler that slips through inflates a page's count; it never makes
     * the site look unused, and it never identifies anyone.
     *
     * @var list<string>
     */
    private const CRAWLER_MARKERS = [
        'bot', 'crawl', 'spider', 'slurp', 'scrap',
        'monitor', 'uptime', 'validator', 'archiver', 'lighthouse',
        'facebookexternalhit', 'whatsapp', 'embedly', 'quora link',
        'curl/', 'wget/', 'node-fetch', 'python-requests',
        'python-urllib', 'go-http-client', 'java/', 'okhttp',
        'headlesschrome', 'phantomjs',
    ];

    /**
     * The four conditions, in the order they are cheapest to check.
     *
     * - **GET only.** A POST is an action, not a page opened; it answers
     *   with a redirect anyway, which the status test below would drop.
     * - **A route this application declares.** `null` means the router
     *   matched nothing — a 404, a probe for `/wp-login.php` — and there
     *   is no pattern to count it under.
     * - **200, HTML, and not a file.** That is what a page is. A JSON
     *   endpoint carries `application/json`, a download carries its own
     *   type and streams from disk, a redirect is a 302 and an error is a
     *   4xx/5xx. An absent `Content-Type` counts as HTML because that is
     *   what Core\Http\Response leaves to PHP's own default, which is
     *   what every page in this application does.
     * - **Not a crawler.**
     *
     * `/api/` is excluded by name as well as by content type: it is the
     * one prefix whose whole purpose is to not be a page, and stating it
     * means a future endpoint that answers HTML for some good reason
     * still does not land in a chief's « pages les plus ouvertes ».
     */
    public static function shouldCount(
        string $method,
        ?string $routePattern,
        int $statusCode,
        ?string $contentType,
        bool $servesAFile,
        string $userAgent
    ): bool {
        if ($method !== 'GET' || $statusCode !== 200 || $servesAFile) {
            return false;
        }

        if ($routePattern === null || $routePattern === '' || str_starts_with($routePattern, '/api/')) {
            return false;
        }

        if ($contentType !== null && !str_starts_with(strtolower(trim($contentType)), 'text/html')) {
            return false;
        }

        return !self::isCrawler($userAgent);
    }

    /**
     * An empty or absent `User-Agent` is treated as a crawler: every real
     * browser sends one, and what does not is a script.
     */
    public static function isCrawler(string $userAgent): bool
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '') {
            return true;
        }

        $needle = strtolower($userAgent);
        foreach (self::CRAWLER_MARKERS as $marker) {
            if (str_contains($needle, $marker)) {
                return true;
            }
        }

        return false;
    }
}
