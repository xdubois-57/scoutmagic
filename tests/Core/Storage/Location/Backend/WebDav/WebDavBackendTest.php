<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend\WebDav;

use Core\Storage\Location\Backend\WebDav\WebDavAccessException;
use Core\Storage\Location\Backend\WebDav\WebDavClient;
use Core\Storage\Location\Backend\WebDavBackend;
use Core\Storage\Location\Config\WebDavLocationConfig;
use Core\Storage\Location\StorageCapability;
use PHPUnit\Framework\TestCase;

/**
 * The WebDAV backend, against a share that keeps what it is given.
 *
 * **Not one byte of this suite crosses the network**, and none of it
 * asserts on a mock's expectations: {@see FakeWebDavServer} holds a
 * folder, so a backend that wrote the same bytes twice, or wrote them
 * under a name nobody asked for, is visible in what the share ends up
 * holding rather than in a call count.
 */
final class WebDavBackendTest extends TestCase
{
    private FakeWebDavServer $share;

    protected function setUp(): void
    {
        $this->share = new FakeWebDavServer();
    }

    // ———— What the roadmap asks to be able to do ————

    public function testAnObjectIsWrittenAndReadBack(): void
    {
        $backend = $this->backend();

        $backend->put('12/med_3.jpg', 'les octets de la photo', 'image/jpeg');

        $this->assertSame('les octets de la photo', $backend->get('12/med_3.jpg'));
        $this->assertSame(22, $backend->size('12/med_3.jpg'));
        $this->assertTrue($backend->exists('12/med_3.jpg'));
    }

    /**
     * **The capability that makes a film playable**, and the reason this
     * type of storage is worth having where Drive is not.
     */
    public function testASliceIsReadWithoutFetchingTheWholeObject(): void
    {
        $backend = $this->backend();
        $backend->put('12/film.mp4', 'ABCDEFGHIJ', 'video/mp4');

        $this->assertSame('DEF', $backend->getRange('12/film.mp4', 3, 3));
        $this->assertTrue($backend->supports(StorageCapability::RangeRead));
    }

    /**
     * **A share that ignores `Range:` is refused rather than truncated.**
     * It answers 200 with the WHOLE object, and a backend that trusted
     * the status would hand a browser a film where it asked for a second
     * of one — an unseekable share would look like a working one, which
     * is exactly what this capability exists to rule out.
     */
    public function testAShareThatIgnoresRangeIsNamedRatherThanSilentlyServingEverything(): void
    {
        $backend = $this->backend();
        $backend->put('12/film.mp4', 'ABCDEFGHIJ', 'video/mp4');
        $this->share->ignoresRange = true;

        $this->expectException(WebDavAccessException::class);
        $this->expectExceptionMessage('extrait de fichier');
        $backend->getRange('12/film.mp4', 3, 3);
    }

    public function testAnObjectIsDeleted(): void
    {
        $backend = $this->backend();
        $backend->put('12/med_3.jpg', 'x', 'image/jpeg');

        $backend->delete('12/med_3.jpg');

        $this->assertSame([], $this->share->files, 'the file is still on the share');
        $this->assertFalse($backend->exists('12/med_3.jpg'));
    }

    /** A key that is not there is a success: the desired end state is reached. */
    public function testDeletingSomethingThatIsNotThereIsNotAFailure(): void
    {
        $backend = $this->backend();
        $backend->put('12/med_3.jpg', 'x', 'image/jpeg');

        $backend->delete('12/never-written.jpg');

        // The neighbour is still there: « not found » is answered by
        // doing nothing, never by clearing the share.
        $this->assertSame(['/dav/scoutmagic/12/med_3.jpg'], array_keys($this->share->files));
    }

    public function testTheShareSaysHowMuchRoomIsLeft(): void
    {
        $this->share->quotaAvailable = 6_000;
        $this->share->quotaUsed = 4_000;

        $quota = $this->backend()->quota();

        $this->assertNotNull($quota);
        $this->assertSame(4_000, $quota->usedBytes);
        $this->assertSame(10_000, $quota->limitBytes);
        $this->assertSame(6_000, $quota->freeBytes());
    }

    /**
     * A server with no notion of quotas answers « unknown », never
     * « nothing free »: a screen must show nothing rather than a
     * reassuring zero.
     */
    public function testAShareThatWillNotSayItsQuotaAnswersUnknown(): void
    {
        $this->assertNull($this->backend()->quota());
    }

    // ———— Each failure named, never a bare exception ————

    public function testWrongCredentialsSaySoAndNameTheRemedy(): void
    {
        $this->share->refuseWith = 401;

        $message = $this->backend()->testConnection();

        $this->assertNotNull($message);
        $this->assertStringContainsString('identifiants', $message);
        $this->assertStringContainsString('mot de passe d\'application', $message);
    }

    public function testAPathThatIsNotThereSaysSoRatherThanBlamingTheCredentials(): void
    {
        $this->share->refuseWith = 404;

        $message = $this->backend()->testConnection();

        $this->assertNotNull($message);
        $this->assertStringContainsString('n\'existe pas', $message);
        $this->assertStringNotContainsString('identifiants', $message);
    }

    /**
     * An invalid certificate arrives as a transport failure, and must not
     * reach the operator as cURL's English words about issuer chains.
     */
    public function testAnInvalidCertificateIsAFrenchSentenceAndNotCurlsWords(): void
    {
        $this->share->connectionFails = true;

        $message = $this->backend()->testConnection();

        $this->assertNotNull($message);
        $this->assertStringContainsString('certificat', $message);
        $this->assertStringNotContainsString('SSL certificate problem', $message);
    }

    /** A healthy share answers null — nothing to report is the success. */
    public function testAReachableShareReportsNothing(): void
    {
        $this->assertNull($this->backend()->testConnection());
    }

    // ———— The shape of what it writes ————

    /**
     * **Every level of collection, from the outside in.** WebDAV has no
     * « create intermediate folders » flag: a PUT into a folder that is
     * not there answers 409, and `MKCOL` makes exactly one level, so
     * creating `a/b` bottom-up fails on the first call.
     */
    public function testTheFoldersAKeyNeedsAreCreatedBeforeItIsWritten(): void
    {
        $this->backend()->put('12/vignettes/med_3.jpg', 'x', 'image/jpeg');

        $made = array_values(array_filter(
            $this->share->calls,
            static fn (array $call): bool => $call['method'] === 'MKCOL'
        ));

        $this->assertSame(
            ['/dav/scoutmagic/12', '/dav/scoutmagic/12/vignettes'],
            array_map(static fn (array $c): string => parse_url($c['url'], PHP_URL_PATH) ?: '', $made),
            'the folders were not created from the outside in'
        );
    }

    /**
     * **Each segment encoded on its own.** Encoding the whole key would
     * turn the separating slashes into `%2F`, and the share would hold
     * one file literally named `12/med_3.jpg` instead of a folder holding
     * a file.
     */
    public function testASlashSeparatesFoldersRatherThanBeingEncodedIntoTheName(): void
    {
        $this->backend()->put('12/une photo.jpg', 'x', 'image/jpeg');

        $this->assertArrayHasKey('/dav/scoutmagic/12/une photo.jpg', $this->share->files);
    }

    /** The credentials travel, and as Basic authentication. */
    public function testTheCredentialsTravelWithEveryRequest(): void
    {
        $backend = $this->backend();
        $backend->put('12/med_3.jpg', 'x', 'image/jpeg');
        $backend->get('12/med_3.jpg');

        $this->assertSame('Basic ' . base64_encode('unite:le-mot-de-passe'), $this->share->lastAuth);
    }

    /**
     * Listing skips the collection itself and reports the objects under
     * it, with the size the share announced.
     */
    public function testListingReportsTheObjectsAndNotTheFolderHoldingThem(): void
    {
        $backend = $this->backend();
        $backend->put('12/a.jpg', 'aa', 'image/jpeg');
        $backend->put('12/b.jpg', 'bbbb', 'image/jpeg');

        $objects = $backend->list('12')->objects;

        $keys = array_map(static fn (object $o): string => $o->key, $objects);
        sort($keys);
        $this->assertSame(['12/a.jpg', '12/b.jpg'], $keys);
        $this->assertSame([2, 4], array_map(static fn (object $o): int => $o->sizeBytes, $objects));
    }

    /**
     * **An empty prefix is refused.** It would resolve to the share's own
     * root and remove files that have nothing to do with this site — an
     * operator's WebDAV is their own folder, not a bucket this
     * application owns.
     */
    public function testDeletingAnEmptyPrefixTouchesNothing(): void
    {
        $backend = $this->backend();
        $backend->put('12/a.jpg', 'x', 'image/jpeg');

        $backend->deletePrefix('');

        $this->assertArrayHasKey('/dav/scoutmagic/12/a.jpg', $this->share->files);
    }

    public function testDeletingAPrefixRemovesEverythingUnderIt(): void
    {
        $backend = $this->backend();
        $backend->put('12/a.jpg', 'x', 'image/jpeg');
        $backend->put('12/b.jpg', 'y', 'image/jpeg');
        $backend->put('13/c.jpg', 'z', 'image/jpeg');

        $backend->deletePrefix('12');

        $this->assertSame(['/dav/scoutmagic/13/c.jpg'], array_keys($this->share->files));
    }

    /**
     * Null on both, like the local disk: there is no URL a visitor could
     * use, so the consumer serves the object through its own route.
     */
    public function testNoUrlIsHandedToAVisitor(): void
    {
        $backend = $this->backend();

        $this->assertNull($backend->directUrl('12/a.jpg'));
        $this->assertNull($backend->stableDirectUrl('12/a.jpg'));
        $this->assertNull($backend->localPath('12/a.jpg'));
        $this->assertFalse($backend->supports(StorageCapability::SignedUrl));
    }

    // ———— What a 206 has to prove beyond its status ————

    /**
     * **A status is not a slice.** A share, or something between this
     * server and it, can answer 206 and hand over another part of the
     * file; those bytes would go straight into the gallery's own 206,
     * under a `Content-Range` this site computed from the object's size.
     * The player then gets a header that does not describe its payload.
     */
    public function testASliceThatIsNotTheOneAskedForIsRefused(): void
    {
        $backend = $this->backend();
        $backend->put('film.mp4', '0123456789abcdefghij', 'video/mp4');
        $this->share->contentRangeOverride = 'bytes 0-4/20';

        $this->expectException(WebDavAccessException::class);
        $this->expectExceptionMessage('ne correspond pas');
        $backend->getRange('film.mp4', 10, 5);
    }

    public function testASliceOfTheWrongLengthIsRefused(): void
    {
        $backend = $this->backend();
        $backend->put('film.mp4', '0123456789abcdefghij', 'video/mp4');
        $this->share->sliceOverride = 'beaucoup trop long pour cinq octets';

        $this->expectException(WebDavAccessException::class);
        $backend->getRange('film.mp4', 10, 5);
    }

    /**
     * A 206 must carry `Content-Range`; one that does not has told us
     * nothing about what its body is.
     */
    public function testA206WithoutAContentRangeIsRefused(): void
    {
        $backend = $this->backend();
        $backend->put('film.mp4', '0123456789abcdefghij', 'video/mp4');
        $this->share->contentRangeOverride = '';

        $this->expectException(WebDavAccessException::class);
        $backend->getRange('film.mp4', 10, 5);
    }

    /**
     * **And a short answer at the end of a file is not a wrong one.** RFC
     * 9110 has a server clamp a range running past the object, which is
     * what reading a file's tail meets on every ordinary share.
     */
    public function testASliceClampedToTheEndOfTheFileIsAccepted(): void
    {
        $backend = $this->backend();
        $backend->put('film.mp4', '0123456789', 'video/mp4');

        $this->assertSame('89', $backend->getRange('film.mp4', 8, 100));
    }

    // ———— A refusal is not an absence ————

    /**
     * **`exists()` answering « non » for a share that is down is the
     * dangerous shape.** A repatriation reads it as « the source lost this
     * file », a safety copy as « re-send everything ». Only a 404 is an
     * absence; the rest has to arrive as a failure.
     */
    public function testAShareThatRefusesTheCredentialsIsNotAnAbsence(): void
    {
        $backend = $this->backend();
        $backend->put('photo.jpg', 'x', 'image/jpeg');
        $this->share->refuseWith = 401;

        $this->expectException(WebDavAccessException::class);
        $backend->exists('photo.jpg');
    }

    public function testAnObjectThatIsGenuinelyNotThereIsAnAbsence(): void
    {
        $this->assertFalse($this->backend()->exists('jamais-ecrit.jpg'));
    }

    /**
     * Null from `announcedChecksum()` means « this destination says
     * nothing comparable », and a verification reads that as « skip the
     * comparison ». A share that is down must not make every file pass.
     */
    public function testAnUnreachableShareDoesNotAnnounceAnEmptyChecksum(): void
    {
        $backend = $this->backend();
        $backend->put('photo.jpg', 'x', 'image/jpeg');
        $this->share->refuseWith = 503;

        $this->expectException(WebDavAccessException::class);
        $backend->announcedChecksum('photo.jpg');
    }

    /**
     * `size()` is the one that still answers null, and on purpose: the
     * gallery calls it on a `Range:` request and falls through to an
     * ordinary read when it cannot say, where a throw would turn a share
     * outage into a 500 on a visitor's page.
     */
    public function testSizeStillAnswersUnknownRatherThanThrowingAtAVisitor(): void
    {
        $backend = $this->backend();
        $backend->put('photo.jpg', 'x', 'image/jpeg');
        $this->share->refuseWith = 503;

        $this->assertNull($backend->size('photo.jpg'));
    }

    // ———— Listing a share whose files are not at its root ————

    /**
     * **The bug this replaced would have copied nothing and said it was
     * done.** `PROPFIND` at `Depth: 1` answers one collection's direct
     * children, the gallery's keys are `{albumId}/med_{mediaId}.jpg`, and
     * a single call on the share root therefore sees album FOLDERS and not
     * one media file.
     */
    public function testListingReachesTheFilesInsideTheAlbumFolders(): void
    {
        $backend = $this->backend();
        $backend->put('12/a.jpg', 'aa', 'image/jpeg');
        $backend->put('13/b.jpg', 'bbbb', 'image/jpeg');

        $this->assertSame(['12/a.jpg', '13/b.jpg'], $this->keysOf($backend->list('')));
    }

    /**
     * **A truncated page says so.** `StorageListing::isComplete()` reads a
     * null cursor as « that was everything », so a copy walking pages
     * would have stopped at the first one and reported success.
     */
    public function testAPageThatIsNotTheWholeCollectionCarriesACursor(): void
    {
        $backend = $this->backend();
        foreach (['a', 'b', 'c'] as $name) {
            $backend->put('12/' . $name . '.jpg', 'x', 'image/jpeg');
        }

        $first = $backend->list('', null, 2);

        $this->assertSame(['12/a.jpg', '12/b.jpg'], $this->keysOf($first));
        $this->assertFalse($first->isComplete());
        $this->assertSame('12/b.jpg', $first->cursor);
    }

    /**
     * And the cursor advances: handing it back asks for what comes after
     * it, not for the same page again.
     */
    public function testTheCursorResumesAfterTheLastKeyHandedOut(): void
    {
        $backend = $this->backend();
        foreach (['a', 'b', 'c'] as $name) {
            $backend->put('12/' . $name . '.jpg', 'x', 'image/jpeg');
        }

        $second = $backend->list('', '12/b.jpg', 2);

        $this->assertSame(['12/c.jpg'], $this->keysOf($second));
        $this->assertTrue($second->isComplete());
    }

    /**
     * **An encoded base path used to make every listing empty.** Hrefs
     * arrive decoded, so a base left as the operator typed it matches none
     * of them — and a Nextcloud address carries the account name, which is
     * very often somebody's, spaces and all.
     */
    public function testAShareWhoseAddressCarriesAnEncodedSegmentStillLists(): void
    {
        $share = new FakeWebDavServer('https://cloud.example.org/dav/marie%20dupont/scoutmagic');
        $backend = new WebDavBackend(
            new WebDavClient($share->transport()),
            new WebDavLocationConfig($share->baseUrl, 'marie dupont'),
            'le-mot-de-passe'
        );
        $backend->put('12/a.jpg', 'aa', 'image/jpeg');

        $this->assertSame(['12/a.jpg'], $this->keysOf($backend->list('')));
    }

    /**
     * **The page ceiling is not a cliff.** The walk used to stop dead at
     * `LIST_CEILING` keys and return silently, and because every page
     * re-walks from scratch, each one drew from the same first five
     * thousand keys — so once the cursor had passed all of them, the next
     * page came back EMPTY with a null cursor, which `isComplete()` reads
     * as « the whole share, seen ». A safety copy would then have treated
     * everything past that point as gone from the source and deleted its
     * backups.
     *
     * Walked here with a page of two against fifteen files, which is the
     * same arithmetic at a size a test can hold.
     */
    public function testEveryObjectIsReachedEvenPastThePageCeiling(): void
    {
        $backend = $this->backend();
        $expected = [];
        foreach (range(1, 15) as $index) {
            $key = sprintf('12/photo-%02d.jpg', $index);
            $backend->put($key, 'x', 'image/jpeg');
            $expected[] = $key;
        }

        $seen = [];
        $cursor = null;
        $pages = 0;
        do {
            $listing = $backend->list('', $cursor, 2);
            $seen = array_merge($seen, $this->keysOf($listing));
            $cursor = $listing->cursor;
            $pages++;
        } while ($cursor !== null && $pages < 50);

        $this->assertSame($expected, $seen);
        $this->assertSame(8, $pages, 'fifteen objects, two per page, then the page that ends it');
    }

    /** A page that lands exactly on the last object still ends the walk. */
    public function testAPageEndingExactlyOnTheLastObjectIsComplete(): void
    {
        $backend = $this->backend();
        $backend->put('12/a.jpg', 'x', 'image/jpeg');
        $backend->put('12/b.jpg', 'x', 'image/jpeg');

        $first = $backend->list('', null, 2);
        $this->assertSame(['12/a.jpg', '12/b.jpg'], $this->keysOf($first));

        // Two objects, a page of two: the page is full, so there may be
        // more — and the next call is what says there is not.
        if (!$first->isComplete()) {
            $second = $backend->list('', $first->cursor, 2);
            $this->assertSame([], $this->keysOf($second));
            $this->assertTrue($second->isComplete());
        }
    }

    // ———— A share is not trusted to name what belongs to it ————

    /**
     * **A prefix test is not containment.** A share answering an `href` of
     * `…/scoutmagic/../../secret.jpg` passes « starts with the base », and
     * `urlFor()` leaves `..` exactly as it is when it percent-encodes each
     * segment — so the address that goes out carries a literal `../../`
     * that cURL resolves outside the operator's folder. That key would
     * have reached `get()`, `put()` and `delete()` like any other.
     */
    public function testAnHrefClimbingOutOfTheCollectionIsNotAKey(): void
    {
        $share = new FakeWebDavServer();
        $backend = new WebDavBackend(
            new WebDavClient($this->climbingListing($share)),
            new WebDavLocationConfig($share->baseUrl, 'unite'),
            'le-mot-de-passe'
        );

        $this->assertSame([], $this->keysOf($backend->list('')));
    }

    /**
     * And the same guard on the caller's side: nothing here builds a key
     * with a `..` in it, which is why one that has is worth refusing
     * rather than sending. Every read, write and delete goes through
     * `urlFor()`.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('keysThatClimb')]
    public function testAKeyThatClimbsIsRefusedRatherThanSent(string $key): void
    {
        $this->expectException(WebDavAccessException::class);

        $this->backend()->delete($key);
    }

    /** @return array<string, array{0: string}> */
    public static function keysThatClimb(): array
    {
        return [
            'straight up' => ['../secret.jpg'],
            'up from inside' => ['12/../../secret.jpg'],
            'the current folder' => ['./secret.jpg'],
            'an empty segment' => ['12//secret.jpg'],
        ];
    }

    /**
     * A `%2F` inside a file name stays inside it. The href is parsed
     * before it is decoded, one segment at a time, so a server cannot
     * smuggle a separator through a name — and a file genuinely called
     * `photo?1.jpg` survives, where decoding first made `parse_url()`
     * read everything past the `?` as a query string.
     */
    public function testAnEncodedSeparatorStaysInsideTheNameItBelongsTo(): void
    {
        $share = new FakeWebDavServer();
        $backend = new WebDavBackend(
            new WebDavClient($this->smuggledListing($share)),
            new WebDavLocationConfig($share->baseUrl, 'unite'),
            'le-mot-de-passe'
        );

        $this->assertSame(['photo?1.jpg'], $this->keysOf($backend->list('')));
    }

    // ———— One folder gone is not the whole share gone ————

    /**
     * **A sub-collection removed mid-walk used to empty the listing.** The
     * « not there is an empty listing » shortcut wrapped the whole walk,
     * so a 404 on any nested `PROPFIND` threw away every key already
     * gathered from its siblings and answered an empty, COMPLETE listing —
     * which `ProtectionPass` reads as « the whole share, seen » and acts on
     * by treating files that never moved as gone from the source.
     */
    public function testAFolderThatVanishesMidWalkDoesNotEmptyTheListing(): void
    {
        $share = new FakeWebDavServer();
        $backend = new WebDavBackend(
            new WebDavClient($this->vanishingFolderListing($share)),
            new WebDavLocationConfig($share->baseUrl, 'unite'),
            'le-mot-de-passe'
        );
        $this->backendOn($share)->put('12/a.jpg', 'aa', 'image/jpeg');
        $this->backendOn($share)->put('13/b.jpg', 'bb', 'image/jpeg');

        $listing = $backend->list('');

        $this->assertSame(['12/a.jpg'], $this->keysOf($listing));
        $this->assertTrue($listing->isComplete(), 'what is there is there');
    }

    /** A share that answers one href climbing out of its own collection. */
    private function climbingListing(FakeWebDavServer $share): \Closure
    {
        return $this->listingAnswering(
            $share,
            '<d:response><d:href>/dav/scoutmagic/..%2F..%2Fsecret.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:getcontentlength>4</d:getcontentlength></d:prop>'
            . '</d:propstat></d:response>'
        );
    }

    /** A share that answers a file whose NAME holds a separator and a query mark. */
    private function smuggledListing(FakeWebDavServer $share): \Closure
    {
        return $this->listingAnswering(
            $share,
            '<d:response><d:href>/dav/scoutmagic/photo%3F1.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:getcontentlength>4</d:getcontentlength></d:prop>'
            . '</d:propstat></d:response>'
        );
    }

    /**
     * A share whose root lists two album folders and answers 404 for the
     * second — the folder somebody removed while the walk was running.
     */
    private function vanishingFolderListing(FakeWebDavServer $share): \Closure
    {
        $real = $share->transport();

        return function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
            int $ceiling
        ) use ($real): array {
            if ($method === 'PROPFIND' && str_ends_with($url, '/13')) {
                return ['status' => 404, 'body' => '', 'headers' => []];
            }

            return $real($method, $url, $headers, $body, $ceiling);
        };
    }

    /**
     * A transport answering one fabricated `PROPFIND` body at the root and
     * deferring to the share for everything else.
     */
    private function listingAnswering(FakeWebDavServer $share, string $entries): \Closure
    {
        $real = $share->transport();

        return function (
            string $method,
            string $url,
            array $headers,
            ?string $body,
            int $ceiling
        ) use ($real, $entries): array {
            if ($method !== 'PROPFIND') {
                return $real($method, $url, $headers, $body, $ceiling);
            }

            return [
                'status' => 207,
                'body' => '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:">'
                    . '<d:response><d:href>/dav/scoutmagic/</d:href>'
                    . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
                    . '<d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop>'
                    . '</d:propstat></d:response>'
                    . $entries
                    . '</d:multistatus>',
                'headers' => [],
            ];
        };
    }

    private function backendOn(FakeWebDavServer $share): WebDavBackend
    {
        return new WebDavBackend(
            new WebDavClient($share->transport()),
            new WebDavLocationConfig($share->baseUrl, 'unite'),
            'le-mot-de-passe'
        );
    }

    // ———— A digest, and only where the share states one ————

    /**
     * **Nextcloud's etag is `md5(mtime . inode . dev . size)`** — thirty-two
     * hexadecimal characters that describe an inode. Announcing it as a
     * checksum makes `ProtectedCopier` compare it against the source's
     * real MD5, delete the copy it has just uploaded and report the file
     * as corrupt. On every file.
     */
    public function testAShareThatStatesNoDigestAnnouncesNothing(): void
    {
        $backend = $this->backend();
        $backend->put('photo.jpg', 'les-octets', 'image/jpeg');

        $this->assertNull($backend->announcedChecksum('photo.jpg'));
    }

    public function testAShareThatStatesADigestAnnouncesIt(): void
    {
        $this->share->announcesChecksums = true;
        $backend = $this->backend();
        $backend->put('photo.jpg', 'les-octets', 'image/jpeg');

        $this->assertSame(md5('les-octets'), $backend->announcedChecksum('photo.jpg'));
    }

    // ———— How long one transfer may take ————

    /**
     * **A read is sized like a write.** The ceiling used to come from the
     * request body alone, so every download got the flat thirty-second
     * floor whatever it carried — and the stall guard does not cover that,
     * since a transfer holding steady at the acceptable floor never
     * stalls. An 8 MiB slice, which is what seeking in a video asks for,
     * would have needed 279 kB/s sustained, on the connection this whole
     * type exists to be usable over.
     */
    public function testARangedReadGetsLongerThanAShortOne(): void
    {
        $backend = $this->backend();
        $backend->put('film.mp4', str_repeat('x', 40), 'video/mp4');
        $this->share->calls = [];

        $backend->getRange('film.mp4', 0, 8 * 1024 * 1024);

        $ceiling = $this->share->calls[0]['ceiling'];
        $this->assertGreaterThan(
            60,
            $ceiling,
            'an 8 MiB slice cannot be given the same thirty seconds as a PROPFIND'
        );
    }

    /**
     * **A `PROPFIND` is not a status-only verb.** Its answer is a
     * multistatus document whose size the caller learns from the answer —
     * `Depth: 1` on a collection of ten thousand renditions carries
     * megabytes — so sizing its ceiling on the tiny request body would cut
     * a large album's listing off at roughly sixty kilobytes, and report
     * it as a share that could not be reached.
     */
    public function testAListingIsNotGivenTheCeilingOfAStatusOnlyRequest(): void
    {
        $backend = $this->backend();
        $backend->put('12/a.jpg', 'x', 'image/jpeg');
        $this->share->calls = [];

        $backend->list('');

        $propfind = array_values(array_filter(
            $this->share->calls,
            static fn (array $call): bool => $call['method'] === 'PROPFIND'
        ));
        $this->assertNotSame([], $propfind);
        $this->assertGreaterThan(30, $propfind[0]['ceiling']);
    }

    // ———— One MKCOL per album, not one per file ————

    public function testAFolderIsNotCreatedAgainForEveryFileWrittenIntoIt(): void
    {
        $backend = $this->backend();
        $backend->put('12/a.jpg', 'x', 'image/jpeg');
        $backend->put('12/b.jpg', 'x', 'image/jpeg');
        $backend->put('12/c.jpg', 'x', 'image/jpeg');

        $made = array_values(array_filter(
            $this->share->calls,
            static fn (array $call): bool => $call['method'] === 'MKCOL'
        ));

        $this->assertCount(1, $made);
    }

    /**
     * And the memory recovers: a collection removed on the share between
     * two writes costs one retry, not a backend that fails for the rest of
     * the request.
     */
    public function testAFolderThatDisappearsMidRequestIsMadeAgain(): void
    {
        $backend = $this->backend();
        $backend->put('12/a.jpg', 'x', 'image/jpeg');

        // Somebody tidying their cloud, or another site writing into it.
        $this->share->collections = [];
        $this->share->files = [];
        $this->share->refusePutOnce = true;

        $backend->put('12/b.jpg', 'les-octets', 'image/jpeg');

        $this->assertSame('les-octets', $backend->get('12/b.jpg'));
    }

    // ———— The test that earns « Vidéos : oui » ————

    /**
     * **`PROPFIND` answering is not the promise this type makes.** Every
     * WebDAV location declares `RangeRead`, and the Emplacements screen
     * turns that into « une vidéo se lit, et on peut avancer dedans ». A
     * server can answer `PROPFIND` perfectly and ignore `Range:`, and the
     * administrator would find out on the evening a parent tries to skip
     * to the end of the camp film.
     */
    public function testAShareThatIgnoresRangeIsRefusedAtDeclarationTime(): void
    {
        $this->share->ignoresRange = true;

        $error = $this->backend()->testConnection();

        $this->assertNotNull($error);
        $this->assertStringContainsString('vidéos', $error);
    }

    /** And the witness file is never left behind on somebody's cloud. */
    public function testTheConnectionTestLeavesNothingOnTheShare(): void
    {
        $this->backend()->testConnection();

        $this->assertSame([], $this->share->files);
    }

    public function testTheConnectionTestCleansUpEvenWhenTheRangeIsRefused(): void
    {
        $this->share->ignoresRange = true;

        $this->backend()->testConnection();

        $this->assertSame([], $this->share->files);
    }

    /**
     * @param \Core\Storage\Location\StorageListing $listing
     * @return list<string>
     */
    private function keysOf(\Core\Storage\Location\StorageListing $listing): array
    {
        return array_map(static fn (\Core\Storage\Location\StoredObject $o): string => $o->key, $listing->objects);
    }

    private function backend(): WebDavBackend
    {
        return new WebDavBackend(
            new WebDavClient($this->share->transport()),
            new WebDavLocationConfig($this->share->baseUrl, 'unite'),
            'le-mot-de-passe'
        );
    }
}
