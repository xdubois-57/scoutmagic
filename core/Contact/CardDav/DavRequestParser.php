<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\CardDav;

/**
 * Reading the XML body of a `PROPFIND` or a `REPORT` — the only place in
 * this feature where something a stranger wrote is parsed.
 *
 * Three deliberate properties, because this runs before any credential
 * has necessarily been accepted by the surrounding controller and
 * because XML parsers are a family of vulnerabilities with their own
 * acronym:
 *
 * - **Entities are never substituted.** `LIBXML_NOENT` is not passed —
 *   its name reads like « no entities » and it means the opposite, it
 *   *expands* them — so a body declaring a `SYSTEM` entity pointing at
 *   `/etc/passwd` parses to nothing rather than to the file. `LIBXML_NONET`
 *   refuses the network for a DTD on top of that.
 * - **The body is capped before it is parsed** ({@see MAX_BODY_BYTES}),
 *   because the cheapest denial of service against an XML parser is a
 *   large document, and this route answers before authentication has a
 *   chance to be expensive about it.
 * - **A malformed body is not an error**, it is « no properties asked ».
 *   RFC 4918 §9.1 says an empty `PROPFIND` body means `allprop`, real
 *   clients send one, and a parse failure that 500s would make the
 *   server look broken to a client that is merely terse.
 */
final class DavRequestParser
{
    /**
     * 256 KB. An `addressbook-multiget` for a unit of a few hundred
     * leaders is a few tens of kilobytes of hrefs; nothing legitimate
     * approaches this, and a body that does is refused rather than
     * parsed.
     */
    public const MAX_BODY_BYTES = 262144;

    /** What `<D:allprop>` means for a collection this server publishes. */
    public const ALLPROP = '*';

    /**
     * The property names a `PROPFIND` asked for, as `{namespace}local`
     * pairs — or {@see ALLPROP} when the body asked for everything or
     * said nothing at all.
     *
     * @return list<string>|string
     */
    public function propfindProperties(string $body): array|string
    {
        $root = $this->parse($body);
        if ($root === null) {
            return self::ALLPROP;
        }

        $props = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof \DOMElement) {
                continue;
            }
            if ($child->localName === 'allprop' || $child->localName === 'propname') {
                return self::ALLPROP;
            }
            if ($child->localName !== 'prop') {
                continue;
            }
            foreach ($child->childNodes as $requested) {
                if ($requested instanceof \DOMElement) {
                    $props[] = '{' . ((string) $requested->namespaceURI) . '}' . $requested->localName;
                }
            }
        }

        return $props === [] ? self::ALLPROP : array_values(array_unique($props));
    }

    /**
     * What a `REPORT` asked for: which report, and — for an
     * `addressbook-multiget` — the hrefs it named.
     *
     * The hrefs are returned exactly as they arrived, resolved to
     * members by {@see AddressBookService::memberIdForHref()} rather
     * than here: this class parses, it does not decide what a path
     * means.
     *
     * @return array{report: ?string, hrefs: list<string>, properties: list<string>|string}
     */
    public function report(string $body): array
    {
        $root = $this->parse($body);
        if ($root === null) {
            return ['report' => null, 'hrefs' => [], 'properties' => self::ALLPROP];
        }

        $hrefs = [];
        foreach ($root->getElementsByTagNameNS(DavXml::NS_DAV, 'href') as $href) {
            $hrefs[] = trim($href->textContent);
        }

        $properties = [];
        foreach ($root->childNodes as $child) {
            if (!$child instanceof \DOMElement || $child->localName !== 'prop') {
                continue;
            }
            foreach ($child->childNodes as $requested) {
                if ($requested instanceof \DOMElement) {
                    $properties[] = '{' . ((string) $requested->namespaceURI) . '}' . $requested->localName;
                }
            }
        }

        return [
            'report' => $root->localName,
            'hrefs' => $hrefs,
            'properties' => $properties === [] ? self::ALLPROP : array_values(array_unique($properties)),
        ];
    }

    /**
     * The document element, or null for a body that is empty, too large,
     * or not XML at all.
     */
    private function parse(string $body): ?\DOMElement
    {
        $body = trim($body);
        if ($body === '' || strlen($body) > self::MAX_BODY_BYTES) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        // LIBXML_NOENT is deliberately absent — see this class's docblock.
        $loaded = $document->loadXML($body, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $loaded && $document->documentElement !== null ? $document->documentElement : null;
    }
}
