<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service\Storage;

use Aws\S3\S3Client;

/**
 * Any S3-compatible object storage (Hetzner, Cloudflare R2, Scaleway,
 * OVHcloud, or a custom endpoint) via aws/aws-sdk-php — every provider in
 * the config page's preset list speaks the same S3 API, so one client
 * class covers them all; only the endpoint/region differ (Controller\
 * GalleryConfigController's provider presets).
 */
class S3StorageBackend implements StorageBackendInterface
{
    /**
     * Dedicated, non-colliding prefix for testConnection()'s canary object —
     * every real album lives under a numeric "{albumId}/..." prefix, so a
     * leading dot can never collide with one.
     */
    private const HEALTH_CHECK_PREFIX = '.scoutmagic-healthcheck';

    /**
     * DeleteObjects accepts at most 1000 keys per call (S3 API limit, and
     * every compatible provider enforces it) — deletePrefix() chunks to
     * this, and pages ListObjectsV2 for the same reason.
     */
    private const DELETE_BATCH_SIZE = 1000;

    private S3Client $client;

    /**
     * $client is normally left null (built from the connection parameters
     * below) — injectable only so tests can substitute an SDK MockHandler-
     * backed client to exercise testConnection()'s put/get/delete failure
     * paths without a real bucket.
     */
    public function __construct(
        string $endpoint,
        string $region,
        private string $bucket,
        string $accessKey,
        string $secretKey,
        private ?string $publicUrl = null,
        ?S3Client $client = null
    ) {
        $this->client = $client ?? new S3Client([
            'version' => 'latest',
            'region' => $region !== '' ? $region : 'auto',
            'endpoint' => self::stripBucketFromEndpointHost($endpoint, $this->bucket),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);
    }

    /**
     * Several providers' consoles (Scaleway in particular) prominently show
     * a bucket's own virtual-hosted-style endpoint (e.g.
     * "https://<bucket>.s3.<region>.scw.cloud") on the bucket's own settings
     * page — pasting that into the account/region-level "Endpoint" field
     * combines with path-style addressing (forced above, for provider
     * compatibility) to reference the bucket twice and always 403s. Strip a
     * leading "{bucket}." host segment so either endpoint shape works.
     *
     * Static (not just for internal use): the composition root also needs
     * this exact normalization to compute the real image-serving origin for
     * the CSP img-src directive (public/index.php), without constructing a
     * whole S3Client (and its required credentials) just for that.
     */
    public static function stripBucketFromEndpointHost(string $endpoint, string $bucket): string
    {
        if ($bucket === '' || $endpoint === '') {
            return $endpoint;
        }
        $parts = parse_url($endpoint);
        if (!isset($parts['host']) || !str_starts_with($parts['host'], $bucket . '.')) {
            return $endpoint;
        }
        $scheme = $parts['scheme'] ?? 'https';
        $host = substr($parts['host'], strlen($bucket) + 1);
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $scheme . '://' . $host . $port;
    }

    /**
     * The origin (scheme://host, no path/query) that image URLs are ACTUALLY
     * served from — Controller\GalleryConfigController::buildContext() /
     * public/index.php use this to allow it in the CSP img-src directive,
     * for whichever provider is currently configured (never hardcode a
     * specific provider's hostname). Mirrors url()'s own public-URL-vs-
     * presigned-endpoint precedence exactly, since that's what actually
     * gets rendered in an <img src>.
     */
    public static function servingOrigin(string $endpoint, string $bucket, ?string $publicUrl): ?string
    {
        $target = $publicUrl !== null && $publicUrl !== '' ? $publicUrl : self::stripBucketFromEndpointHost($endpoint, $bucket);
        $parts = parse_url($target);
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        return $parts['scheme'] . '://' . $parts['host'] . $port;
    }

    public function put(string $key, string $contents, string $mimeType): void
    {
        $this->client->putObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
            'Body' => $contents,
            'ContentType' => $mimeType,
        ]);
    }

    public function get(string $key): string
    {
        $result = $this->client->getObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        return (string) $result['Body'];
    }

    public function size(string $key): ?int
    {
        try {
            $result = $this->client->headObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $length = $result['ContentLength'] ?? null;
        return is_numeric($length) ? (int) $length : null;
    }

    public function getRange(string $key, int $offset, int $length): string
    {
        if ($length <= 0) {
            return '';
        }

        $offset = max(0, $offset);
        $last = $offset + $length - 1;

        try {
            $result = $this->client->getObject([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Range' => "bytes={$offset}-{$last}",
            ]);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Gallery file range not readable: {$key}", 0, $e);
        }

        return (string) $result['Body'];
    }

    public function delete(string $key): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }

    /**
     * ListObjectsV2 returns at most 1000 keys per response and DeleteObjects
     * accepts at most 1000 per call, so both sides are paged/chunked: an
     * album at the default gallery_max_media_per_album (200) already holds up
     * to 800 renditions, and a single unpaged pass silently left everything
     * past the first page behind — orphaned objects the operator keeps paying
     * for, on every album deletion and every post-migration source cleanup.
     */
    public function deletePrefix(string $prefix): void
    {
        $continuationToken = null;

        do {
            $request = [
                'Bucket' => $this->bucket,
                'Prefix' => rtrim($prefix, '/') . '/',
            ];
            if ($continuationToken !== null) {
                $request['ContinuationToken'] = $continuationToken;
            }

            $objects = $this->client->listObjectsV2($request);

            $keys = array_values(array_map(
                fn(array $object) => ['Key' => $object['Key']],
                $objects['Contents'] ?? []
            ));
            foreach (array_chunk($keys, self::DELETE_BATCH_SIZE) as $batch) {
                $this->client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => ['Objects' => $batch],
                ]);
            }

            $token = $objects['NextContinuationToken'] ?? null;
            $continuationToken = ($objects['IsTruncated'] ?? false) && is_string($token) && $token !== ''
                ? $token
                : null;
        } while ($continuationToken !== null);
    }

    public function url(string $key, string $ttl = '+1 hour'): string
    {
        if ($this->publicUrl !== null && $this->publicUrl !== '') {
            return rtrim($this->publicUrl, '/') . '/' . ltrim($key, '/');
        }

        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        // Minted fresh on every call, per $ttl — a presigned URL remains
        // valid for the whole of its expiry once handed out, so callers
        // that need a short-lived grant (Controller\GalleryController::
        // serveMedia() for a delegated album) pass a short one; nothing
        // here ever caches or reuses a previously minted URL.
        $request = $this->client->createPresignedRequest($command, $ttl);
        return (string) $request->getUri();
    }

    public function exists(string $key): bool
    {
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    /**
     * Exercises every operation the app actually performs against this
     * location, not just read access — a headBucket-only check can report
     * "ok" for credentials that can list/read a bucket but are denied
     * PutObject (this happened for real: a Scaleway policy scoped to
     * read-only let every prior health check pass right up until a real
     * upload/migration hit AccessDenied). Writes a small canary object
     * under HEALTH_CHECK_PREFIX (never collides with a real album's numeric
     * prefix), reads it back to catch silent write corruption, then removes
     * it via deletePrefix() — the exact same list-then-batch-delete call
     * production code uses to clean up an album — so ListObjectsV2 and
     * DeleteObjects permissions are verified too, not just PutObject/
     * GetObject. Called from Controller\GalleryConfigController::
     * testConnection() (admin-triggered) and Service\StorageLocationService
     * ::checkNow() (TTL-gated background refresh).
     *
     * @return string|null null on success, otherwise a message identifying
     *                      which operation failed and the underlying SDK
     *                      error (never contains the secret key — only ever
     *                      the access key id, endpoint and bucket, which are
     *                      not secrets) so the admin can see exactly why a
     *                      provider rejected the request instead of a single
     *                      generic message for every cause.
     */
    public function testConnection(): ?string
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
        } catch (\Throwable $e) {
            return $e->getMessage();
        }

        $key = self::HEALTH_CHECK_PREFIX . '/' . bin2hex(random_bytes(8)) . '.txt';
        $content = 'scoutmagic-healthcheck-' . bin2hex(random_bytes(8));

        try {
            $this->put($key, $content, 'text/plain');
        } catch (\Throwable $e) {
            return "Écriture impossible (l'accès en lecture fonctionne, mais pas en écriture) : " . $e->getMessage();
        }

        try {
            $readBack = $this->get($key);
        } catch (\Throwable $e) {
            $this->cleanupHealthCheckObject($key);
            return "Lecture impossible juste après l'écriture : " . $e->getMessage();
        }
        if ($readBack !== $content) {
            $this->cleanupHealthCheckObject($key);
            return 'Le contenu relu après écriture diffère de ce qui a été envoyé (corruption silencieuse).';
        }

        try {
            $this->deletePrefix(self::HEALTH_CHECK_PREFIX);
        } catch (\Throwable $e) {
            return "Suppression impossible (list/delete) : " . $e->getMessage();
        }

        return null;
    }

    private function cleanupHealthCheckObject(string $key): void
    {
        try {
            $this->delete($key);
        } catch (\Throwable) {
            // Best effort — the connection is already being reported as
            // broken via the exception above; a leftover few-byte canary
            // object is harmless.
        }
    }
}
