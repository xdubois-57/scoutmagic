<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service\Storage;

use Aws\Exception\AwsException;
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
     * The AWS SDK's own message for the most recent failed
     * {@see testConnection()} operation — deliberately kept OFF that
     * method's return value, which is rendered on the configuration page.
     */
    private ?string $lastTechnicalError = null;

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

    public function localPath(string $key): ?string
    {
        // An S3 object is not a local file — it would have to be downloaded
        // first, so it can't be streamed straight off disk (audit M10).
        return null;
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

    public function copy(string $fromKey, string $toKey): void
    {
        // CopyObject is server-side: the object never leaves the provider,
        // so a 900 MB video changes album without a download/upload round
        // trip through this process.
        $this->client->copyObject([
            'Bucket' => $this->bucket,
            'Key' => $toKey,
            // Passed unencoded on purpose: every key this backend receives
            // is app-generated ("{albumId}/med_{mediaId}.jpg" — digits,
            // "_", "." and "/" only), and percent-encoding the whole
            // "bucket/key" string would escape the separators themselves.
            'CopySource' => $this->bucket . '/' . ltrim($fromKey, '/'),
        ]);
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
     * @return string|null null on success, otherwise a FRENCH sentence
     *                      naming the operation that failed and, where the
     *                      provider said so, why. It used to return the AWS
     *                      SDK's own English — "Error executing
     *                      \"HeadBucket\" … AWS HTTP error: cURL error 6" —
     *                      which reached the configuration page through
     *                      Service\StorageLocationService's `lastCheckError`
     *                      column and Controller\GalleryConfigController's
     *                      JSON. The SDK's words are still available, on
     *                      {@see self::lastTechnicalError()}, for the caller
     *                      to journal.
     */
    public function testConnection(): ?string
    {
        $this->lastTechnicalError = null;

        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
        } catch (\Throwable $e) {
            return $this->failure('Connexion au bucket impossible', $e);
        }

        $key = self::HEALTH_CHECK_PREFIX . '/' . bin2hex(random_bytes(8)) . '.txt';
        $content = 'scoutmagic-healthcheck-' . bin2hex(random_bytes(8));

        try {
            $this->put($key, $content, 'text/plain');
        } catch (\Throwable $e) {
            return $this->failure("Écriture impossible (l'accès en lecture fonctionne, mais pas en écriture)", $e);
        }

        try {
            $readBack = $this->get($key);
        } catch (\Throwable $e) {
            $this->cleanupHealthCheckObject($key);
            return $this->failure("Lecture impossible juste après l'écriture", $e);
        }
        if ($readBack !== $content) {
            $this->cleanupHealthCheckObject($key);
            return 'Le contenu relu après écriture diffère de ce qui a été envoyé (corruption silencieuse).';
        }

        try {
            $this->deletePrefix(self::HEALTH_CHECK_PREFIX);
        } catch (\Throwable $e) {
            return $this->failure('Suppression impossible (list/delete)', $e);
        }

        return null;
    }

    /**
     * The AWS SDK's own message for the most recent {@see testConnection()}
     * failure, or null when the last check succeeded or has not run. For the
     * journal and the log — never for a page, which is the whole point of
     * keeping it off testConnection()'s return value.
     */
    public function lastTechnicalError(): ?string
    {
        return $this->lastTechnicalError;
    }

    /**
     * Turns one failed operation into the sentence the admin reads, and
     * parks the SDK's own words on $lastTechnicalError.
     */
    private function failure(string $operation, \Throwable $e): string
    {
        $this->lastTechnicalError = $e->getMessage();

        return $operation . ' : ' . self::probableCause($e) . '.';
    }

    /**
     * A French cause per S3 error code — the codes are a short, stable,
     * provider-independent vocabulary (every S3-compatible provider uses
     * them), unlike the prose around them, which varies by provider and is
     * always English.
     */
    private static function probableCause(\Throwable $e): string
    {
        $code = $e instanceof AwsException ? (string) $e->getAwsErrorCode() : '';

        return match ($code) {
            'NoSuchBucket' => 'le bucket indiqué n\'existe pas sur ce service (vérifiez son nom et la région)',
            'InvalidAccessKeyId' => 'la clé d\'accès est inconnue de ce service (vérifiez qu\'elle appartient bien à ce fournisseur)',
            'SignatureDoesNotMatch' => 'la clé secrète ne correspond pas à la clé d\'accès (recopiez-la, sans espace avant ni après)',
            'AccessDenied', 'Forbidden' => 'les identifiants n\'ont pas les droits nécessaires sur ce bucket (lecture, écriture, listage et suppression sont tous requis)',
            'RequestTimeTooSkewed' => 'l\'horloge du serveur est trop décalée par rapport à celle du fournisseur',
            'NotFound' => 'le bucket ou l\'adresse du service est introuvable (vérifiez l\'adresse et le nom du bucket)',
            default => 'le service a refusé la requête (voir le journal pour le détail technique)',
        };
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
