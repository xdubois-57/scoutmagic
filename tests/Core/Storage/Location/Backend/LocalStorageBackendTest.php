<?php

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend;

use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\StorageCapabilities;
use Core\Storage\Location\StorageCapability;
use Core\Storage\Location\UnsupportedCapabilityException;
use PHPUnit\Framework\TestCase;

class LocalStorageBackendTest extends TestCase
{
    private string $storagePath;
    private LocalStorageBackend $backend;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/storage_location_test_' . uniqid();
        $this->backend = new LocalStorageBackend($this->storagePath . '/gallery');
    }

    protected function tearDown(): void
    {
        if (is_dir($this->storagePath)) {
            $this->removeRecursively($this->storagePath);
        }
    }

    private function removeRecursively(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            // isLink() first: a symlink pointing at a directory answers
            // true to isDir(), and rmdir() on it fails — leaving the whole
            // tree behind.
            if ($item->isLink() || !$item->isDir()) {
                unlink((string) $item);
                continue;
            }
            rmdir((string) $item);
        }
        rmdir($dir);
    }

    public function testPutThenGetRoundTrips(): void
    {
        $this->backend->put('1/thumb_1.jpg', 'fake-jpeg-bytes', 'image/jpeg');

        $this->assertSame('fake-jpeg-bytes', $this->backend->get('1/thumb_1.jpg'));
    }

    public function testExistsReflectsPresence(): void
    {
        $this->assertFalse($this->backend->exists('1/thumb_1.jpg'));

        $this->backend->put('1/thumb_1.jpg', 'data', 'image/jpeg');

        $this->assertTrue($this->backend->exists('1/thumb_1.jpg'));
    }

    public function testDeleteRemovesTheFile(): void
    {
        $this->backend->put('1/thumb_1.jpg', 'data', 'image/jpeg');

        $this->backend->delete('1/thumb_1.jpg');

        $this->assertFalse($this->backend->exists('1/thumb_1.jpg'));
    }

    public function testGetThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backend->get('missing/nope.jpg');
    }

    public function testDeletePrefixRemovesTheWholeAlbumDirectory(): void
    {
        $this->backend->put('7/thumb_1.jpg', 'a', 'image/jpeg');
        $this->backend->put('7/med_1.jpg', 'b', 'image/jpeg');
        $this->backend->put('8/thumb_2.jpg', 'c', 'image/jpeg');

        $this->backend->deletePrefix('7');

        $this->assertFalse($this->backend->exists('7/thumb_1.jpg'));
        $this->assertFalse($this->backend->exists('7/med_1.jpg'));
        $this->assertTrue($this->backend->exists('8/thumb_2.jpg'));
    }

    public function testDeletePrefixOnMissingDirectoryIsANoOp(): void
    {
        $this->backend->deletePrefix('never-existed');
        $this->assertTrue(true);
    }

    public function testDeletePrefixRefusesAnEmptyPrefix(): void
    {
        $this->backend->put('7/thumb_1.jpg', 'a', 'image/jpeg');

        $this->backend->deletePrefix('');
        $this->backend->deletePrefix('/');

        // An empty prefix resolves to the location's own root — wiping every
        // album in it, not one.
        $this->assertTrue($this->backend->exists('7/thumb_1.jpg'));
    }

    public function testSizeReportsTheByteCount(): void
    {
        $this->backend->put('1/med_1.jpg', 'abcdefghij', 'image/jpeg');

        $this->assertSame(10, $this->backend->size('1/med_1.jpg'));
    }

    public function testSizeIsNullForAMissingKey(): void
    {
        $this->assertNull($this->backend->size('1/nope.jpg'));
    }

    public function testGetRangeReturnsOnlyTheRequestedSlice(): void
    {
        $this->backend->put('1/med_1.mp4', '0123456789', 'video/mp4');

        $this->assertSame('234', $this->backend->getRange('1/med_1.mp4', 2, 3));
        $this->assertSame('0', $this->backend->getRange('1/med_1.mp4', 0, 1));
    }

    public function testGetRangeClipsAtTheEndOfTheObject(): void
    {
        $this->backend->put('1/med_1.mp4', '0123456789', 'video/mp4');

        $this->assertSame('89', $this->backend->getRange('1/med_1.mp4', 8, 500));
    }

    public function testGetRangeOfZeroLengthIsEmpty(): void
    {
        $this->backend->put('1/med_1.mp4', '0123456789', 'video/mp4');

        $this->assertSame('', $this->backend->getRange('1/med_1.mp4', 0, 0));
    }

    public function testGetRangeThrowsWhenMissing(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backend->getRange('missing/nope.mp4', 0, 10);
    }

    /**
     * @dataProvider escapingKeys
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('escapingKeys')]
    public function testAKeyThatEscapesTheLocationIsRefused(string $key): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backend->put($key, 'payload', 'image/jpeg');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function escapingKeys(): array
    {
        return [
            'parent hop' => ['../escaped.jpg'],
            'deep parent hop' => ['1/../../escaped.jpg'],
            'walks into the webroot' => ['../../public/escaped.jpg'],
            'backslash parent hop' => ['..\\escaped.jpg'],
        ];
    }

    public function testAnEscapingKeyIsAlsoRefusedOnRead(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->backend->get('../../etc/passwd');
    }

    public function testAWriteThatTheFilesystemRefusedIsRaisedRatherThanReportedAsDone(): void
    {
        // A full disk, a read-only mount, a quota: mkdir() and
        // file_put_contents() return false and the caller used to carry
        // on as if the photo were there — a thumbnail generated from an
        // absent file, a row in the database pointing at nothing. The
        // refusal staged here is a parent that is a FILE, because it is
        // the one the test process cannot be privileged out of: the
        // container runs as root, for whom a chmod 0500 is no obstacle.
        $this->backend->put('1/thumb_1.jpg', 'ok', 'image/jpeg');

        $this->expectException(\RuntimeException::class);
        $this->backend->put('1/thumb_1.jpg/nested.jpg', 'refused', 'image/jpeg');
    }

    public function testASymbolicLinkOutOfTheLocationIsRefusedEvenThoughItsPathReadsAsInside(): void
    {
        // The lexical check reads the TEXT of the path; the filesystem
        // follows the link. "1/away/x.jpg" never leaves the location on
        // paper, and lands outside it on disk.
        $outside = $this->storagePath . '/outside';
        mkdir($outside, 0700, true);
        mkdir($this->storagePath . '/gallery/1', 0700, true);
        symlink($outside, $this->storagePath . '/gallery/1/away');

        $this->expectException(\RuntimeException::class);
        $this->backend->put('1/away/x.jpg', 'payload', 'image/jpeg');
    }

    public function testADotSegmentInsideTheLocationStillResolves(): void
    {
        // "./" and a normalizing ".." that stays inside are fine — only
        // escaping the location's own directory is refused.
        $this->backend->put('9/./thumb_1.jpg', 'ok', 'image/jpeg');

        $this->assertSame('ok', $this->backend->get('9/thumb_1.jpg'));
    }

    public function testItDeclaresRangeReadingAndServerSideCopyAndNothingElse(): void
    {
        $this->assertSame(
            [StorageCapability::RangeRead, StorageCapability::ServerSideCopy],
            $this->backend->capabilities()
        );
        $this->assertTrue($this->backend->supports(StorageCapability::RangeRead));
        // Not signed URLs: the local disk hands a visitor nothing, which
        // is why directUrl() answers null and the consumer serves the
        // bytes through its own access-controlled route.
        $this->assertFalse($this->backend->supports(StorageCapability::SignedUrl));
        $this->assertFalse($this->backend->supports(StorageCapability::Quota));
    }

    public function testAnAbsentCapabilityIsRefusedWithAFrenchSentenceRatherThanAMissingMethod(): void
    {
        $this->expectException(UnsupportedCapabilityException::class);
        $this->expectExceptionMessage("L'emplacement « Disque du serveur » ne sait pas reprendre un envoi interrompu.");

        StorageCapabilities::require(
            $this->backend,
            StorageCapability::ResumableUpload,
            'Disque du serveur'
        );
    }

    public function testRequiringACapabilityTheBackendHasPassesSilently(): void
    {
        StorageCapabilities::require($this->backend, StorageCapability::RangeRead, 'Disque du serveur');

        $this->expectNotToPerformAssertions();
    }

    public function testNoUrlIsServedDirectlyByTheDisk(): void
    {
        $this->backend->put('1/thumb.jpg', 'x', 'image/jpeg');

        $this->assertNull($this->backend->directUrl('1/thumb.jpg'));
        $this->assertNull($this->backend->stableDirectUrl('1/thumb.jpg'));
    }

    public function testDeletingAKeyThatIsNotThereSucceeds(): void
    {
        // Every mechanism that deletes derives its keys from something
        // that can be older than the storage — an inventory, a database
        // restored to last week — so « already gone » is the ordinary
        // case and the desired end state either way.
        $this->backend->delete('1/never-existed.jpg');

        $this->assertFalse($this->backend->exists('1/never-existed.jpg'));
    }

    public function testCopyDuplicatesWithinTheLocation(): void
    {
        $this->backend->put('1/med.jpg', 'bytes', 'image/jpeg');

        $this->backend->copy('1/med.jpg', '2/med.jpg');

        $this->assertSame('bytes', $this->backend->get('2/med.jpg'));
        $this->assertSame('bytes', $this->backend->get('1/med.jpg'));
    }

    public function testListReturnsTheFilesUnderAPrefixWithTheirSizes(): void
    {
        $this->backend->put('7/thumb.jpg', 'abc', 'image/jpeg');
        $this->backend->put('7/med.jpg', 'abcdef', 'image/jpeg');
        $this->backend->put('8/other.jpg', 'x', 'image/jpeg');

        $listing = $this->backend->list('7');

        $this->assertTrue($listing->isComplete());
        $this->assertSame(['7/med.jpg', '7/thumb.jpg'], array_map(fn($o) => $o->key, $listing->objects));
        $this->assertSame([6, 3], array_map(fn($o) => $o->sizeBytes, $listing->objects));
        // A filesystem announces no checksum: a caller that wants one has
        // the file in front of it.
        $this->assertNull($listing->objects[0]->announcedChecksum);
    }

    public function testListPagesWithACursorThatResumesExactlyWhereItStopped(): void
    {
        foreach (['a', 'b', 'c', 'd'] as $name) {
            $this->backend->put("9/{$name}.jpg", $name, 'image/jpeg');
        }

        $first = $this->backend->list('9', null, 2);
        $this->assertSame(['9/a.jpg', '9/b.jpg'], array_map(fn($o) => $o->key, $first->objects));
        $this->assertFalse($first->isComplete());

        $second = $this->backend->list('9', $first->cursor, 2);
        $this->assertSame(['9/c.jpg', '9/d.jpg'], array_map(fn($o) => $o->key, $second->objects));
        $this->assertTrue($second->isComplete());
    }

    public function testListOfAMissingPrefixIsEmptyRatherThanAnError(): void
    {
        $listing = $this->backend->list('does-not-exist');

        $this->assertSame([], $listing->objects);
        $this->assertTrue($listing->isComplete());
    }

    public function testTheConnectionTestWritesReadsAndRemovesAWitness(): void
    {
        $this->assertNull($this->backend->testConnection());

        // Nothing is left behind — a stray witness file would eventually
        // be taken for real content.
        $this->assertSame([], $this->backend->list('')->objects);
    }

    public function testAWitnessThatCannotBeRemovedIsNamedInFrenchRatherThanThrown(): void
    {
        // testConnection() exists to turn a failure into a sentence an
        // administrator can act on. Once delete() started raising on a
        // refused unlink, that sentence became unreachable and the
        // exception travelled instead — out of a method the interface
        // documents as returning ?string and never throwing.
        $backend = new class ($this->storagePath . '/gallery') extends LocalStorageBackend {
            public function delete(string $key): void
            {
                throw new \RuntimeException("Stored file could not be removed: {$key}");
            }
        };

        $this->assertSame("Le fichier témoin n'a pas pu être supprimé.", $backend->testConnection());
    }

    public function testAFailedReadBackIsReportedEvenWhenTheCleanupAlsoFails(): void
    {
        // Two failures at once, and only one of them is the diagnosis: the
        // administrator needs « it could not be read back », not the
        // cleanup that failed on the way out of it.
        $backend = new class ($this->storagePath . '/gallery') extends LocalStorageBackend {
            public function get(string $key): string
            {
                throw new \RuntimeException("Stored file not found: {$key}");
            }

            public function delete(string $key): void
            {
                throw new \RuntimeException("Stored file could not be removed: {$key}");
            }
        };

        $this->assertSame(
            "Le fichier témoin n'a pas pu être relu juste après avoir été écrit.",
            $backend->testConnection()
        );
    }

    public function testTheConnectionTestNamesTheProblemInFrenchWhenTheFolderCannotExist(): void
    {
        $backend = new LocalStorageBackend('/proc/self/cmdline/impossible');

        $error = $backend->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('dossier', $error);
    }
}
