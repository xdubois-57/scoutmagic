<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service\Storage;

use Modules\Gallery\Service\Storage\S3StorageBackend;
use PHPUnit\Framework\TestCase;

class S3StorageBackendTest extends TestCase
{
    public function testUrlStripsABucketPrefixedEndpointHost(): void
    {
        // Scaleway's console shows this bucket-scoped form as the bucket's
        // own "Endpoint" — pasting it verbatim (instead of the account/
        // region-level endpoint) must not end up referencing the bucket
        // twice once path-style addressing appends it again.
        $backend = new S3StorageBackend(
            'https://scoutmagic.s3.fr-par.scw.cloud',
            'fr-par',
            'scoutmagic',
            'access',
            'secret'
        );

        $url = $backend->url('albums/1/photo.jpg');

        $this->assertStringStartsWith('https://s3.fr-par.scw.cloud/scoutmagic/', $url);
    }

    public function testUrlKeepsARegionLevelEndpointUnchanged(): void
    {
        $backend = new S3StorageBackend(
            'https://s3.fr-par.scw.cloud',
            'fr-par',
            'scoutmagic',
            'access',
            'secret'
        );

        $url = $backend->url('albums/1/photo.jpg');

        $this->assertStringStartsWith('https://s3.fr-par.scw.cloud/scoutmagic/', $url);
    }

    public function testServingOriginStripsTheBucketPrefixTheSameWayUrlDoes(): void
    {
        // Must match what url() actually renders in an <img src> exactly —
        // this is what gets allowed in the CSP img-src directive.
        $origin = S3StorageBackend::servingOrigin('https://scoutmagic.s3.fr-par.scw.cloud', 'scoutmagic', null);

        $this->assertSame('https://s3.fr-par.scw.cloud', $origin);
    }

    public function testServingOriginPrefersThePublicUrlWhenConfigured(): void
    {
        $origin = S3StorageBackend::servingOrigin('https://s3.fr-par.scw.cloud', 'scoutmagic', 'https://cdn.example.org/photos');

        $this->assertSame('https://cdn.example.org', $origin);
    }

    public function testServingOriginReturnsNullForAnUnparseableEndpoint(): void
    {
        $origin = S3StorageBackend::servingOrigin('', 'scoutmagic', null);

        $this->assertNull($origin);
    }
}
