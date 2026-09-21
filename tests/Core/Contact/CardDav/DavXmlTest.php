<?php

declare(strict_types=1);

namespace Tests\Core\Contact\CardDav;

use Core\Contact\CardDav\DavXml;
use PHPUnit\Framework\TestCase;

/**
 * The XML this server writes. Every assertion here is ultimately the
 * same one: a document a client's parser accepts, carrying values that
 * came from a Desk import and cannot become markup.
 */
class DavXmlTest extends TestCase
{
    public function testTextThatLooksLikeMarkupLeavesAsText(): void
    {
        $this->assertSame(
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            DavXml::escape('<script>alert(1)</script>')
        );
    }

    /**
     * `&apos;` is an XML entity and is not an HTML 4 one, so the
     * difference between `ENT_XML1` and the HTML flavours is a document
     * some parsers reject. A unit's name with an apostrophe in it — «
     * Unité Saint-Étienne d'Uccle » — is the ordinary case, not the
     * exotic one.
     */
    public function testAnApostropheIsEscapedTheXmlWay(): void
    {
        $this->assertSame('Staff d&apos;U', DavXml::escape("Staff d'U"));
        $this->assertSame('Dupont &amp; Fils', DavXml::escape('Dupont & Fils'));
    }

    public function testAnHrefIsEncodedSegmentBySegment(): void
    {
        // The separators survive; a space inside a segment does not.
        $this->assertSame(
            '<D:href>/carddav/staff/7.vcf</D:href>',
            DavXml::href('/carddav/staff/7.vcf')
        );
        $this->assertStringContainsString('/carddav/a%20b', DavXml::href('/carddav/a b'));
        $this->assertStringNotContainsString('%2F', DavXml::href('/carddav/staff/'));
    }

    public function testAMultistatusIsWellFormedXml(): void
    {
        $xml = DavXml::multistatus([
            [
                'href' => '/carddav/staff/7.vcf',
                'found' => ['D:getetag' => '&quot;abc&quot;', 'D:resourcetype' => ''],
                'missing' => ['D:getcontentlength'],
            ],
        ]);

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml));
        $this->assertSame('multistatus', $document->documentElement?->localName);
        $this->assertSame(2, $document->getElementsByTagNameNS(DavXml::NS_DAV, 'propstat')->length);
    }

    /**
     * RFC 4918 §9.1 splits a response by status: the properties that
     * exist under 200, the ones that do not under 404. Answering 200 for
     * everything makes a property this server does not implement look
     * like one that is merely empty.
     */
    public function testAMissingPropertyIsReportedUnderItsOwnStatus(): void
    {
        $xml = DavXml::multistatus([
            ['href' => '/carddav/', 'found' => ['D:displayname' => 'x'], 'missing' => ['D:quota-used-bytes']],
        ]);

        $this->assertStringContainsString('<D:status>HTTP/1.1 200 OK</D:status>', $xml);
        $this->assertStringContainsString('<D:status>HTTP/1.1 404 Not Found</D:status>', $xml);
        $this->assertStringContainsString('<D:quota-used-bytes />', $xml);
    }

    public function testAResponseWithNothingMissingCarriesOnlyTheOneStatus(): void
    {
        $xml = DavXml::multistatus([
            ['href' => '/carddav/', 'found' => ['D:displayname' => 'x'], 'missing' => []],
        ]);

        $this->assertStringContainsString('200 OK', $xml);
        $this->assertStringNotContainsString('404 Not Found', $xml);
    }

    /**
     * The other shape RFC 4918 allows: a status for the whole resource,
     * which is how a multiget reports one href it could not resolve
     * without failing the others.
     */
    public function testAResourceLevelStatusReplacesThePropstats(): void
    {
        $xml = DavXml::multistatus([
            ['href' => '/carddav/staff/9.vcf', 'found' => [], 'missing' => [], 'status' => 'HTTP/1.1 404 Not Found'],
        ]);

        $document = new \DOMDocument();
        $this->assertTrue($document->loadXML($xml));
        $this->assertSame(0, $document->getElementsByTagNameNS(DavXml::NS_DAV, 'propstat')->length);
        $this->assertStringContainsString('<D:status>HTTP/1.1 404 Not Found</D:status>', $xml);
    }

    public function testAnEmptyPropertyIsAnEmptyElement(): void
    {
        $this->assertSame('<D:collection />', DavXml::element('D:collection', ''));
        $this->assertSame('<D:displayname>Staff</D:displayname>', DavXml::element('D:displayname', 'Staff'));
    }
}
