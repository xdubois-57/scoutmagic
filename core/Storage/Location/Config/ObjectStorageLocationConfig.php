<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Config;

/**
 * An S3-compatible bucket — Hetzner, Cloudflare R2, Scaleway, OVHcloud, or
 * a plain custom endpoint. Every provider in the configuration screen's
 * preset list speaks the same API; only the endpoint and the region
 * differ, which is why one record covers all of them.
 *
 * `$publicUrl` is the one field that changes what a visitor's browser
 * talks to: set, images are served from that origin directly; empty, they
 * are served through time-limited pre-signed URLs minted against the
 * endpoint. Both are direct — the bytes never pass through this site
 * either way — and the composition root has to know which, because the
 * origin has to be allowed in the Content-Security-Policy.
 *
 * The secret key is NOT here. See {@see LocationConfig}.
 */
final class ObjectStorageLocationConfig implements LocationConfig
{
    public function __construct(
        public readonly string $endpoint,
        public readonly string $region,
        public readonly string $bucket,
        public readonly string $accessKey,
        public readonly ?string $provider = null,
        public readonly ?string $publicUrl = null
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        return new self(
            endpoint: self::text($raw, 'endpoint') ?? '',
            region: self::text($raw, 'region') ?? '',
            bucket: self::text($raw, 'bucket') ?? '',
            accessKey: self::text($raw, 'access_key') ?? '',
            provider: self::text($raw, 'provider'),
            publicUrl: self::text($raw, 'public_url')
        );
    }

    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'region' => $this->region,
            'bucket' => $this->bucket,
            'access_key' => $this->accessKey,
            'provider' => $this->provider,
            'public_url' => $this->publicUrl,
        ];
    }

    /**
     * With a public URL prefix, yes, and that is exactly what it is for: a
     * CDN origin serving images to anyone. Without one, every URL this
     * location produces is pre-signed and expires.
     */
    public function servesPubliclyWithoutExpiry(): bool
    {
        return $this->publicUrl !== null && $this->publicUrl !== '';
    }

    public function describe(): string
    {
        $host = parse_url($this->endpoint, PHP_URL_HOST);

        return (is_string($host) && $host !== '' ? $host : $this->endpoint) . ' / ' . $this->bucket;
    }

    /**
     * @param array<string, mixed> $raw
     */
    private static function text(array $raw, string $key): ?string
    {
        $value = $raw[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
