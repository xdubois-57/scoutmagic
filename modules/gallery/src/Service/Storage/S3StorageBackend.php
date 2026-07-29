<?php

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
    private S3Client $client;

    public function __construct(
        string $endpoint,
        string $region,
        private string $bucket,
        string $accessKey,
        string $secretKey,
        private ?string $publicUrl = null
    ) {
        $this->client = new S3Client([
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

    public function delete(string $key): void
    {
        $this->client->deleteObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
    }

    public function deletePrefix(string $prefix): void
    {
        $objects = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => rtrim($prefix, '/') . '/',
        ]);

        $keys = array_map(fn(array $object) => ['Key' => $object['Key']], $objects['Contents'] ?? []);
        if ($keys === []) {
            return;
        }

        $this->client->deleteObjects([
            'Bucket' => $this->bucket,
            'Delete' => ['Objects' => $keys],
        ]);
    }

    public function url(string $key): string
    {
        if ($this->publicUrl !== null && $this->publicUrl !== '') {
            return rtrim($this->publicUrl, '/') . '/' . ltrim($key, '/');
        }

        $command = $this->client->getCommand('GetObject', [
            'Bucket' => $this->bucket,
            'Key' => $key,
        ]);
        $request = $this->client->createPresignedRequest($command, '+1 hour');
        return (string) $request->getUri();
    }

    public function exists(string $key): bool
    {
        return $this->client->doesObjectExist($this->bucket, $key);
    }

    /**
     * Attempts a headBucket call to verify the credentials/endpoint/bucket
     * are all correct — Controller\GalleryConfigController::testConnection().
     *
     * @return string|null null on success, otherwise the underlying SDK
     *                      error message (never contains the secret key —
     *                      only ever the access key id, endpoint and bucket,
     *                      which are not secrets) so the admin can actually
     *                      see why a provider rejected the request instead
     *                      of a single generic message for every cause.
     */
    public function testConnection(): ?string
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
            return null;
        } catch (\Throwable $e) {
            return $e->getMessage();
        }
    }
}
