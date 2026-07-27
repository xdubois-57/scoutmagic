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
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
        ]);
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
     */
    public function testConnection(): bool
    {
        try {
            $this->client->headBucket(['Bucket' => $this->bucket]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
