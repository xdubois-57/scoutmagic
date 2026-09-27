<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

use Core\Http\StreamResponseHeaders;

/**
 * The real fetch: `file_get_contents()` + `stream_context_create()`, the
 * same approach as Core\Statistics\StreamStatisticsTransport and
 * Core\Maintenance\GitHubReleaseClient — no new Composer dependency for a
 * developer tool that GETs thirty pages.
 *
 * Unlike FederalScaleLookupService::fetchPage(), redirects ARE followed:
 * a console that sends an anonymous visitor to its sign-in page is alive,
 * and that is only visible at the end of the chain. The checker decides
 * afterwards whether a redirect is acceptable for a given source.
 *
 * `ignore_errors` is on so a 404 comes back as a status rather than as
 * `false`: "the page is gone" and "the host is gone" are different
 * findings, and the report has to say which.
 */
final class StreamPageFetcher implements PageFetcherInterface
{
    private const TIMEOUT_SECONDS = 20;
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const MAX_REDIRECTS = 8;
    private const MAX_BYTES = 4 * 1024 * 1024;

    /**
     * A browser-like identity: a few consoles answer a bare scripted
     * User-Agent with a 403 that says nothing about the page itself. The
     * bot name stays in it so the traffic remains attributable.
     */
    private const USER_AGENT = 'Mozilla/5.0 (compatible; ScoutMagicSourceCheck/1.0; '
        . '+https://github.com/xdubois-57/scoutmagic)';

    public function fetch(string $url): FetchedPage
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => self::MAX_REDIRECTS,
                'ignore_errors' => true,
                'protocol_version' => 1.1,
                'header' => 'User-Agent: ' . self::USER_AGENT . "\r\n"
                    . "Accept: text/html,application/xhtml+xml,*/*;q=0.8\r\n"
                    . "Accept-Language: fr-BE,fr;q=0.9\r\n"
                    . "Connection: close\r\n",
            ],
            'socket' => [
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            ],
        ]);

        $requestUrl = self::withoutFragment($url);

        StreamResponseHeaders::clear();
        $body = @file_get_contents($requestUrl, false, $context, 0, self::MAX_BYTES);
        $headers = StreamResponseHeaders::last();

        if ($headers === []) {
            $error = error_get_last();

            return FetchedPage::unreachable(
                $error !== null ? self::shorten($error['message']) : 'no response'
            );
        }

        return self::fromHeaders($requestUrl, $headers, $body === false ? '' : $body);
    }

    /**
     * Reads the status of the LAST response in the chain and the address
     * the chain ended on. With `follow_location` the stream wrapper
     * concatenates every hop's headers into one list, each hop starting
     * with its own status line.
     *
     * public so the parsing can be tested on a recorded header list.
     *
     * @param list<string> $headers
     */
    public static function fromHeaders(string $url, array $headers, string $body): FetchedPage
    {
        $status = 0;
        $current = $url;
        $redirected = false;
        $permanent = false;

        foreach ($headers as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                $status = (int) $matches[1];
                $permanent = $permanent || $status === 301 || $status === 308;
                continue;
            }
            if ($status >= 300 && $status < 400 && preg_match('/^location:\s*(\S+)/i', $line, $matches) === 1) {
                $current = self::resolve($current, $matches[1]);
                $redirected = true;
            }
        }

        if ($status === 0) {
            return FetchedPage::unreachable('no status line');
        }

        return new FetchedPage($status, $body, $redirected ? $current : null, null, $redirected && $permanent);
    }

    /**
     * A Location header may be absolute, scheme-relative, host-relative
     * or path-relative; the last is resolved against the current
     * directory, which is all a redirect in the wild needs.
     */
    private static function resolve(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }

        $scheme = (string) parse_url($base, PHP_URL_SCHEME);
        $host = (string) parse_url($base, PHP_URL_HOST);
        $port = parse_url($base, PHP_URL_PORT);
        $origin = $scheme . '://' . $host . ($port !== null ? ':' . $port : '');

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = (string) parse_url($base, PHP_URL_PATH);
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1);

        return $origin . ($directory === '' ? '/' : $directory) . $location;
    }

    private static function withoutFragment(string $url): string
    {
        $hash = strpos($url, '#');

        return $hash === false ? $url : substr($url, 0, $hash);
    }

    private static function shorten(string $message): string
    {
        $message = preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $message) ?? $message;

        return mb_substr(trim($message), 0, 200);
    }
}
