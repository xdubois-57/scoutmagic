<?php

declare(strict_types=1);

namespace Tests\Core\Contact\CardDav;

use Core\Contact\CardDav\DavRequestParser;
use PHPUnit\Framework\TestCase;

/**
 * The only place in this feature where something a stranger wrote is
 * parsed — so the tests that matter most here are the ones about what it
 * refuses.
 */
class DavRequestParserTest extends TestCase
{
    private DavRequestParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DavRequestParser();
    }

    public function testTheRequestedPropertiesComeBackAsNamespacedNames(): void
    {
        $properties = $this->parser->propfindProperties(
            '<?xml version="1.0"?><D:propfind xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:prop><D:resourcetype /><C:addressbook-home-set /></D:prop></D:propfind>'
        );

        $this->assertSame(
            ['{DAV:}resourcetype', '{urn:ietf:params:xml:ns:carddav}addressbook-home-set'],
            $properties
        );
    }

    /**
     * The prefix a client chooses is its own business: `<x:prop>` bound
     * to `DAV:` is the same property as `<D:prop>`, and a parser reading
     * prefixes rather than namespaces would answer 404 to half the
     * clients in existence.
     */
    public function testThePrefixAClientChoosesChangesNothing(): void
    {
        $properties = $this->parser->propfindProperties(
            '<?xml version="1.0"?><x:propfind xmlns:x="DAV:"><x:prop><x:getetag /></x:prop></x:propfind>'
        );

        $this->assertSame(['{DAV:}getetag'], $properties);
    }

    /**
     * RFC 4918 §9.1: an empty body means allprop, and real clients send
     * one.
     */
    public function testAnEmptyBodyAsksForEverything(): void
    {
        $this->assertSame(DavRequestParser::ALLPROP, $this->parser->propfindProperties(''));
        $this->assertSame(DavRequestParser::ALLPROP, $this->parser->propfindProperties('   '));
    }

    public function testAllpropAndPropnameBothAskForEverything(): void
    {
        $this->assertSame(DavRequestParser::ALLPROP, $this->parser->propfindProperties(
            '<D:propfind xmlns:D="DAV:"><D:allprop /></D:propfind>'
        ));
        $this->assertSame(DavRequestParser::ALLPROP, $this->parser->propfindProperties(
            '<D:propfind xmlns:D="DAV:"><D:propname /></D:propfind>'
        ));
    }

    /**
     * A body that is not XML at all is read as « nothing asked », never
     * as an error: a 500 would make this server look broken to a client
     * that is merely terse, and there is a correct answer available.
     */
    public function testAMalformedBodyIsNotAnError(): void
    {
        $this->assertSame(DavRequestParser::ALLPROP, $this->parser->propfindProperties('<D:propfind'));
        $this->assertSame(DavRequestParser::ALLPROP, $this->parser->propfindProperties('{"json":true}'));
    }

    /**
     * **The one that matters.** A body declaring an external entity and
     * using it must not resolve it: the classic XXE, aimed at a route
     * that parses before it is certain who is calling.
     */
    public function testAnExternalEntityIsNeverResolved(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'xxe');
        $this->assertIsString($secret);
        file_put_contents($secret, 'SUPER-SECRET-VALUE');

        $body = '<?xml version="1.0"?>'
            . '<!DOCTYPE propfind [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
            . '<D:propfind xmlns:D="DAV:"><D:prop><D:displayname>&xxe;</D:displayname></D:prop></D:propfind>';

        $properties = $this->parser->propfindProperties($body);
        unlink($secret);

        // Whatever it parsed to, the file's contents are not in it.
        $this->assertStringNotContainsString('SUPER-SECRET-VALUE', var_export($properties, true));
    }

    /**
     * The same entity, this time in an href a multiget would send back:
     * the value must not become a file's contents on the way through.
     */
    public function testAnExternalEntityInAnHrefIsNeverResolved(): void
    {
        $secret = tempnam(sys_get_temp_dir(), 'xxe');
        $this->assertIsString($secret);
        file_put_contents($secret, 'SUPER-SECRET-VALUE');

        $report = $this->parser->report(
            '<?xml version="1.0"?>'
            . '<!DOCTYPE m [<!ENTITY xxe SYSTEM "file://' . $secret . '">]>'
            . '<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:href>&xxe;</D:href></C:addressbook-multiget>'
        );
        unlink($secret);

        foreach ($report['hrefs'] as $href) {
            $this->assertStringNotContainsString('SUPER-SECRET-VALUE', $href);
        }
    }

    /**
     * A body past the ceiling is not parsed at all — the cheapest denial
     * of service against an XML parser is a large document, and this
     * runs before authentication has had a chance to be expensive.
     */
    public function testABodyPastTheCeilingIsNotParsed(): void
    {
        $filler = str_repeat('<D:getetag />', DavRequestParser::MAX_BODY_BYTES);
        $body = '<D:propfind xmlns:D="DAV:"><D:prop>' . $filler . '</D:prop></D:propfind>';
        $this->assertGreaterThan(DavRequestParser::MAX_BODY_BYTES, strlen($body));

        $this->assertSame(DavRequestParser::ALLPROP, $this->parser->propfindProperties($body));
    }

    public function testAMultigetNamesItsReportAndItsHrefs(): void
    {
        $report = $this->parser->report(
            '<C:addressbook-multiget xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:carddav">'
            . '<D:prop><D:getetag /><C:address-data /></D:prop>'
            . '<D:href>/carddav/staff/7.vcf</D:href><D:href>/carddav/staff/9.vcf</D:href>'
            . '</C:addressbook-multiget>'
        );

        $this->assertSame('addressbook-multiget', $report['report']);
        $this->assertSame(['/carddav/staff/7.vcf', '/carddav/staff/9.vcf'], $report['hrefs']);
        $this->assertSame(
            ['{DAV:}getetag', '{urn:ietf:params:xml:ns:carddav}address-data'],
            $report['properties']
        );
    }

    public function testAnUnknownReportIsNamedRatherThanGuessedAt(): void
    {
        $report = $this->parser->report(
            '<C:free-busy-query xmlns:C="urn:ietf:params:xml:ns:carddav" />'
        );

        $this->assertSame('free-busy-query', $report['report']);
    }

    public function testAnEmptyReportBodyNamesNoReport(): void
    {
        $report = $this->parser->report('');

        $this->assertNull($report['report']);
        $this->assertSame([], $report['hrefs']);
    }
}
