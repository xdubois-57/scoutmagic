<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * Fetches Open Graph metadata (og:title/og:description/og:image) from an
 * external album link — module spec §"External album (OG scraping)".
 * Best-effort only: a failed/slow fetch never blocks saving the album,
 * it just leaves the cached og_* columns null (Service\AlbumService
 * catches GalleryException and proceeds without them).
 *
 * The URL is chief-supplied, so every fetch is a potential SSRF vector:
 * the server would otherwise happily GET a cloud metadata endpoint or an
 * internal admin panel and surface part of the response (og:title/
 * og:description, plus the og:image bytes, cached and served via
 * /files/{id}). isFetchableUrl() below is the guard, and redirects are
 * followed by hand precisely so every hop goes through it too.
 */
class OgScraperService
{
    private const TIMEOUT_SECONDS = 5;
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
        $html = $this->httpGet($url, self::MAX_BYTES);
        if ($html === null) {
            throw new GalleryException("Impossible de récupérer le lien : {$url}");
        }

        return $this->parseOgTags($html);
    }

    /**
     * Best-effort binary download of an og:image URL — never throws, since
     * a failed fetch must never block saving/refreshing the album (same
     * philosophy as fetch() above, just non-throwing since the caller,
     * Service\AlbumService::cacheOgImage(), already treats this as
     * optional). Returns null on any failure (invalid URL, blocked target,
     * network error, over MAX_IMAGE_BYTES).
     */
    public function fetchImageBytes(string $url): ?string
    {
        return $this->httpGet($url, self::MAX_IMAGE_BYTES);
    }

    /**
     * Whether $url is safe for the server to fetch on a user's behalf:
     * an http(s) URL whose host resolves exclusively to public unicast
     * addresses. Public so the guard itself is directly unit-testable
     * against literal addresses, with no network involved — same reasoning
     * as parseOgTags() below.
     *
     * A host that cannot be resolved at all is refused rather than
     * attempted: "we could not verify where this points" is not a reason to
     * go ahead. Note this cannot close the DNS-rebinding window on its own
     * (the name is resolved again by the actual request); pinning the
     * resolved IP would mean sending a bare-IP request with a spoofed Host
     * header, which breaks TLS verification — a worse trade for a
     * best-effort preview scrape.
     */
    public function isFetchableUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        if (!in_array(strtolower($parts['scheme']), ['http', 'https'], true)) {
            return false;
        }

        $host = trim($parts['host'], '[]');
        if ($host === '') {
            return false;
        }

        $addresses = $this->resolveHost($host);
        if ($addresses === []) {
            return false;
        }

        foreach ($addresses as $address) {
            if (!$this->isPublicAddress($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return string[] every A/AAAA address $host resolves to, or the
     *                  literal address when $host already is one
     */
    private function resolveHost(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                foreach (['ip', 'ipv6'] as $key) {
                    if (isset($record[$key]) && is_string($record[$key])) {
                        $addresses[] = $record[$key];
                    }
                }
            }
        }

        if ($addresses === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $addresses = $ipv4;
            }
        }

        return array_values(array_unique($addresses));
    }

    /**
     * Rejects loopback, private, link-local (incl. the 169.254.169.254 cloud
     * metadata endpoint), and every other reserved range, plus IPv4-mapped
     * IPv6 forms of the same, which FILTER_FLAG_NO_* does not catch.
     */
    private function isPublicAddress(string $address): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $matches) === 1) {
            $address = $matches[1];
        }

        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Follows up to MAX_REDIRECTS hops manually — the stream wrapper's own
     * follow_location would jump to the Location header without ever handing
     * it back for validation, which is exactly how an allowed public URL
     * redirects its way to 169.254.169.254.
     */
    private function httpGet(string $url, int $maxBytes): ?string
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
            if (!$this->isFetchableUrl($current)) {
                return null;
            }

            $redirectTarget = null;
            $body = $this->fetchOnce($current, $maxBytes, $redirectTarget);

            if ($redirectTarget === null) {
                return $body;
            }

            $next = $this->resolveRedirect($current, $redirectTarget);
            if ($next === null) {
                return null;
            }
            $current = $next;
        }

        return null;
    }

    /**
     * One single request, no redirect following. $redirectTarget is set to
     * the raw Location header when the response is a 3xx, and left null
     * otherwise.
     */
    private function fetchOnce(string $url, int $maxBytes, ?string &$redirectTarget): ?string
    {
        $options = [
            'timeout' => self::TIMEOUT_SECONDS,
            'follow_location' => 0,
            'max_redirects' => 1,
            'header' => 'User-Agent: ' . self::USER_AGENT . "\r\n",
            'ignore_errors' => true,
        ];
        $context = stream_context_create(['http' => $options, 'https' => $options]);

        $content = @file_get_contents($url, false, $context, 0, $maxBytes);
        // Set by the http/https stream wrapper in this function's own scope,
        // and absent entirely when the request never got a response.
        $headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];

        if ($this->isRedirect($headers)) {
            $redirectTarget = $this->locationHeader($headers);
            if ($redirectTarget !== null) {
                return null;
            }
        }

        return $content !== false ? $content : null;
    }

    /**
     * @param array<int, mixed> $headers
     */
    private function isRedirect(array $headers): bool
    {
        $status = null;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                // Last status line wins: it belongs to the response actually
                // held, whatever came before it.
                $status = (int) $matches[1];
            }
        }

        return $status !== null && $status >= 300 && $status < 400;
    }

    /**
     * @param array<int, mixed> $headers
     */
    private function locationHeader(array $headers): ?string
    {
        $location = null;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('/^Location:\s*(.+)$/i', trim($header), $matches) === 1) {
                $location = trim($matches[1]);
            }
        }

        return $location !== null && $location !== '' ? $location : null;
    }

    /**
     * Resolves a possibly-relative Location against the URL it came from.
     * Only http(s) absolute results are accepted — isFetchableUrl() re-checks
     * the result anyway, this just avoids building nonsense for it.
     */
    private function resolveRedirect(string $base, string $location): ?string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }

        $parts = parse_url($base);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $basePath = $parts['path'] ?? '/';
        $directory = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);
        return $origin . ($directory !== '' ? $directory : '/') . $location;
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
            // with name= instead of the spec's property=.
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
