<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Protection;

use Core\Storage\Location\Backend\LocalStorageBackend;
use Core\Storage\Location\Backend\RangeReadableBackend;
use Core\Storage\Location\Protection\CopyOutcome;
use Core\Storage\Location\Protection\ProtectedCopier;
use PHPUnit\Framework\TestCase;

/**
 * Carrying one file to a safety copy: what survives an interruption, what
 * is refused outright, and what happens when the two ends disagree.
 */
final class ProtectedCopierTest extends TestCase
{
    private string $root;
    private LocalStorageBackend $source;
    private LocalStorageBackend $destination;
    private ProtectedCopier $copier;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/protected_copier_' . uniqid();
        mkdir($this->root . '/source', 0755, true);
        mkdir($this->root . '/destination', 0755, true);
        $this->source = new LocalStorageBackend($this->root . '/source');
        $this->destination = new LocalStorageBackend($this->root . '/destination');
        $this->copier = new ProtectedCopier();
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    private function always(): callable
    {
        return static fn(): bool => true;
    }

    public function testASmallFileIsCarriedAcrossWithItsDigest(): void
    {
        $this->source->put('12/med_88.jpg', 'fake-jpeg-bytes', 'image/jpeg');

        $outcome = $this->copier->copy(
            $this->source,
            $this->destination,
            '12/med_88.jpg',
            strlen('fake-jpeg-bytes'),
            'image/jpeg',
            $this->always()
        );

        $this->assertTrue($outcome->isCompleted());
        $this->assertSame(md5('fake-jpeg-bytes'), $outcome->md5);
        $this->assertSame('fake-jpeg-bytes', $this->destination->get('12/med_88.jpg'));
    }

    /**
     * An empty file is a file, and it has to arrive.
     *
     * The slicing path is driven by `while ($offset < $expectedSize)`,
     * which never runs for a zero-byte object — so nothing created the
     * partial, `promotePartial()` threw « no partial upload to promote »,
     * and the pass recorded a failure. Not an edge case anybody would
     * notice as one either: the entry is then removed from the inventory,
     * the same key is met again on the next pass, and it fails again,
     * every night, for ever. A file that can never be protected.
     */
    public function testAnEmptyFileIsCopiedRatherThanFailingForEver(): void
    {
        $this->source->put('12/vide.txt', '', 'text/plain');
        $this->assertTrue(
            $this->source instanceof RangeReadableBackend,
            'this test is only meaningful on the sliced path'
        );

        $outcome = $this->copier->copy(
            $this->source,
            $this->destination,
            '12/vide.txt',
            0,
            'text/plain',
            $this->always()
        );

        $this->assertTrue($outcome->isCompleted(), $outcome->reason ?? '');
        $this->assertTrue($this->destination->exists('12/vide.txt'));
        $this->assertSame('', $this->destination->get('12/vide.txt'));
        $this->assertSame(md5(''), $outcome->md5);
        $this->assertSame(
            0,
            $this->destination->partialSize('12/vide.txt'),
            'and the partial object must not be left behind beside it'
        );
    }

    /**
     * **Resumption after a cut in the middle of a big file, without
     * retransmitting what got through** — the test the chantier asks for
     * by name.
     */
    public function testACopyCutInTheMiddleResumesWithoutResendingWhatArrived(): void
    {
        $payload = random_bytes(ProtectedCopier::CHUNK_BYTES * 3);
        $this->source->put('1/film.mp4', $payload, 'video/mp4');

        // The cut: time runs out after the first slice.
        $slices = 0;
        $firstRun = $this->copier->copy(
            $this->source,
            $this->destination,
            '1/film.mp4',
            strlen($payload),
            'video/mp4',
            static function () use (&$slices): bool {
                return $slices++ < 1;
            }
        );

        $this->assertTrue($firstRun->isPaused(), 'the run must pause rather than fail');
        $this->assertSame(ProtectedCopier::CHUNK_BYTES, $firstRun->bytesTransferred);
        $this->assertSame(
            ProtectedCopier::CHUNK_BYTES,
            $this->destination->partialSize('1/film.mp4'),
            'what arrived must still be there'
        );
        $this->assertFalse(
            $this->destination->exists('1/film.mp4'),
            'and half a film must not be readable under the name of a whole one'
        );

        // The next night: the source is asked only for what is missing.
        $reads = [];
        $counting = new class ($this->root . '/source', $reads) extends LocalStorageBackend {
            /** @param list<array{int, int}> $reads */
            public function __construct(string $root, public array &$reads)
            {
                parent::__construct($root);
            }

            public function getRange(string $key, int $offset, int $length): string
            {
                $this->reads[] = [$offset, $length];

                return parent::getRange($key, $offset, $length);
            }
        };

        $secondRun = $this->copier->copy(
            $counting,
            $this->destination,
            '1/film.mp4',
            strlen($payload),
            'video/mp4',
            $this->always()
        );

        $this->assertTrue($secondRun->isCompleted());
        $this->assertSame($payload, $this->destination->get('1/film.mp4'));
        $this->assertNotSame([], $counting->reads);
        $this->assertSame(
            ProtectedCopier::CHUNK_BYTES,
            $counting->reads[0][0],
            'the resumed run must start reading where the partial object ends, not at zero'
        );
    }

    /**
     * A resumed copy records no digest, and that is a decision rather than
     * an omission: the `hash_init` context cannot cross two runs, and
     * re-reading what already arrived in order to rebuild one would double
     * the transfer — the single cost this whole design exists to avoid.
     * Such a copy is verified on size, exactly as a copy to a destination
     * that announces nothing comparable is.
     */
    public function testACopyThatSpannedTwoRunsRecordsNoDigest(): void
    {
        $payload = random_bytes(ProtectedCopier::CHUNK_BYTES * 2);
        $this->source->put('1/film.mp4', $payload, 'video/mp4');

        $slices = 0;
        $this->copier->copy(
            $this->source,
            $this->destination,
            '1/film.mp4',
            strlen($payload),
            'video/mp4',
            static function () use (&$slices): bool {
                return $slices++ < 1;
            }
        );
        $second = $this->copier->copy(
            $this->source,
            $this->destination,
            '1/film.mp4',
            strlen($payload),
            'video/mp4',
            $this->always()
        );

        $this->assertTrue($second->isCompleted());
        $this->assertNull($second->md5);
    }

    /**
     * A destination that cannot be appended to is refused a file too large
     * to pass through memory — in a French sentence, rather than being
     * handed one that kills the run.
     */
    public function testAFileTooLargeForAnAppendlessDestinationIsRefusedRatherThanAttempted(): void
    {
        $appendless = $this->appendlessDestination();

        $outcome = $this->copier->copy(
            $this->source,
            $appendless,
            '1/film.mp4',
            ProtectedCopier::WHOLE_OBJECT_LIMIT_BYTES + 1,
            'video/mp4',
            $this->always()
        );

        $this->assertSame(CopyOutcome::REFUSED, $outcome->status);
        $this->assertNotNull($outcome->reason);
        $this->assertStringContainsString('reprendre un envoi interrompu', $outcome->reason);
    }

    public function testASmallFileGoesToAnAppendlessDestinationInOnePiece(): void
    {
        $this->source->put('12/med_88.jpg', 'fake-jpeg-bytes', 'image/jpeg');
        $appendless = $this->appendlessDestination();

        $outcome = $this->copier->copy(
            $this->source,
            $appendless,
            '12/med_88.jpg',
            strlen('fake-jpeg-bytes'),
            'image/jpeg',
            $this->always()
        );

        $this->assertTrue($outcome->isCompleted());
        $this->assertSame(md5('fake-jpeg-bytes'), $outcome->md5);
    }

    /**
     * **On disagreement the copy is removed, not left in place** (D14).
     * A wrong object under the right key is worse than no object: the next
     * pass sees the key, sees a size it cannot check, and records the file
     * as protected.
     */
    public function testACopyThatDisagreesOnSizeIsDeletedRatherThanKept(): void
    {
        $this->source->put('12/med_88.jpg', 'fake-jpeg-bytes', 'image/jpeg');

        $outcome = $this->copier->copy(
            $this->source,
            $this->destination,
            '12/med_88.jpg',
            999_999,
            'image/jpeg',
            $this->always()
        );

        $this->assertSame(CopyOutcome::FAILED, $outcome->status);
        $this->assertFalse(
            $this->destination->exists('12/med_88.jpg'),
            'a file that disagrees with its source must not stay under the right key'
        );
        $this->assertSame(0, $this->destination->partialSize('12/med_88.jpg'));
    }

    /**
     * **The S3 trap.** An ETag equals the object's MD5 only for an upload
     * sent in one piece; for a multipart one it is a digest of the part
     * digests with the part count after a hyphen. Comparing that to an MD5
     * fails every time, and whoever read the result would conclude that
     * every copy this site ever made is corrupt.
     */
    public function testAMultipartStyleChecksumIsNotComparedToAnMd5(): void
    {
        $this->source->put('12/med_88.jpg', 'fake-jpeg-bytes', 'image/jpeg');
        $multipart = $this->destinationAnnouncing('"d41d8cd98f00b204e9800998ecf8427e-12"');

        $outcome = $this->copier->copy(
            $this->source,
            $multipart,
            '12/med_88.jpg',
            strlen('fake-jpeg-bytes'),
            'image/jpeg',
            $this->always()
        );

        $this->assertTrue(
            $outcome->isCompleted(),
            'a multipart ETag must not be read as a mismatched MD5'
        );
    }

    /** And a real MD5 that disagrees IS a disagreement. */
    public function testAnAnnouncedMd5ThatDisagreesFailsTheCopy(): void
    {
        $this->source->put('12/med_88.jpg', 'fake-jpeg-bytes', 'image/jpeg');
        $lying = $this->destinationAnnouncing(str_repeat('b', 32));

        $outcome = $this->copier->copy(
            $this->source,
            $lying,
            '12/med_88.jpg',
            strlen('fake-jpeg-bytes'),
            'image/jpeg',
            $this->always()
        );

        $this->assertSame(CopyOutcome::FAILED, $outcome->status);
        $this->assertSame('L\'empreinte de la copie ne correspond pas à la source.', $outcome->reason);
    }

    /** And a matching one passes. */
    public function testAnAnnouncedMd5ThatAgreesPassesTheCopy(): void
    {
        $this->source->put('12/med_88.jpg', 'fake-jpeg-bytes', 'image/jpeg');
        $honest = $this->destinationAnnouncing(md5('fake-jpeg-bytes'));

        $outcome = $this->copier->copy(
            $this->source,
            $honest,
            '12/med_88.jpg',
            strlen('fake-jpeg-bytes'),
            'image/jpeg',
            $this->always()
        );

        $this->assertTrue($outcome->isCompleted());
    }

    /**
     * A partial object longer than the file it copies describes a source
     * that changed under it: nothing can be salvaged, and resuming would
     * continue into the middle of something else.
     */
    public function testAPartialObjectLongerThanItsSourceIsThrownAwayAndTheCopyRestarts(): void
    {
        $this->source->put('12/med_88.jpg', 'short', 'image/jpeg');
        $this->destination->appendToPartial('12/med_88.jpg', str_repeat('x', 4096));

        $outcome = $this->copier->copy(
            $this->source,
            $this->destination,
            '12/med_88.jpg',
            5,
            'image/jpeg',
            $this->always()
        );

        $this->assertTrue($outcome->isCompleted());
        $this->assertSame('short', $this->destination->get('12/med_88.jpg'));
    }

    // ————— Stand-ins —————

    /**
     * A destination that can store and read but cannot be appended to —
     * the shape of every backend that has not declared
     * `ResumableUpload`.
     */
    private function appendlessDestination(): \Core\Storage\Location\Backend\StorageBackendInterface
    {
        return new class ($this->root . '/appendless') implements
            \Core\Storage\Location\Backend\StorageBackendInterface {
            /** @var array<string, string> */
            private array $objects = [];

            public function __construct(private string $root)
            {
            }

            public function capabilities(): array
            {
                return [];
            }

            public function supports(\Core\Storage\Location\StorageCapability $capability): bool
            {
                return false;
            }

            public function put(string $key, string $contents, string $mimeType): void
            {
                $this->objects[$key] = $contents;
            }

            public function get(string $key): string
            {
                return $this->objects[$key] ?? throw new \RuntimeException('absent');
            }

            public function size(string $key): ?int
            {
                return isset($this->objects[$key]) ? strlen($this->objects[$key]) : null;
            }

            public function exists(string $key): bool
            {
                return isset($this->objects[$key]);
            }

            public function delete(string $key): void
            {
                unset($this->objects[$key]);
            }

            public function deletePrefix(string $prefix): void
            {
                foreach (array_keys($this->objects) as $key) {
                    if (str_starts_with($key, $prefix)) {
                        unset($this->objects[$key]);
                    }
                }
            }

            public function list(
                string $prefix,
                ?string $cursor = null,
                int $limit = 1000
            ): \Core\Storage\Location\StorageListing {
                return new \Core\Storage\Location\StorageListing([]);
            }

            public function localPath(string $key): ?string
            {
                return null;
            }

            public function directUrl(string $key, string $ttl = '+1 hour'): ?string
            {
                return null;
            }

            public function stableDirectUrl(string $key): ?string
            {
                return null;
            }

            public function announcedChecksum(string $key): ?string
            {
                return null;
            }

            public function testConnection(): ?string
            {
                return null;
            }
        };
    }

    /**
     * A local destination that answers a checksum of the test's choosing —
     * which is how a bucket's ETag is reproduced without a bucket.
     */
    private function destinationAnnouncing(string $checksum): LocalStorageBackend
    {
        return new class ($this->root . '/announcing', $checksum) extends LocalStorageBackend {
            public function __construct(string $root, private string $announced)
            {
                parent::__construct($root);
            }

            public function announcedChecksum(string $key): ?string
            {
                return $this->announced;
            }
        };
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir((string) $item) : unlink((string) $item);
        }
        rmdir($dir);
    }
}
