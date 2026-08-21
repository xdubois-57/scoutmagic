<?php

declare(strict_types=1);

namespace Tests\Core\File;

use Core\File\ChunkedUploadStore;
use Core\File\UploadException;
use PHPUnit\Framework\TestCase;

/**
 * Audit M2 — server-side reassembly of chunked uploads. What matters here
 * is what the store REFUSES: out-of-order chunks, ids from another
 * session, growth past the caller's ceiling, malformed ids.
 */
class ChunkedUploadStoreTest extends TestCase
{
    private string $storagePath;
    private ChunkedUploadStore $store;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/chunked_upload_store_test_' . uniqid();
        mkdir($this->storagePath, 0755, true);
        $this->store = new ChunkedUploadStore($this->storagePath);
    }

    protected function tearDown(): void
    {
        $dir = $this->storagePath . '/temp/chunked_uploads';
        foreach (glob($dir . '/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($dir);
        @rmdir($this->storagePath . '/temp');
        @rmdir($this->storagePath);
    }

    private function chunkFile(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'chunk_');
        file_put_contents($path, $content);
        return $path;
    }

    private const ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function testAssemblesSequentialChunksAndReturnsThePathOnTheLastOne(): void
    {
        $first = $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile('hello '), false, 1024);
        $this->assertNull($first);
        $this->assertSame(6, $this->store->receivedBytes(self::ID, 'sess1'));

        $path = $this->store->appendChunk(self::ID, 'sess1', 6, $this->chunkFile('world'), true, 1024);

        $this->assertNotNull($path);
        $this->assertSame('hello world', file_get_contents($path));
    }

    public function testRejectsAnOutOfOrderChunkAndReportsTheRealSize(): void
    {
        $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile('hello '), false, 1024);

        try {
            $this->store->appendChunk(self::ID, 'sess1', 100, $this->chunkFile('world'), false, 1024);
            $this->fail('Expected UploadException');
        } catch (UploadException $e) {
            $this->assertStringContainsString('hors séquence', $e->getMessage());
            $this->assertStringContainsString('6', $e->getMessage());
        }

        // The partial survives untouched, so the client can resume from 6.
        $this->assertSame(6, $this->store->receivedBytes(self::ID, 'sess1'));
    }

    public function testRejectsADuplicatedChunk(): void
    {
        $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile('hello '), false, 1024);

        $this->expectException(UploadException::class);
        $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile('hello '), false, 1024);
    }

    public function testEnforcesTheCapWhileGrowingAndDeletesThePartial(): void
    {
        $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile(str_repeat('a', 10)), false, 15);

        try {
            // Never sends "last" — the cap must still bite mid-stream.
            $this->store->appendChunk(self::ID, 'sess1', 10, $this->chunkFile(str_repeat('b', 10)), false, 15);
            $this->fail('Expected UploadException');
        } catch (UploadException $e) {
            $this->assertStringContainsString('dépasse', $e->getMessage());
        }

        $this->assertSame(0, $this->store->receivedBytes(self::ID, 'sess1'));
        $this->assertNull($this->store->assembledPath(self::ID, 'sess1'));
    }

    public function testRejectsAMalformedUploadId(): void
    {
        $this->expectException(UploadException::class);
        $this->store->appendChunk('../../etc/passwd', 'sess1', 0, $this->chunkFile('x'), false, 1024);
    }

    public function testRejectsATooShortUploadId(): void
    {
        $this->expectException(UploadException::class);
        $this->store->receivedBytes('abc', 'sess1');
    }

    public function testAnotherSessionCannotSeeOrAppendToTheUpload(): void
    {
        $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile('secret'), true, 1024);

        // Same id, different session: nothing there.
        $this->assertSame(0, $this->store->receivedBytes(self::ID, 'sess2'));
        $this->assertNull($this->store->assembledPath(self::ID, 'sess2'));

        // Appending under the other session starts a SEPARATE file (offset
        // 0 required), it does not extend sess1's.
        $this->store->appendChunk(self::ID, 'sess2', 0, $this->chunkFile('other'), false, 1024);
        $this->assertSame(6, $this->store->receivedBytes(self::ID, 'sess1'));
    }

    public function testAssembledPathIsNullBeforeAnyChunk(): void
    {
        $this->assertNull($this->store->assembledPath(self::ID, 'sess1'));
    }

    public function testDiscardRemovesThePartial(): void
    {
        $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile('data'), true, 1024);

        $this->store->discard(self::ID, 'sess1');

        $this->assertNull($this->store->assembledPath(self::ID, 'sess1'));
    }

    public function testStalePartialsArePurgedWhenANewUploadStarts(): void
    {
        $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile('old'), false, 1024);
        $stale = $this->storagePath . '/temp/chunked_uploads';
        foreach (glob($stale . '/*.part') ?: [] as $file) {
            touch($file, time() - 25 * 3600);
        }

        $freshId = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
        $this->store->appendChunk($freshId, 'sess1', 0, $this->chunkFile('new'), false, 1024);

        $this->assertSame(0, $this->store->receivedBytes(self::ID, 'sess1'));
        $this->assertSame(3, $this->store->receivedBytes($freshId, 'sess1'));
    }

    public function testARecentPartialSurvivesThePurge(): void
    {
        $this->store->appendChunk(self::ID, 'sess1', 0, $this->chunkFile('keep'), false, 1024);

        $freshId = 'cccccccccccccccccccccccccccccccc';
        $this->store->appendChunk($freshId, 'sess1', 0, $this->chunkFile('new'), false, 1024);

        $this->assertSame(4, $this->store->receivedBytes(self::ID, 'sess1'));
    }
}
