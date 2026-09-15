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
     * An etag is believed only where it looks like an MD5 — everywhere
     * else it describes an inode, and comparing a file against it would
     * report corruption on a file that is intact.
     *
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('etags')]
    public function testAnEtagIsAChecksumOnlyWhenItLooksLikeOne(string $etag, ?string $expected): void
    {
        $xml = '<?xml version="1.0"?>'
            . '<d:multistatus xmlns:d="DAV:"><d:response>'
            . '<d:href>/dav/photo.jpg</d:href>'
            . '<d:propstat><d:status>HTTP/1.1 200 OK</d:status>'
            . '<d:prop><d:getetag>' . $etag . '</d:getetag></d:prop>'
            . '</d:propstat></d:response></d:multistatus>';

        $this->assertSame($expected, WebDavResource::parseMultiStatus($xml)[0]->etag);
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function etags(): array
    {
        return [
            'a quoted MD5 is one' => ['"d41d8cd98f00b204e9800998ecf8427e"', 'd41d8cd98f00b204e9800998ecf8427e'],
            'an unquoted MD5 is one' => ['d41d8cd98f00b204e9800998ecf8427e', 'd41d8cd98f00b204e9800998ecf8427e'],
            'Apache mod_dav inode-size-mtime is not' => ['"1a2b3c-1000-5f8a"', null],
            'a Nextcloud validator is not' => ['"6a1c3f9b2e4d5a7c8b9e0f1a2b3c4d5e6"', null],
            'a weak validator says so itself' => ['W/"d41d8cd98f00b204e9800998ecf8427e"', null],
        ];
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
