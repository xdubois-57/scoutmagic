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

    /**
     * @return array{title: ?string, description: ?string, image: ?string}
     * @throws GalleryException on an invalid URL or a fetch failure
     */
    public function fetch(string $url): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new GalleryException('URL invalide.');
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

        $html = @file_get_contents($url, false, $context, 0, self::MAX_BYTES);
        if ($html === false) {
            throw new GalleryException("Impossible de récupérer le lien : {$url}");
        }

        return $this->parseOgTags($html);
    }

    /**
     * @return array{title: ?string, description: ?string, image: ?string}
     */
    private function parseOgTags(string $html): array
    {
        $doc = new \DOMDocument();
        // libxml emits warnings for real-world malformed HTML — irrelevant
        // here, we only read <meta> tags.
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
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
