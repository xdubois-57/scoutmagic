<?php

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * Fetches Open Graph metadata (og:title/og:description/og:image) from an
 * external album link — module spec §"External album (OG scraping)".
 * Best-effort only: a failed/slow fetch never blocks saving the album,
 * it just leaves the cached og_* columns null (Service\AlbumService
 * catches GalleryException and proceeds without them).
 */
class OgScraperService
{
    private const TIMEOUT_SECONDS = 5;
    private const MAX_BYTES = 2 * 1024 * 1024;
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

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
     * optional). Returns null on any failure (invalid URL, network error,
     * over MAX_IMAGE_BYTES).
     */
    public function fetchImageBytes(string $url): ?string
    {
        return $this->httpGet($url, self::MAX_IMAGE_BYTES);
    }

    private function httpGet(string $url, int $maxBytes): ?string
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $context = stream_context_create([
            'http' => [
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: Mozilla/5.0 (compatible; ScoutMagicGalleryBot/1.0)\r\n",
                'ignore_errors' => true,
            ],
            'https' => [
                'timeout' => self::TIMEOUT_SECONDS,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => "User-Agent: Mozilla/5.0 (compatible; ScoutMagicGalleryBot/1.0)\r\n",
                'ignore_errors' => true,
            ],
        ]);

        $content = @file_get_contents($url, false, $context, 0, $maxBytes);
        return $content !== false ? $content : null;
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
            $property = $meta->getAttribute('property');
            $content = trim($meta->getAttribute('content'));
            if ($content === '') {
                continue;
            }

            match ($property) {
                'og:title' => $tags['title'] = $content,
                'og:description' => $tags['description'] = $content,
                'og:image' => $tags['image'] = $content,
                default => null,
            };
        }

        return $tags;
    }
}
