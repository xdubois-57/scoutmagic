<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service\Storage;

use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use GuzzleHttp\Psr7\Utils;
use Modules\Gallery\Service\Storage\S3StorageBackend;
use PHPUnit\Framework\TestCase;

class S3StorageBackendTest extends TestCase
{
    private function backendWithMockClient(MockHandler $mock): S3StorageBackend
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'credentials' => ['key' => 'access', 'secret' => 'secret'],
            'handler' => $mock,
        ]);

        return new S3StorageBackend(
            'https://s3.example.org', 'us-east-1', 'scoutmagic', 'access', 'secret', null, $client
        );
    }

    public function testConnectionSucceedsWhenEveryOperationWorks(): void
    {
        $written = ['key' => null, 'body' => null];
        $mock = new MockHandler();
        $mock->append(fn() => new Result([])); // headBucket
        $mock->append(function ($cmd) use (&$written) { // putObject
            $written['key'] = $cmd['Key'];
            $written['body'] = (string) $cmd['Body'];
            return new Result([]);
        });
        $mock->append(function () use (&$written) { // getObject
            return new Result(['Body' => Utils::streamFor((string) $written['body'])]);
        });
        $mock->append(function () use (&$written) { // listObjectsV2
            return new Result(['Contents' => [['Key' => $written['key']]]]);
        });
        $mock->append(fn() => new Result([])); // deleteObjects

        $this->assertNull($this->backendWithMockClient($mock)->testConnection());
    }

    public function testConnectionFailsWhenHeadBucketFails(): void
    {
        $mock = new MockHandler();
        $mock->appendException(new \RuntimeException('NoSuchBucket'));

        $error = $this->backendWithMockClient($mock)->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('NoSuchBucket', $error);
    }

    /**
     * The real incident this whole rewrite exists to catch: read-only
     * credentials pass headBucket every time, then a real upload 403s.
     */
    public function testConnectionFailsWhenPutObjectIsDenied(): void
    {
        $mock = new MockHandler();
        $mock->append(fn() => new Result([])); // headBucket succeeds
        $mock->appendException(new \RuntimeException('Access Denied')); // putObject fails

        $error = $this->backendWithMockClient($mock)->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('Écriture impossible', $error);
        $this->assertStringContainsString('Access Denied', $error);
    }

    public function testConnectionFailsWhenGetObjectFailsAfterASuccessfulWrite(): void
    {
        $mock = new MockHandler();
        $mock->append(fn() => new Result([])); // headBucket
        $mock->append(fn() => new Result([])); // putObject
        $mock->appendException(new \RuntimeException('connection reset')); // getObject

        $error = $this->backendWithMockClient($mock)->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('Lecture impossible', $error);
    }

    /**
     * Write "succeeds" but what comes back doesn't match — a storage
     * backend silently corrupting data must not be reported as healthy.
     */
    public function testConnectionFailsWhenReadBackContentDiffersFromWhatWasWritten(): void
    {
        $mock = new MockHandler();
        $mock->append(fn() => new Result([])); // headBucket
        $mock->append(fn() => new Result([])); // putObject
        $mock->append(fn() => new Result(['Body' => Utils::streamFor('tampered-content')])); // getObject

        $error = $this->backendWithMockClient($mock)->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('diffère', $error);
    }

    public function testConnectionFailsWhenCleanupListOrDeleteIsDenied(): void
    {
        $written = ['body' => null];
        $mock = new MockHandler();
        $mock->append(fn() => new Result([])); // headBucket
        $mock->append(function ($cmd) use (&$written) { // putObject
            $written['body'] = (string) $cmd['Body'];
            return new Result([]);
        });
        $mock->append(function () use (&$written) { // getObject
            return new Result(['Body' => Utils::streamFor((string) $written['body'])]);
        });
        $mock->appendException(new \RuntimeException('Access Denied')); // listObjectsV2

        $error = $this->backendWithMockClient($mock)->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('Suppression impossible', $error);
    }

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
