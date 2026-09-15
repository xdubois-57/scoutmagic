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

        $this->assertSame([], $this->share->files, 'le fichier est resté sur le partage');
        $this->assertFalse($backend->exists('12/med_3.jpg'));
    }

    /** A key that is not there is a success: the desired end state is reached. */
    public function testDeletingSomethingThatIsNotThereIsNotAFailure(): void
    {
        $this->backend()->delete('12/jamais-ecrit.jpg');

        $this->assertTrue(true, 'la suppression a levé sur une clé absente');
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
            'les dossiers n\'ont pas été créés de l\'extérieur vers l\'intérieur'
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

    private function backend(): WebDavBackend
    {
        return new WebDavBackend(
            new WebDavClient($this->share->transport()),
            new WebDavLocationConfig($this->share->baseUrl, 'unite'),
            'le-mot-de-passe'
        );
    }
}
