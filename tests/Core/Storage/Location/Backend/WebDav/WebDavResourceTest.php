<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Core\Storage\Location\Backend\WebDav;

use Core\Storage\Location\Backend\WebDav\WebDavAccessException;
use Core\Storage\Location\Backend\WebDav\WebDavResource;
use PHPUnit\Framework\TestCase;

/**
 * Reading a `PROPFIND` answer, against the shapes real servers send.
 *
 * **Every fixture here is a shape that differs between servers**, which
 * is the point: a parser written against one Nextcloud and tested against
 * the same Nextcloud proves only that two copies of one assumption agree.
 */
final class WebDavResourceTest extends TestCase
{
    /**
     * The namespace prefix is the server's to choose, and three of them
     * choose differently in the same deployment.
     */
    public function testAPrefixOtherThanDIsReadTheSameWay(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<D:multistatus xmlns:D="DAV:"><D:response>'
            . '<D:href>/remote.php/dav/scoutmagic/photo.jpg</D:href>'
            . '<D:propstat><D:status>HTTP/1.1 200 OK</D:status>'
            . '<D:prop><D:getcontentlength>4096</D:getcontentlength></D:prop>'
            . '</D:propstat></D:response></D:multistatus>';

        $resources = WebDavResource::parseMultiStatus($xml);

        $this->assertCount(1, $resources);
        $this->assertSame(4096, $resources[0]->contentLength);
        $this->assertFalse($resources[0]->isCollection);
    }

    /**
     * **The 404 propstat must not be the one read.** A server answers a
     * property it does not carry in a second block, and taking that one
     * measures every file at zero bytes — which a retention pass reads as
     * « this archive is empty ».
     */
    public function testAPropertyTheServerDoesNotCarryDoesNotZeroTheOnesItDoes(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/photo.jpg</d:href>'
            // The 404 block FIRST, which is where servers actually put
            // it and what makes this test able to fail: with the blocks
            // the other way round, « take the first » happens to be
            // right and the assertion proves nothing.
            . '<d:propstat><d:status>HTTP/1.1 404 Not Found</d:status>'
            . '<d:prop><d:quota-available-bytes/></d:prop></d:propstat>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:getcontentlength>512</d:getcontentlength></d:prop></d:propstat>'
            . '</d:response></d:multistatus>';

        $resources = WebDavResource::parseMultiStatus($xml);

        $this->assertSame(512, $resources[0]->contentLength);
    }

    /** A collection says so through `resourcetype`, not through its name. */
    public function testACollectionIsRecognised(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/dossier/</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:resourcetype><d:collection/></d:resourcetype></d:prop>'
            . '</d:propstat></d:response></d:multistatus>';

        $this->assertTrue(WebDavResource::parseMultiStatus($xml)[0]->isCollection);
    }

    /**
     * **A digest is announced only where the server states one as a
     * digest**, and never derived from the `getetag`.
     *
     * The rule used to be « believe an etag that is thirty-two hexadecimal
     * characters, because nothing else has that shape ». Nextcloud — the
     * server this type is aimed at first — writes
     * `md5(mtime . inode . dev . size)`, which has exactly that shape and
     * describes an inode. `ProtectedCopier` compares an announced checksum
     * against the source's real MD5 and, on a mismatch, DELETES the copy
     * it has just uploaded and reports the file as corrupt: every file, on
     * every pass, for a safety copy pointed at a Nextcloud.
     *
     * @param string $properties the `<d:prop>` contents to answer
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('checksumSources')]
    public function testOnlyAStatedDigestIsAnnouncedAsAChecksum(string $properties, ?string $expected): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:" xmlns:oc="http://owncloud.org/ns"><d:response>'
            . '<d:href>/dav/photo.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop>' . $properties . '</d:prop>'
            . '</d:propstat></d:response></d:multistatus>';

        $this->assertSame($expected, WebDavResource::parseMultiStatus($xml)[0]->contentMd5);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function checksumSources(): array
    {
        $md5 = 'd41d8cd98f00b204e9800998ecf8427e';

        return [
            // The case that made the old rule wrong: a real Nextcloud
            // etag, thirty-two hexadecimal characters, describing an inode.
            'a Nextcloud metadata etag that looks exactly like an MD5' => [
                '<d:getetag>"' . md5('1740000000' . '12345' . '2049' . '8192') . '"</d:getetag>',
                null,
            ],
            'an etag that IS the content MD5 is still not announced' => [
                '<d:getetag>"' . $md5 . '"</d:getetag>',
                null,
            ],
            'Apache mod_dav inode-size-mtime' => ['<d:getetag>"1a2b3c-1000-5f8a"</d:getetag>', null],
            'no properties at all' => ['<d:getcontentlength>12</d:getcontentlength>', null],
            'a stated MD5, alone' => [
                '<oc:checksums><oc:checksum>MD5:' . $md5 . '</oc:checksum></oc:checksums>',
                $md5,
            ],
            'a stated MD5 beside other algorithms in one element' => [
                '<oc:checksums><oc:checksum>SHA1:da39a3ee5e6b4b0d3255bfef95601890afd80709 MD5:'
                    . $md5 . '</oc:checksum></oc:checksums>',
                $md5,
            ],
            'a stated MD5 in an element of its own' => [
                '<oc:checksums><oc:checksum>SHA1:da39a3ee5e6b4b0d3255bfef95601890afd80709</oc:checksum>'
                    . '<oc:checksum>MD5:' . strtoupper($md5) . '</oc:checksum></oc:checksums>',
                $md5,
            ],
            'a checksums block naming no MD5' => [
                '<oc:checksums><oc:checksum>ADLER32:0dc4028e</oc:checksum></oc:checksums>',
                null,
            ],
            'an empty checksums block' => ['<oc:checksums/>', null],
        ];
    }

    /**
     * **A `<response>` whose properties all failed is not a 0-byte file.**
     * RFC 4918 lets a server split properties across per-status blocks and
     * succeed at none of them, and reading that as a definite zero is how
     * `ProtectedCopier` comes to compare a real file against an announced
     * nothing, call the copy corrupt and delete it.
     */
    public function testAnEntryWhoseEveryPropertyFailedHasNoSizeRatherThanZero(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/photo.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 404 Not Found</d:status>'
            . '<d:prop><d:getcontentlength/></d:prop></d:propstat>'
            . '</d:response></d:multistatus>';

        $this->assertNull(WebDavResource::parseMultiStatus($xml)[0]->contentLength);
    }

    /**
     * **And the common shape of the same silence**: a 200 block that
     * simply omits `getcontentlength` while a sibling block answers 404
     * for it, which is how RFC 4918 has a server report a property it
     * cannot answer. `(int) (string)` on an absent element is 0, so this
     * one slipped past a guard that only looked for a missing 200 block.
     */
    public function testAnEntryWhose200BlockOmitsTheLengthHasNoSizeEither(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/photo.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:getetag>"1a2b3c"</d:getetag></d:prop></d:propstat>'
            . '<d:propstat><d:status>HTTP/1.1 404 Not Found</d:status>'
            . '<d:prop><d:getcontentlength/></d:prop></d:propstat>'
            . '</d:response></d:multistatus>';

        $this->assertNull(WebDavResource::parseMultiStatus($xml)[0]->contentLength);
    }

    /**
     * **A stated zero IS a zero** — an empty file is a thing that exists,
     * and the normalisation must not swallow it along with the silences
     * around it.
     */
    public function testAStatedZeroIsAnEmptyFileRatherThanSilence(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/photo.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:getcontentlength>0</d:getcontentlength></d:prop></d:propstat>'
            . '</d:response></d:multistatus>';

        $this->assertSame(0, WebDavResource::parseMultiStatus($xml)[0]->contentLength);
    }

    /**
     * **A length the server sent but could not fill in is silence**, and
     * this is the shape an `isset()` guard cannot see: the element is
     * there, inside the 200 block, and carries nothing — or carries
     * something that is not a number at all, or a negative sentinel. Each
     * of those read as a definite size announces a file that measures 0
     * or -1 bytes, and `ProtectedCopier` deletes the copy it has just
     * made for disagreeing with it.
     *
     * @param string $stated what the 200 block puts inside the element
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unusableLengths')]
    public function testALengthTheServerCouldNotStateIsSilence(string $stated): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/photo.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:getcontentlength>' . $stated . '</d:getcontentlength></d:prop>'
            . '</d:propstat>'
            . '</d:response></d:multistatus>';

        $this->assertNull(WebDavResource::parseMultiStatus($xml)[0]->contentLength);
    }

    /** @return array<string, array{string}> */
    public static function unusableLengths(): array
    {
        return [
            'present but empty' => [''],
            'whitespace only' => ['   '],
            'not a number' => ['unknown'],
            'a negative sentinel' => ['-1'],
        ];
    }

    /** A file the server did describe still measures what it says. */
    public function testAnEntryTheServerDescribedKeepsItsSize(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/photo.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:getcontentlength>512</d:getcontentlength></d:prop></d:propstat>'
            . '</d:response></d:multistatus>';

        $this->assertSame(512, WebDavResource::parseMultiStatus($xml)[0]->contentLength);
    }

    /**
     * A share with no limit answers « unknown », never « nothing free » —
     * a screen must show nothing rather than a reassuring zero.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('quotas')]
    public function testAQuotaIsUnknownRatherThanZeroWhenTheServerDeclines(string $value, ?int $expected): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:quota-available-bytes>' . $value . '</d:quota-available-bytes></d:prop>'
            . '</d:propstat></d:response></d:multistatus>';

        $this->assertSame($expected, WebDavResource::parseMultiStatus($xml)[0]->quotaAvailableBytes);
    }

    /** @return array<string, array{0: string, 1: ?int}> */
    public static function quotas(): array
    {
        return [
            'a figure is a figure' => ['10737418240', 10737418240],
            'a real zero is a real zero' => ['0', 0],
            'the no-limit sentinel is unknown' => ['-3', null],
            'an empty element is unknown' => ['', null],
            'something that is not a number is unknown' => ['illimité', null],
        ];
    }

    /**
     * A name is percent-encoded on the wire, and `+` is a character a
     * file name carries rather than a space.
     */
    public function testAnHrefIsDecodedWithoutTurningAPlusIntoASpace(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/scoutmagic+1%20(copie).zip</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status><d:prop/></d:propstat>'
            . '</d:response></d:multistatus>';

        $this->assertSame('/dav/scoutmagic+1 (copie).zip', WebDavResource::parseMultiStatus($xml)[0]->href);
    }

    /**
     * **A doctype is refused rather than parsed.** The body is text from
     * a server an operator named, and a parser that resolves external
     * entities is how a remote document reads local files.
     */
    public function testABodyCarryingADoctypeIsRefused(): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<!DOCTYPE multistatus [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>&xxe;</d:href></d:response></d:multistatus>';

        $this->expectException(WebDavAccessException::class);
        WebDavResource::parseMultiStatus($xml);
    }

    /** A body that is not well-formed at all is a named refusal, not a fatal. */
    public function testAnAnswerThatIsNotWellFormedIsNamed(): void
    {
        $this->expectException(WebDavAccessException::class);
        WebDavResource::parseMultiStatus('<d:multistatus xmlns:d="DAV:"><d:response>');
    }

    /**
     * An empty collection is empty, not broken.
     *
     * The distinction matters to the caller: a refusal stops a retention
     * pass, where « this folder holds nothing » is an ordinary answer it
     * has to be able to act on.
     */
    public function testAnEmptyMultiStatusIsAnEmptyListRatherThanARefusal(): void
    {
        $xml = '<?xml version="1.0"?><d:multistatus xmlns:d="DAV:"></d:multistatus>';

        $this->assertSame([], WebDavResource::parseMultiStatus($xml));
    }
}
