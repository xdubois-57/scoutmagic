<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * Reduces a caller-supplied redirect target to a safe **same-site path**, or
 * a fallback when it is anything else.
 *
 * Several flows echo a `return`/`return_url` parameter (or the `Referer`
 * header) straight back into a `Location:` redirect or an `href`. Left
 * unvalidated, `//evil.example` or `https://evil.example` turns that into an
 * open redirect — a phishing primitive that borrows the unit's trusted domain
 * (audit M17). This is the single place that decides what counts as internal.
 *
 * Only a value that is unambiguously a local absolute path is accepted: it
 * must start with a single `/`, must not be scheme-relative (`//host`) or
 * backslash-tricked (`/\host`, which browsers normalise to `//host`), must
 * carry no scheme or host of its own, and must contain no control characters
 * (which could smuggle a header or be parsed inconsistently by the browser).
 * Everything else collapses to the fallback.
 */
final class SafeRedirect
{
    public static function internalPath(string $url, string $fallback = '/'): string
    {
        if ($url === '' || $url[0] !== '/') {
            return $fallback;
        }

        // Scheme-relative ("//host") and backslash variants a browser treats
        // as scheme-relative ("/\host", "/\\host").
        if (str_starts_with($url, '//') || str_starts_with($url, '/\\')) {
            return $fallback;
        }

        // Control characters (CR/LF/tab/NUL …) have no place in a path and are
        // a header-smuggling / parser-confusion vector.
        if (preg_match('/[\x00-\x1f\x7f]/', $url) === 1) {
            return $fallback;
        }

        // Defence in depth: whatever parse_url makes of it must have no host
        // and no scheme of its own.
        $parts = parse_url($url);
        if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) {
            return $fallback;
        }

        return $url;
    }

    /**
     * Reduce a possibly-absolute URL (typically a `Referer` header) to its
     * own path+query and validate that as an internal path. A same-origin
     * referer keeps the user on the page they came from; a cross-origin one
     * can at most redirect within our own site (its host is discarded), never
     * off it — so this is safe even though the header is not trusted.
     */
    public static function internalPathFromUrl(string $url, string $fallback = '/'): string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            return $fallback;
        }

        $query = parse_url($url, PHP_URL_QUERY);
        $candidate = $path . (is_string($query) && $query !== '' ? '?' . $query : '');

        return self::internalPath($candidate, $fallback);
    }
}
