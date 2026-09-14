<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend;

use Core\Storage\Location\Backend\Drive\DriveAccessException;
use Core\Storage\Location\Backend\GoogleDriveBackend;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Config\GoogleDriveSecret;
use Core\Storage\Location\StorageCapability;
use PHPUnit\Framework\TestCase;
use Tests\Core\Storage\Location\Backend\Drive\FakeDrive;

/**
 * A Drive folder behaving like a storage location.
 *
 * Every case here is written against {@see FakeDrive}, which holds state
 * across calls, so what is asserted is what ends up in the folder rather
 * than which methods were called in which order.
 */
final class GoogleDriveBackendTest extends TestCase
{
    private FakeDrive $drive;

    protected function setUp(): void
    {
        $this->drive = new FakeDrive();
    }

    public function testItDeclaresOnlyWhatItCanActuallyDo(): void
    {
        $backend = $this->backend();

        $this->assertTrue($backend->supports(StorageCapability::ResumableUpload));
        $this->assertTrue($backend->supports(StorageCapability::Quota));
        $this->assertTrue($backend->supports(StorageCapability::Checksum));

        // The two that keep this destination out of the gallery until
        // IT-07 gives it the methods. A capability announced ahead of its
        // implementation is the lie the enum exists to prevent.
        $this->assertFalse($backend->supports(StorageCapability::RangeRead));
        $this->assertFalse($backend->supports(StorageCapability::SignedUrl));
        $this->assertFalse($backend->supports(StorageCapability::ServerSideCopy));
    }

    public function testAnObjectIsWrittenReadBackAndRemoved(): void
    {
        $backend = $this->backend();

        $backend->put('rapport.txt', 'bonjour', 'text/plain');

        $this->assertTrue($backend->exists('rapport.txt'));
        $this->assertSame('bonjour', $backend->get('rapport.txt'));
        $this->assertSame(7, $backend->size('rapport.txt'));
        $this->assertSame(md5('bonjour'), $backend->announcedChecksum('rapport.txt'));

        $backend->delete('rapport.txt');
        $this->assertFalse($backend->exists('rapport.txt'));
    }

    /**
     * **A key that is not there is a success**, which every backend
     * promises and which a purge depends on: its list of keys comes from
     * something that can be older than the storage, so « already gone » is
     * the ordinary case and the desired end state either way.
     */
    public function testDeletingSomethingThatIsNotThereIsNotAFailure(): void
    {
        $this->backend()->delete('jamais-ecrit.txt');

        $this->assertSame([], $this->drive->names());
    }

    /**
     * **Drive allows two files to share a name and a storage key may
     * not.** A reader and a deleter picking different files is worse than
     * either failing, so a write collapses the namesakes it creates.
     */
    public function testWritingOverAKeyLeavesExactlyOneFileUnderIt(): void
    {
        $this->drive->put('rapport.txt', 'vieux');
        $backend = $this->backend();

        $backend->put('rapport.txt', 'neuf', 'text/plain');

        $this->assertSame(1, $this->drive->countNamed('rapport.txt'));
        $this->assertSame('neuf', $this->drive->contentOf('rapport.txt'));
    }

    public function testAListingHidesThisApplicationsOwnBookkeeping(): void
    {
        $this->drive->put('album/1.jpg', 'a');
        $this->drive->put('album/2.jpg', 'b');
        $this->drive->put('autre.txt', 'c');
        $this->drive->put('.scoutmagic-part-abc.json', '{}');

        $listing = $this->backend()->list('');

        $this->assertSame(
            ['album/1.jpg', 'album/2.jpg', 'autre.txt'],
            $this->sortedKeys($listing->objects)
        );
    }

    public function testAListingFiltersOnThePrefixItWasGiven(): void
    {
        $this->drive->put('album/1.jpg', 'a');
        $this->drive->put('autre.txt', 'c');

        $listing = $this->backend()->list('album/');

        $this->assertSame(['album/1.jpg'], $this->sortedKeys($listing->objects));
    }

    public function testDeletingAPrefixLeavesEverythingElseAlone(): void
    {
        $this->drive->put('album/1.jpg', 'a');
        $this->drive->put('album/2.jpg', 'b');
        $this->drive->put('autre.txt', 'c');

        $this->backend()->deletePrefix('album/');

        $this->assertSame(['autre.txt'], $this->drive->names());
    }

    /**
     * **An empty prefix is a no-op, never « everything ».** A bug that
     * produced one must not erase the operator's whole folder.
     */
    public function testDeletingAnEmptyPrefixRemovesNothing(): void
    {
        $this->drive->put('album/1.jpg', 'a');

        $this->backend()->deletePrefix('');

        $this->assertSame(['album/1.jpg'], $this->drive->names());
    }

    /**
     * A small object needs no session, and that is measurable: one
     * request to write it and one to collapse its namesakes, against the
     * five a session would have cost.
     */
    public function testASmallUploadNeedsNoSessionAndLeavesNoBookkeepingBehind(): void
    {
        $backend = $this->backend();

        $backend->beginPartial('petit.txt', 6);
        $backend->appendToPartial('petit.txt', 'petit!');
        $backend->promotePartial('petit.txt', 'text/plain');

        $this->assertSame('petit!', $this->drive->contentOf('petit.txt'));
        $this->assertSame(['petit.txt'], $this->drive->names(), 'a bookkeeping object was left in the folder');
        $this->assertSame([], $this->drive->sessions);
    }

    /**
     * **And a small upload costs two requests, not five.**
     *
     * Asserted as a number because the cost is the whole reason the
     * buffered branch exists: a gallery copying ten thousand thumbnails
     * pays this per file. A session would be open, write the bookkeeping
     * object, send, de-duplicate and remove the note — and the lookup for
     * a note that has never existed for a key this size is the one that
     * would creep back in unnoticed.
     */
    public function testASmallUploadCostsOneWriteAndOneDeduplication(): void
    {
        $backend = $this->backend();
        // The token refresh, once per instance, out of the way first.
        $backend->beginPartial('chauffe.txt', 1);
        $backend->appendToPartial('chauffe.txt', 'x');
        $backend->promotePartial('chauffe.txt', 'text/plain');

        $before = $this->drive->requests;
        $backend->beginPartial('petit.txt', 6);
        $backend->appendToPartial('petit.txt', 'petit!');
        $backend->promotePartial('petit.txt', 'text/plain');

        $this->assertSame(2, $this->drive->requests - $before);
    }

    /**
     * **Nothing is readable under the key until the upload finishes**,
     * which the interface requires: a consumer listing this location
     * mid-copy must see no object rather than half of one.
     */
    public function testALargeUploadIsInvisibleUntilItIsPromoted(): void
    {
        $backend = $this->backend();
        $size = GoogleDriveBackend::BUFFERED_UPLOAD_LIMIT_BYTES + 1024;

        $backend->beginPartial('film.mp4', $size);
        $backend->appendToPartial('film.mp4', str_repeat('a', 1024));

        $this->assertFalse($backend->exists('film.mp4'));
        $this->assertSame([], $this->sortedKeys($backend->list('')->objects));
    }

    /**
     * **Resuming continues from what the destination holds, not from
     * zero.** This is the non-regression the whole iteration turns on: a
     * backup of several gibibytes crosses as many runs as it needs, and a
     * run that started over each night would never finish one.
     */
    public function testASecondRunResumesTheSessionTheFirstOneOpened(): void
    {
        $limit = GoogleDriveBackend::BUFFERED_UPLOAD_LIMIT_BYTES;
        $first = str_repeat('a', $limit);
        $second = str_repeat('b', 1024);
        $size = strlen($first) + strlen($second);

        // Night one: opens the session and sends the first half.
        $one = $this->backend();
        $one->beginPartial('archive.zip', $size);
        $one->appendToPartial('archive.zip', $first);

        // Night two: a brand new object, with nothing in memory. It has
        // to find the session in the destination and pick up from there.
        $two = $this->backend();
        $this->assertSame($limit, $two->partialSize('archive.zip'));
        $two->appendToPartial('archive.zip', $second);
        $two->promotePartial('archive.zip', 'application/zip');

        $this->assertSame($first . $second, $this->drive->contentOf('archive.zip'));
        $this->assertNotContains('.scoutmagic-part-' . sha1('archive.zip') . '.json', $this->drive->names());
    }

    /**
     * **The whole chunk is stored when `appendToPartial()` returns, or it
     * throws.** Google is allowed to keep fewer bytes than were sent and
     * say so; a caller advancing by what it handed over would then read
     * its next slice from past the hole, and the archive would upload, be
     * accepted, and be unreadable on the day it was needed.
     */
    public function testAChunkGoogleOnlyHalfKeepsIsFinishedRatherThanReportedAsStored(): void
    {
        $limit = GoogleDriveBackend::BUFFERED_UPLOAD_LIMIT_BYTES;
        $payload = str_repeat('a', $limit) . str_repeat('b', 2048);
        $backend = $this->backend();

        $backend->beginPartial('archive.zip', strlen($payload));
        // Google keeps only the first kibibyte of what this call sends.
        $this->drive->commitOnly = 1024;
        $backend->appendToPartial('archive.zip', $payload);

        $this->assertSame(strlen($payload), $backend->partialSize('archive.zip'));
        $backend->promotePartial('archive.zip', 'application/zip');
        $this->assertSame($payload, $this->drive->contentOf('archive.zip'));
    }

    /**
     * A session Google has forgotten is not an offset of zero on a live
     * transfer — it is no transfer at all, and the note describing it has
     * to go so the next call opens a fresh one.
     */
    public function testASessionTheDestinationHasForgottenIsStartedAgain(): void
    {
        $limit = GoogleDriveBackend::BUFFERED_UPLOAD_LIMIT_BYTES;
        $one = $this->backend();
        $one->beginPartial('archive.zip', $limit + 1024);
        $one->appendToPartial('archive.zip', str_repeat('a', $limit));

        // Google loses the session; the note in the folder still points
        // at it.
        $this->drive->sessions = [];

        $two = $this->backend();
        $this->assertSame(0, $two->partialSize('archive.zip'));
        $this->assertNotContains(
            '.scoutmagic-part-' . sha1('archive.zip') . '.json',
            $this->drive->names(),
            'a note pointing at a dead session was kept'
        );
    }

    public function testAnAbandonedUploadIsCancelledAtTheDestination(): void
    {
        $limit = GoogleDriveBackend::BUFFERED_UPLOAD_LIMIT_BYTES;
        $backend = $this->backend();
        $backend->beginPartial('archive.zip', $limit + 1024);
        $backend->appendToPartial('archive.zip', str_repeat('a', $limit));

        $backend->discardPartial('archive.zip');

        $this->assertSame([], $this->drive->sessions);
        $this->assertSame([], $this->drive->names());
    }

    /**
     * Promoting an upload that is not finished is a refusal, not a file.
     * Half an archive under the name of a whole one is what every
     * `promotePartial()` in this codebase exists to prevent.
     */
    public function testPromotingAnUnfinishedUploadIsRefused(): void
    {
        $limit = GoogleDriveBackend::BUFFERED_UPLOAD_LIMIT_BYTES;
        $backend = $this->backend();
        $backend->beginPartial('archive.zip', $limit + 4096);
        $backend->appendToPartial('archive.zip', str_repeat('a', $limit));

        $this->expectException(\RuntimeException::class);
        $backend->promotePartial('archive.zip', 'application/zip');
    }

    /**
     * An empty file still has to be materialised: `list()` reports a
     * zero-byte object like any other, and a key that can never be copied
     * fails every night for ever, silently.
     */
    public function testAZeroByteObjectIsStoredRatherThanSkipped(): void
    {
        $backend = $this->backend();

        $backend->beginPartial('vide.txt', 0);
        $backend->appendToPartial('vide.txt', '');
        $backend->promotePartial('vide.txt', 'text/plain');

        $this->assertTrue($backend->exists('vide.txt'));
        $this->assertSame('', $this->drive->contentOf('vide.txt'));
    }

    public function testTheQuotaIsWhatTheAccountSays(): void
    {
        $quota = $this->backend()->quota();

        $this->assertNotNull($quota);
        $this->assertSame(400, $quota->usedBytes);
        $this->assertSame(600, $quota->freeBytes());
    }

    /**
     * **A test writes and reads back**, because a destination that
     * answers `about` happily may still refuse every write — and the
     * operator who saw a green tick would learn that on the night their
     * server burned down. The witness never survives it.
     */
    public function testAConnectionTestWritesAWitnessAndRemovesItAgain(): void
    {
        $this->assertNull($this->backend()->testConnection());
        $this->assertSame([], $this->drive->names(), 'the witness was left in the operator\'s Drive');
    }

    public function testAConnectionTestWithoutAGrantSaysSoRatherThanCallingGoogle(): void
    {
        $backend = new GoogleDriveBackend(
            $this->drive->client(),
            new GoogleDriveLocationConfig('client-1', $this->drive->folderId),
            new GoogleDriveSecret('secret-1')
        );

        $this->assertSame(
            'Aucun compte Google Drive n\'est raccordé à cet emplacement.',
            $backend->testConnection()
        );
        $this->assertSame(0, $this->drive->requests);
    }

    /**
     * **Never throws**, per the interface: a failed test is an answer, and
     * the button exists to give the administrator that answer rather than
     * an error page. The sentence is French because `DriveAccessException`
     * is a `UserFacingException` and wrote it at its own throw site.
     */
    public function testAConnectionTestGoogleRefusesComesBackAsASentence(): void
    {
        $this->drive->failNextWith = 500;

        $message = $this->backend()->testConnection();

        $this->assertNotNull($message);
        $this->assertStringNotContainsString('Exception', $message);
    }

    public function testAnOperationWithoutAGrantIsRefusedInFrench(): void
    {
        $backend = new GoogleDriveBackend(
            $this->drive->client(),
            new GoogleDriveLocationConfig('client-1', $this->drive->folderId),
            new GoogleDriveSecret('secret-1')
        );

        $this->expectException(DriveAccessException::class);
        $this->expectExceptionMessage('Aucun compte Google Drive');
        $backend->put('a.txt', 'x', 'text/plain');
    }

    /**
     * A location whose row carries no folder yet resolves one on first
     * use — an operator may have emptied their trash, and under
     * `drive.file` a folder this application cannot see is one that no
     * longer exists as far as it is concerned.
     */
    public function testAFolderIsCreatedWhenTheRowCarriesNone(): void
    {
        $backend = new GoogleDriveBackend(
            $this->drive->client(),
            new GoogleDriveLocationConfig('client-1', ''),
            new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org')
        );

        $backend->put('a.txt', 'x', 'text/plain');

        $this->assertSame('x', $this->drive->contentOf('a.txt'));
    }

    /** Nothing here is a local file, and nothing hands out a public URL. */
    public function testItOffersNeitherALocalPathNorAPublicUrl(): void
    {
        $backend = $this->backend();

        $this->assertNull($backend->localPath('a.txt'));
        $this->assertNull($backend->directUrl('a.txt'));
        $this->assertNull($backend->stableDirectUrl('a.txt'));
    }

    private function backend(): GoogleDriveBackend
    {
        return new GoogleDriveBackend(
            $this->drive->client(),
            new GoogleDriveLocationConfig('client-1', $this->drive->folderId, '2026-09-01T00:00:00+00:00'),
            new GoogleDriveSecret('secret-1', 'refresh-1', 'unite@example.org')
        );
    }

    /**
     * @param list<\Core\Storage\Location\StoredObject> $objects
     * @return list<string>
     */
    private function sortedKeys(array $objects): array
    {
        $keys = array_map(static fn ($o): string => $o->key, $objects);
        sort($keys);

        return array_values($keys);
    }
}
