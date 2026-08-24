<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Http\StreamResponseHeaders;

/**
 * Fetches Open Graph metadata (og:title/og:description/og:image) from an
 * external album link — module spec §"External album (OG scraping)".
 * Best-effort only: a failed/slow fetch never blocks saving the album,
 * it just leaves the cached og_* columns null (Service\AlbumService
 * catches GalleryException and proceeds without them).
 *
 * The URL fetched here is always chief-supplied (an album's external_url)
 * or, since the `groups` module started using fetch() too, member-supplied
 * (a link shared in a post) — either way, untrusted input this process is
 * about to make an outbound request to. SECURITY.md's "Outbound HTTP
 * requests to user-supplied URLs" section is the contract this class
 * implements: every resolved IP is checked against private/reserved/
 * multicast ranges before connecting (not just the literal host in the
 * URL — a hostname is only as trustworthy as where DNS currently points
 * it, and every redirect hop gets the exact same check before it is
 * followed, closing the classic "public URL that 302s to
 * http://169.254.169.254/" bypass). Failures are never distinguished from
 * each other outward: every rejection reason (private IP, bad port,
 * credentials in the URL, too many redirects, wrong content type, a
 * genuine network error) collapses to the same null/GalleryException a
 * caller already treats as "could not fetch" — nothing here is a signal
 * an attacker could use to probe internal network layout from outside,
 * and nothing here is ever written to JournalService (a resolved-IP log
 * would itself be the kind of internal-network fingerprinting this class
 * exists to prevent).
 */
class OgScraperService
{
    private const TIMEOUT_SECONDS = 8.0;
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;
    private const MAX_REDIRECTS = 5;
    private const USER_AGENT = 'Mozilla/5.0 (compatible; ScoutMagicGalleryBot/1.0)';

    /**
     * @return array{title: ?string, description: ?string, image: ?string}
     * @throws GalleryException on an invalid URL or a fetch failure
     */
    public function fetch(string $url): array
    {
        $result = $this->fetchChain($url, self::MAX_BYTES);
        if ($result === null || !$this->looksLikeHtml($result['contentType'])) {
            throw new GalleryException("Impossible de récupérer le lien : {$url}");
        }

        return $this->parseOgTags($result['body']);
    }

    /**
     * Best-effort binary download of an og:image URL — never throws, since
     * a failed fetch must never block saving/refreshing the album (same
     * philosophy as fetch() above, just non-throwing since the caller,
     * Service\AlbumService::cacheOgImage(), already treats this as
     * optional). Returns null on any failure (invalid URL, an SSRF-unsafe
     * target, network error, wrong content type, or over MAX_IMAGE_BYTES).
     */
    public function fetchImageBytes(string $url): ?string
    {
        $result = $this->fetchChain($url, self::MAX_IMAGE_BYTES);
        if ($result === null || !$this->looksLikeImage($result['contentType'])) {
            return null;
        }

        return $result['body'];
    }

    /**
     * Follows redirects itself (PHP's own follow_location would connect
     * straight through to wherever a 3xx points without this class ever
     * seeing — let alone re-validating — the new target) under a single
     * budget shared by every hop, so a chain of slow redirects cannot add
     * up to an unbounded wait. Each iteration re-resolves and re-validates
     * the URL exactly like the first hop did — a same-host response is no
     * more trusted than any other.
     *
     * @return array{body: string, contentType: string}|null
     */
    private function fetchChain(string $url, int $maxBytes): ?array
    {
        $deadline = microtime(true) + self::TIMEOUT_SECONDS;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $target = $this->parseAndValidateUrl($url);
            if ($target === null) {
                return null;
            }

            $ip = $this->resolveValidatedIp($target['host']);
            if ($ip === null) {
                return null;
            }

            $response = $this->performRawRequest(
                $target['scheme'],
                $target['host'],
                $ip,
                $target['pathAndQuery'],
                $maxBytes,
                $remaining
            );

            if ($response['status'] === null) {
                return null;
            }

            if (in_array($response['status'], [301, 302, 303, 307, 308], true)) {
                $location = $response['headers']['location'] ?? null;
                if ($location === null || $location === '') {
                    return null;
                }
                $next = $this->resolveRedirectUrl($url, $location);
                if ($next === null) {
                    return null;
                }
                $url = $next;
                continue;
            }

            if ($response['status'] !== 200 || $response['body'] === null) {
                return null;
            }

            return ['body' => $response['body'], 'contentType' => $response['headers']['content-type'] ?? ''];
        }

        // Exhausted MAX_REDIRECTS hops without a final 200/failure — same
        // outcome as any other unreachable target.
        return null;
    }

    /**
     * The only method that actually opens a connection — isolated so
     * tests can substitute a fake chain of responses (redirects, content
     * types, statuses) and exercise fetchChain()'s own validation and
     * hop-cap logic without a real network call. $ip is the
     * already-validated address to connect to; $host is the original,
     * still-untouched hostname, sent as the Host header and, for https,
     * as the TLS SNI/certificate name — connecting to the pinned IP while
     * keeping $host only in these two places (never re-resolved by the
     * stream wrapper itself) is what stops a second, attacker-controlled
     * DNS answer from substituting a different address between the
     * validation above and the actual connection (DNS rebinding).
     *
     * @return array{status: ?int, headers: array<string, string>, body: ?string}
     */
    protected function performRawRequest(
        string $scheme,
        string $host,
        string $ip,
        string $pathAndQuery,
        int $maxBytes,
        float $timeoutSeconds
    ): array {
        $connectHost = str_contains($ip, ':') ? "[{$ip}]" : $ip;
        $connectUrl = $scheme . '://' . $connectHost . $pathAndQuery;

        $httpOptions = [
            'method' => 'GET',
            'timeout' => $timeoutSeconds,
            'follow_location' => 0,
            'header' => "Host: {$host}\r\nUser-Agent: " . self::USER_AGENT . "\r\n",
            'ignore_errors' => true,
            'protocol_version' => 1.1,
        ];
        $sslOptions = [];
        if ($scheme === 'https') {
            // peer_name overrides both the SNI value and the name checked
            // against the certificate, independently of $connectUrl's own
            // (IP-literal) host — this is what lets the connection target
            // the pinned IP while still validating the real site's
            // certificate for $host, exactly as a normal browser
            // connecting to $host would.
            $sslOptions = [
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ];
        }

        $context = stream_context_create(['http' => $httpOptions, 'ssl' => $sslOptions]);
        // Cleared first: the header store is process-wide and sticky, so
        // a connection that fails before any response (refused, reset, TLS
        // handshake rejected) would otherwise report the headers of some
        // earlier, unrelated fetch — and this service's whole job is to
        // decide whether a remote URL answered.
        StreamResponseHeaders::clear();
        $body = @file_get_contents($connectUrl, false, $context, 0, $maxBytes);
        $rawHeaders = StreamResponseHeaders::last();

        if ($body === false || $rawHeaders === []) {
            return ['status' => null, 'headers' => [], 'body' => null];
        }

        [$status, $headers] = $this->parseResponseHeaders($rawHeaders);

        return ['status' => $status, 'headers' => $headers, 'body' => $body];
    }

    /**
     * @param string[] $rawHeaders as the stream wrapper reports them:
     *        the status line, then one "Name: value" entry per header
     * @return array{0: ?int, 1: array<string, string>}
     */
    private function parseResponseHeaders(array $rawHeaders): array
    {
        $statusLine = array_shift($rawHeaders);
        if ($statusLine === null || !preg_match('#^HTTP/\S+\s+(\d{3})#', $statusLine, $m)) {
            return [null, []];
        }

        $headers = [];
        foreach ($rawHeaders as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) {
                continue;
            }
            // A redirect (or any multi-header response) legitimately
            // repeats header names in the raw list — last one
            // wins, matching how a real HTTP client would present a
            // single merged header map.
            $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
        }

        return [(int) $m[1], $headers];
    }

    /**
     * Resolves a Location header against the URL that produced it — a
     * redirect target is very often relative (module spec's real-world
     * example: link shorteners and share pages routinely 302 to a
     * path-only Location).
     */
    private function resolveRedirectUrl(string $baseUrl, string $location): ?string
    {
        if (parse_url($location, PHP_URL_SCHEME) !== null) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if ($base === false || !isset($base['scheme'], $base['host'])) {
            return null;
        }

        $port = isset($base['port']) ? ':' . $base['port'] : '';
        $origin = $base['scheme'] . '://' . $base['host'] . $port;

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $basePath = $base['path'] ?? '/';
        $lastSlash = strrpos($basePath, '/');
        $dir = $lastSlash !== false ? substr($basePath, 0, $lastSlash + 1) : '/';

        return $origin . $dir . $location;
    }

    /**
     * Rejects everything that is not a plain "http(s)://host/path"
     * request to the default port with no embedded credentials, before
     * any DNS lookup or connection is attempted.
     *
     * @return array{scheme: string, host: string, pathAndQuery: string}|null
     */
    private function parseAndValidateUrl(string $url): ?array
    {
        $parts = @parse_url($url);
        if (!is_array($parts)) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        // Credentials embedded in the URL (http://user:pass@host/...) are
        // refused outright rather than stripped and silently ignored — a
        // link that only means what it says with Basic-Auth credentials
        // attached is not one this best-effort scraper should be
        // fetching, and passing them through would let a crafted URL
        // target internal services that treat the userinfo component as
        // an auth bypass or SSRF-oriented trick.
        if (isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $host = $parts['host'] ?? null;
        if (!is_string($host) || $host === '') {
            return null;
        }

        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = $parts['port'] ?? $defaultPort;
        if ($port !== $defaultPort) {
            return null;
        }

        $path = $parts['path'] ?? '/';
        $pathAndQuery = ($path === '' ? '/' : $path) . (isset($parts['query']) ? '?' . $parts['query'] : '');

        return ['scheme' => $scheme, 'host' => $host, 'pathAndQuery' => $pathAndQuery];
    }

    /**
     * protected (like performRawRequest()) so a test double can override
     * just the DNS half — a hostname a test wants treated as "resolves to
     * a public IP" without an actual, possibly network-unavailable-in-CI
     * lookup — while still delegating IP-literal hosts back to this real
     * implementation, which performs no DNS lookup for those anyway.
     *
     * Resolves every A/AAAA record for $host (or validates it directly
     * when it is already an IP literal) and returns one address to
     * connect to — but only when EVERY resolved record is a public
     * address. A hostname that resolves to a mix of public and private
     * addresses is refused entirely rather than picking the "safe" one:
     * which record the network stack actually uses on a subsequent
     * connection is not something this check controls, so a partially
     * private result is treated as fully unsafe.
     */
    protected function resolveValidatedIp(string $host): ?string
    {
        // Shared with the SSRF endpoint guard (Core\Security\SsrfUrlValidator,
        // SECURITY.md §17/§20) so the private-range logic never drifts between
        // the scraper and the M4/M5/M6 endpoint checks. Kept as a protected
        // method (not an inline static call) so tests can still override just
        // the DNS half.
        return \Core\Security\SsrfUrlValidator::resolveHostToPublicIp($host);
    }

    private function looksLikeHtml(string $contentType): bool
    {
        if ($contentType === '') {
            // Some real-world pages omit the header entirely — refusing
            // them outright would be a behaviour change for legitimate
            // URLs, not a security improvement (the body still has to
            // parse as HTML to yield anything).
            return true;
        }

        $normalized = strtolower(trim(explode(';', $contentType)[0]));

        return in_array($normalized, ['text/html', 'application/xhtml+xml'], true);
    }

    private function looksLikeImage(string $contentType): bool
    {
        if ($contentType === '') {
            return true;
        }

        $normalized = strtolower(trim(explode(';', $contentType)[0]));

        return str_starts_with($normalized, 'image/');
    }

    /**
     * Pure HTML → tags parsing, no network involved — public so it can be
     * unit-tested directly against a fixed HTML string (real page content
     * is not something a test can depend on network access for).
     *
     * @return array{title: ?string, description: ?string, image: ?string}
     */
    public function parseOgTags(string $html): array
    {
        $doc = new \DOMDocument();
        // libxml emits warnings for real-world malformed HTML — irrelevant
        // here, we only read <meta> tags.
        libxml_use_internal_errors(true);
        // DOMDocument::loadHTML() defaults to ISO-8859-1 when the document
        // has no <meta charset> (Google Photos' share page is one such
        // page) — a real UTF-8 og:title like "Visite … · Saturday 📸" then
        // decodes as mojibake ("Â·", "ð"...). Prepending a fake XML
        // encoding declaration forces libxml to read the bytes as UTF-8;
        // it's stripped automatically and never appears in the parsed DOM.
        $doc->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_use_internal_errors(false);

        $tags = ['title' => null, 'description' => null, 'image' => null];
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            // Some sites (and every WordPress SEO plugin) emit the OG tags
            // with name= instead of the spec's property=. property wins on
            // an element carrying both — name is a fallback for a missing
            // property, never an override of it.
            $property = $meta->getAttribute('property') !== ''
                ? $meta->getAttribute('property')
                : $meta->getAttribute('name');
            $content = trim($meta->getAttribute('content'));
            if ($content === '') {
                continue;
            }

            match (strtolower($property)) {
                'og:title' => $tags['title'] = $content,
                'og:description' => $tags['description'] = $content,
                'og:image' => $tags['image'] = $content,
                default => null,
            };
        }

        return $tags;
    }
}
