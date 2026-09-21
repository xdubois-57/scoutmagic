<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Contact\CardDav;

/**
 * The WebDAV XML this server writes, built by hand.
 *
 * **No new dependency**, deliberately: what CardDAV needs from XML here
 * is one document shape — `<D:multistatus>` carrying a `<D:response>`
 * per resource — with a fixed set of namespace prefixes and no
 * attributes to speak of. A library would be a supply-chain commitment
 * for a builder that fits on one screen.
 *
 * Everything that reaches {@see escape()} is treated as untrusted,
 * including values this application produced itself: a member's totem
 * comes from a Desk import, and an `&` in it must not be able to leave
 * this server as markup.
 */
final class DavXml
{
    public const NS_DAV = 'DAV:';
    public const NS_CARDDAV = 'urn:ietf:params:xml:ns:carddav';
    public const NS_CALENDARSERVER = 'http://calendarserver.org/ns/';

    /**
     * Text content, safe to place between two tags.
     *
     * `ENT_XML1` rather than the HTML flavours: the difference is
     * `&apos;`, which is an XML entity and is not one in HTML 4, and
     * getting it wrong produces a document some parsers reject.
     */
    public static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * An `<D:href>` for a path this server publishes.
     *
     * The path is URL-encoded segment by segment — never as a whole,
     * which would turn its separators into `%2F` — because a client
     * sends this value straight back as a request target.
     */
    public static function href(string $path): string
    {
        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));

        return '<D:href>' . self::escape($encoded) . '</D:href>';
    }

    /**
     * One `<D:multistatus>` document.
     *
     * Each response names a resource and the properties asked of it,
     * split the way RFC 4918 §9.1 requires: the ones that exist in a
     * `200 OK` propstat, the ones that do not in a `404 Not Found` one,
     * with the second block omitted entirely when nothing is missing. A
     * client that asked for a property this server does not implement
     * needs to be told so per property — answering 200 for everything
     * makes a missing property look like an empty one.
     *
     * A response carrying a `status` instead is the other shape RFC 4918
     * allows: a bare `<D:status>` for the whole resource, which is how a
     * `REPORT` reports one href it could not resolve without failing the
     * others.
     *
     * @param list<array{href: string, found: array<string, string>, missing: list<string>, status?: string}> $responses
     *     `found` maps a qualified element name (`D:displayname`) to its
     *     inner XML, already escaped; an empty string produces an empty
     *     element. `missing` is a list of qualified names.
     */
    public static function multistatus(array $responses): string
    {
        $xml = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
            . '<D:multistatus xmlns:D="' . self::NS_DAV . '"'
            . ' xmlns:C="' . self::NS_CARDDAV . '"'
            . ' xmlns:CS="' . self::NS_CALENDARSERVER . '">' . "\n";

        foreach ($responses as $response) {
            $xml .= '  <D:response>' . "\n";
            $xml .= '    ' . self::href($response['href']) . "\n";

            if (isset($response['status'])) {
                $xml .= '    <D:status>' . self::escape($response['status']) . '</D:status>' . "\n"
                    . '  </D:response>' . "\n";
                continue;
            }

            if ($response['found'] !== []) {
                $xml .= '    <D:propstat>' . "\n" . '      <D:prop>' . "\n";
                foreach ($response['found'] as $name => $inner) {
                    $xml .= '        ' . self::element($name, $inner) . "\n";
                }
                $xml .= '      </D:prop>' . "\n"
                    . '      <D:status>HTTP/1.1 200 OK</D:status>' . "\n"
                    . '    </D:propstat>' . "\n";
            }

            if ($response['missing'] !== []) {
                $xml .= '    <D:propstat>' . "\n" . '      <D:prop>' . "\n";
                foreach ($response['missing'] as $name) {
                    $xml .= '        <' . $name . ' />' . "\n";
                }
                $xml .= '      </D:prop>' . "\n"
                    . '      <D:status>HTTP/1.1 404 Not Found</D:status>' . "\n"
                    . '    </D:propstat>' . "\n";
            }

            $xml .= '  </D:response>' . "\n";
        }

        return $xml . '</D:multistatus>' . "\n";
    }

    /**
     * `<D:displayname>Staff</D:displayname>`, or `<D:collection />` for
     * an element with nothing inside it.
     */
    public static function element(string $name, string $inner): string
    {
        return $inner === ''
            ? '<' . $name . ' />'
            : '<' . $name . '>' . $inner . '</' . $name . '>';
    }
}
