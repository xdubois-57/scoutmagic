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

    /**
     * The per-chunk check has to hold the CUMULATIVE size against the
     * budget, not this chunk's.
     *
     * Checking one fragment at a time passes the same small number over and
     * over while a half-gigabyte archive lands past the quota, which is
     * precisely the mid-write overshoot the guard exists to refuse.
     */
    public function testTheQuotaIsCheckedAgainstTheAssembledSizeNotTheChunk(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($pdo));
        $settings->register(\Core\Storage\DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');
        // Room for the safety margin plus a little: one small chunk fits,
        // the accumulated total must not.
        $settings->set(\Core\Storage\DiskBudget::QUOTA_SETTING, (string) (60 * 1024 * 1024));

        $store = new ChunkedUploadStore(
            $this->storagePath,
            new \Core\Storage\DiskBudget($this->storagePath, $settings)
        );

        $uploadId = bin2hex(random_bytes(16));
        $chunk = $this->storagePath . '/chunk.bin';
        file_put_contents($chunk, str_repeat('x', 1024));

        // The first chunk sits well inside the budget.
        $store->appendChunk($uploadId, 'sess-quota', 0, $chunk, false, 500 * 1024 * 1024);

        // A chunk claiming to start 400 MiB in must be refused, even
        // though the chunk itself is a kilobyte.
        $this->expectException(UploadException::class);
        $store->appendChunk($uploadId, 'sess-quota', 400 * 1024 * 1024, $chunk, false, 500 * 1024 * 1024);
    }

    /**
     * ...and against the headroom PINNED before the first fragment, never
     * against a reading taken while the partial file is already on disk.
     *
     * This is the other half of the same guard, and it fails in the
     * opposite direction. `DiskBudget::availableBytes()` reads
     * `disk_free_space()` live on every call, and with a quota declared its
     * other leg re-walks `storage/` as soon as the cached measurement
     * expires — which a large upload outlives. Either way the reading
     * already has the `.part` file subtracted from it, so charging the
     * cumulative size against it charges the bytes already written twice:
     * roughly double the room demanded, and an upload refused that fits.
     *
     * The quota here leaves 64 KiB of slack over the assembled megabyte. A
     * pinned reading passes; charging the first fragment a second time
     * would not.
     */
    public function testTheHeadroomIsPinnedBeforeTheFirstChunkRatherThanRemeasured(): void
    {
        $margin = \Core\Storage\DiskBudget::SAFETY_MARGIN_BYTES;
        $chunkBytes = 512 * 1024;
        $slack = 64 * 1024;

        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($pdo));
        $settings->register(\Core\Storage\DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');

        // Measured rather than assumed: the quota has to be expressed
        // relative to whatever this temporary tree already weighs, or the
        // 64 KiB of slack is the first thing to drift.
        $before = \Core\Storage\DirectorySize::measure($this->storagePath);
        $quota = $before + 2 * $chunkBytes + $margin + $slack;
        $settings->set(\Core\Storage\DiskBudget::QUOTA_SETTING, (string) $quota);

        // The volume's own free space is the other leg of the minimum, and
        // a nearly-full runner would bind there instead and prove nothing.
        $volumeFree = @disk_free_space($this->storagePath);
        if (!is_float($volumeFree) || $volumeFree < $quota) {
            $this->markTestSkipped('Volume libre insuffisant pour que le quota déclaré soit la contrainte.');
        }

        $store = new ChunkedUploadStore(
            $this->storagePath,
            new \Core\Storage\DiskBudget($this->storagePath, $settings)
        );

        $uploadId = bin2hex(random_bytes(16));
        // Outside the measured tree, so the fragment's own bytes are not
        // part of the figure the quota is set against.
        $chunk = $this->chunkFile(str_repeat('x', $chunkBytes));

        $store->appendChunk($uploadId, 'sess-pinned', 0, $chunk, false, 10 * 1024 * 1024);

        // Expire the cached `storage/` measurement, so any code that went
        // back to the budget here would see the 512 KiB already written and
        // subtract them a second time. Nothing should: the reading pinned
        // at offset 0 is the one that counts.
        @unlink($this->storagePath . '/core/disk-usage.json');

        $path = $store->appendChunk($uploadId, 'sess-pinned', $chunkBytes, $chunk, true, 10 * 1024 * 1024);

        $this->assertNotNull($path);
        $this->assertSame(2 * $chunkBytes, filesize($path));
    }

    /**
     * With no pinned reading — a resumed upload whose sidecar was purged, a
     * temp directory that refused to write it — the store falls back to the
     * ordinary live check on the fragment in hand rather than refusing.
     *
     * Weaker than the pinned check and deliberately so: it can only
     * under-charge, and an upload that would have gone through before this
     * guard existed must not start failing because a sidecar went missing.
     */
    public function testAMissingPinnedReadingFallsBackToTheLivePerChunkCheck(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($pdo));
        $settings->register(\Core\Storage\DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');
        $settings->set(\Core\Storage\DiskBudget::QUOTA_SETTING, '');

        $store = new ChunkedUploadStore(
            $this->storagePath,
            new \Core\Storage\DiskBudget($this->storagePath, $settings)
        );

        $uploadId = bin2hex(random_bytes(16));
        $chunk = $this->chunkFile('hello ');
        $store->appendChunk($uploadId, 'sess-nopin', 0, $chunk, false, 1024);

        foreach (glob($this->storagePath . '/temp/chunked_uploads/*.budget') ?: [] as $sidecar) {
            unlink($sidecar);
        }

        $path = $store->appendChunk($uploadId, 'sess-nopin', 6, $this->chunkFile('world'), true, 1024);

        $this->assertNotNull($path);
        $this->assertSame('hello world', file_get_contents($path));
    }

    /** A discarded upload leaves neither its partial nor its pinned reading. */
    public function testDiscardRemovesThePinnedReadingWithThePartial(): void
    {
        $pdo = \Tests\DatabaseTestHelper::createTestDatabase();
        $settings = new \Core\Config\SettingService(new \Core\Config\SettingRepository($pdo));
        $settings->register(\Core\Storage\DiskBudget::QUOTA_SETTING, '', 'text', 'Quota', 'Quota');
        $settings->set(\Core\Storage\DiskBudget::QUOTA_SETTING, (string) (10 * 1024 * 1024 * 1024));

        $store = new ChunkedUploadStore(
            $this->storagePath,
            new \Core\Storage\DiskBudget($this->storagePath, $settings)
        );

        $uploadId = bin2hex(random_bytes(16));
        $store->appendChunk($uploadId, 'sess-discard', 0, $this->chunkFile('data'), false, 1024);
        $this->assertNotEmpty(glob($this->storagePath . '/temp/chunked_uploads/*.budget') ?: []);

        $store->discard($uploadId, 'sess-discard');

        $this->assertSame([], glob($this->storagePath . '/temp/chunked_uploads/*.budget') ?: []);
        $this->assertSame([], glob($this->storagePath . '/temp/chunked_uploads/*.part') ?: []);
    }
}
