<?php

declare(strict_types=1);

namespace Tests\Modules\Gallery\Service\Storage;

use Aws\Command;
use Aws\Exception\AwsException;
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

        $backend = $this->backendWithMockClient($mock);
        $error = $backend->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('Connexion au bucket impossible', $error);
        // The SDK's own words are still reachable — for the journal, not the
        // page (Controller\GalleryConfigController::testConnection()).
        $this->assertStringContainsString('NoSuchBucket', (string) $backend->lastTechnicalError());
    }

    /**
     * The leak this method exists to stop: `lastCheckError` is rendered on
     * the gallery configuration page and this string used to be the AWS
     * SDK's own English — "Error executing \"HeadBucket\" on … AWS HTTP
     * error: cURL error 6: Could not resolve host", endpoint, request id
     * and all.
     */
    public function testConnectionNeverSurfacesTheAwsSdkMessage(): void
    {
        $sdkMessage = 'Error executing "HeadBucket" on "https://s3.example.org/scoutmagic"; '
            . 'AWS HTTP error: cURL error 6: Could not resolve host: s3.example.org';

        $mock = new MockHandler();
        $mock->appendException(new \RuntimeException($sdkMessage));

        $backend = $this->backendWithMockClient($mock);
        $error = $backend->testConnection();

        $this->assertNotNull($error);
        foreach (['HeadBucket', 'AWS', 'cURL', 'http', 'Error executing'] as $fragment) {
            $this->assertStringNotContainsStringIgnoringCase($fragment, $error);
        }
        $this->assertSame($sdkMessage, $backend->lastTechnicalError());
    }

    /**
     * The S3 error CODE is a short, stable vocabulary every compatible
     * provider shares — unlike the prose around it — so it is what the
     * French sentence is built from.
     */
    public function testConnectionNamesTheProbableCauseInFrenchForAKnownS3ErrorCode(): void
    {
        $mock = new MockHandler();
        $mock->appendException(new AwsException(
            'Error executing "HeadBucket": Access Denied',
            new Command('HeadBucket'),
            ['code' => 'SignatureDoesNotMatch']
        ));

        $error = $this->backendWithMockClient($mock)->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('la clé secrète ne correspond pas', $error);
    }

    public function testConnectionForgetsThePreviousTechnicalErrorOnASuccessfulCheck(): void
    {
        $backend = $this->backendWithMockClient((function () {
            $mock = new MockHandler();
            $mock->appendException(new \RuntimeException('NoSuchBucket'));
            return $mock;
        })());
        $backend->testConnection();
        $this->assertNotNull($backend->lastTechnicalError());

        // A second, healthy backend must not report the first one's error.
        $written = ['key' => null, 'body' => null];
        $mock = new MockHandler();
        $mock->append(fn() => new Result([]));
        $mock->append(function ($cmd) use (&$written) {
            $written['key'] = $cmd['Key'];
            $written['body'] = (string) $cmd['Body'];
            return new Result([]);
        });
        $mock->append(function () use (&$written) {
            return new Result(['Body' => Utils::streamFor((string) $written['body'])]);
        });
        $mock->append(function () use (&$written) {
            return new Result(['Contents' => [['Key' => $written['key']]]]);
        });
        $mock->append(fn() => new Result([]));

        $healthy = $this->backendWithMockClient($mock);
        $this->assertNull($healthy->testConnection());
        $this->assertNull($healthy->lastTechnicalError());
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

        $backend = $this->backendWithMockClient($mock);
        $error = $backend->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('Écriture impossible', $error);
        $this->assertStringNotContainsString('Access Denied', $error);
        $this->assertStringContainsString('Access Denied', (string) $backend->lastTechnicalError());
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

    public function testSizeReportsContentLength(): void
    {
        $mock = new MockHandler();
        $mock->append(fn() => new Result(['ContentLength' => 4096]));

        $this->assertSame(4096, $this->backendWithMockClient($mock)->size('1/med_1.mp4'));
    }

    public function testSizeIsNullWhenHeadObjectFails(): void
    {
        $mock = new MockHandler();
        $mock->appendException(new \RuntimeException('NoSuchKey'));

        $this->assertNull($this->backendWithMockClient($mock)->size('1/gone.mp4'));
    }

    public function testGetRangeSendsAByteRangeHeaderAndReturnsTheSlice(): void
    {
        $sent = null;
        $mock = new MockHandler();
        $mock->append(function ($cmd) use (&$sent) {
            $sent = $cmd['Range'];
            return new Result(['Body' => Utils::streamFor('234')]);
        });

        $bytes = $this->backendWithMockClient($mock)->getRange('1/med_1.mp4', 2, 3);

        $this->assertSame('bytes=2-4', $sent);
        $this->assertSame('234', $bytes);
    }

    public function testGetRangeOfZeroLengthNeverHitsTheNetwork(): void
    {
        $mock = new MockHandler();
        $mock->appendException(new \RuntimeException('should not be called'));

        $this->assertSame('', $this->backendWithMockClient($mock)->getRange('1/med_1.mp4', 0, 0));
    }

    public function testGetRangeWrapsASdkFailureAsARuntimeException(): void
    {
        $mock = new MockHandler();
        $mock->appendException(new \RuntimeException('InvalidRange'));

        $this->expectException(\RuntimeException::class);
        $this->backendWithMockClient($mock)->getRange('1/med_1.mp4', 0, 10);
    }

    /**
     * ListObjectsV2 caps a response at 1000 keys. An album at the default
     * gallery_max_media_per_album already holds up to 800 renditions, and a
     * higher limit puts it over the page size — an unpaged single pass left
     * every key past the first page in the bucket forever.
     */
    public function testDeletePrefixPagesThroughEveryListedObject(): void
    {
        $firstPage = array_map(fn(int $i) => ['Key' => "5/a{$i}.jpg"], range(1, 1000));
        $secondPage = array_map(fn(int $i) => ['Key' => "5/b{$i}.jpg"], range(1, 3));

        $listRequests = [];
        $deletedKeys = [];
        $mock = new MockHandler();
        $mock->append(function ($cmd) use (&$listRequests, $firstPage) {
            $listRequests[] = $cmd['ContinuationToken'] ?? null;
            return new Result([
                'Contents' => $firstPage,
                'IsTruncated' => true,
                'NextContinuationToken' => 'page-2',
            ]);
        });
        $mock->append(function ($cmd) use (&$deletedKeys) {
            foreach ($cmd['Delete']['Objects'] as $object) {
                $deletedKeys[] = $object['Key'];
            }
            return new Result([]);
        });
        $mock->append(function ($cmd) use (&$listRequests, $secondPage) {
            $listRequests[] = $cmd['ContinuationToken'] ?? null;
            return new Result(['Contents' => $secondPage, 'IsTruncated' => false]);
        });
        $mock->append(function ($cmd) use (&$deletedKeys) {
            foreach ($cmd['Delete']['Objects'] as $object) {
                $deletedKeys[] = $object['Key'];
            }
            return new Result([]);
        });

        $this->backendWithMockClient($mock)->deletePrefix('5');

        $this->assertSame([null, 'page-2'], $listRequests);
        $this->assertCount(1003, $deletedKeys);
        $this->assertContains('5/a1000.jpg', $deletedKeys);
        $this->assertContains('5/b3.jpg', $deletedKeys);
    }

    /**
     * DeleteObjects itself accepts at most 1000 keys per call.
     */
    public function testDeletePrefixChunksDeletesToAThousandKeys(): void
    {
        $batchSizes = [];
        $mock = new MockHandler();
        $mock->append(fn() => new Result([
            'Contents' => array_map(fn(int $i) => ['Key' => "5/a{$i}.jpg"], range(1, 1500)),
            'IsTruncated' => false,
        ]));
        $mock->append(function ($cmd) use (&$batchSizes) {
            $batchSizes[] = count($cmd['Delete']['Objects']);
            return new Result([]);
        });
        $mock->append(function ($cmd) use (&$batchSizes) {
            $batchSizes[] = count($cmd['Delete']['Objects']);
            return new Result([]);
        });

        $this->backendWithMockClient($mock)->deletePrefix('5');

        $this->assertSame([1000, 500], $batchSizes);
    }

    public function testDeletePrefixIssuesNoDeleteWhenNothingIsListed(): void
    {
        $mock = new MockHandler();
        $mock->append(fn() => new Result(['Contents' => [], 'IsTruncated' => false]));
        // A second queued response would only be consumed by a stray
        // deleteObjects call.
        $mock->appendException(new \RuntimeException('should not be called'));

        $this->backendWithMockClient($mock)->deletePrefix('5');

        $this->assertTrue(true);
    }

    /**
     * Guards against a provider that reports IsTruncated but forgets the
     * token — the loop must end rather than re-list the same page forever.
     */
    public function testDeletePrefixStopsWhenTruncatedWithoutAToken(): void
    {
        $listCalls = 0;
        $mock = new MockHandler();
        $mock->append(function () use (&$listCalls) {
            $listCalls++;
            return new Result([
                'Contents' => [['Key' => '5/a1.jpg']],
                'IsTruncated' => true,
            ]);
        });
        $mock->append(fn() => new Result([]));

        $this->backendWithMockClient($mock)->deletePrefix('5');

        $this->assertSame(1, $listCalls);
    }
}
