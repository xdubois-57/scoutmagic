<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend\WebDav;

/**
 * One entry of a `PROPFIND` answer, and the parser that reads them.
 *
 * **The namespace prefix is not fixed**, which is the whole difficulty of
 * reading this format by hand. `DAV:` is the namespace; the letter
 * standing for it is the server's choice, so Nextcloud writes `<d:href>`,
 * another server `<D:href>`, a third `<lp1:getcontentlength>`. Matching on
 * a prefix works against whichever server was used while writing the code
 * and fails on the next one, so everything here matches on the LOCAL name
 * and ignores whatever comes before the colon.
 */
final class WebDavResource
{
    public function __construct(
        public readonly string $href,
        public readonly bool $isCollection,
        public readonly int $contentLength = 0,
        public readonly ?string $etag = null,
        public readonly ?string $lastModified = null,
        public readonly ?int $quotaAvailableBytes = null,
        public readonly ?int $quotaUsedBytes = null
    ) {
    }

    /**
     * Reads a 207 Multi-Status body.
     *
     * **Entities are refused rather than expanded** (`LIBXML_NONET` and a
     * check for a doctype): the body is text from a server the operator
     * named, and an XML parser that resolves external entities is how a
     * remote document reads local files. Nothing in `DAV:` needs a
     * doctype, so anything carrying one is refused outright.
     *
     * @return list<self>
     * @throws WebDavAccessException
     */
    public static function parseMultiStatus(string $xml): array
    {
        if (stripos($xml, '<!DOCTYPE') !== false) {
            throw WebDavAccessException::of('La réponse du partage n\'a pas pu être lue : format inattendu.');
        }

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($xml, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($document === false) {
            throw WebDavAccessException::of('La réponse du partage n\'a pas pu être lue : format inattendu.');
        }

        $resources = [];
        foreach ($document->children('DAV:')->response as $response) {
            $resources[] = self::fromResponse($response);
        }

        return $resources;
    }

    private static function fromResponse(\SimpleXMLElement $response): self
    {
        $href = trim((string) $response->children('DAV:')->href);

        // **Only the propstat whose status is 200 carries values.** A
        // server answers a property it does not have with a second
        // propstat at 404, and reading that one back gives empty strings
        // where the first block had the real figures — a file would then
        // measure zero bytes and a full share look bottomless.
        $properties = null;
        foreach ($response->children('DAV:')->propstat as $propstat) {
            $status = (string) $propstat->children('DAV:')->status;
            if (stripos($status, ' 200 ') !== false) {
                $properties = $propstat->children('DAV:')->prop;
                break;
            }
        }

        if ($properties === null) {
            return new self(self::decodeHref($href), false);
        }

        $dav = $properties->children('DAV:');

        return new self(
            self::decodeHref($href),
            isset($dav->resourcetype->children('DAV:')->collection),
            (int) (string) $dav->getcontentlength,
            self::checksumOf((string) $dav->getetag),
            self::textOrNull((string) $dav->getlastmodified),
            self::bytesOrNull((string) $dav->{'quota-available-bytes'}),
            self::bytesOrNull((string) $dav->{'quota-used-bytes'})
        );
    }

    /**
     * An `href` is percent-encoded, and a key may hold anything a file
     * name holds — a space, an accent, a plus sign. Decoding with
     * `rawurldecode()` rather than `urldecode()` because the second turns
     * `+` into a space, and `scoutmagic+1.zip` is a name somebody will
     * have.
     */
    private static function decodeHref(string $href): string
    {
        return rawurldecode($href);
    }

    /**
     * An etag believed only when it looks like an MD5.
     *
     * **`getetag` is not a checksum and the standard never said it was.**
     * It is an opaque validator: Nextcloud writes something of its own,
     * Apache's `mod_dav` writes inode-size-mtime, some servers quote it
     * and some do not. Where it happens to be a 32-character hexadecimal
     * string it is an MD5 of the content and worth having; anywhere else,
     * answering it as an « announced checksum » would make a verification
     * compare a file against a number that describes an inode, and report
     * corruption on a file that is intact.
     */
    private static function checksumOf(string $etag): ?string
    {
        $value = strtolower(trim($etag, " \t\n\r\0\x0B\"'"));
        // Some servers suffix a weak-validator marker; a weak etag says
        // outright that it does not describe the bytes exactly.
        if (str_starts_with($value, 'w/')) {
            return null;
        }

        return preg_match('/^[0-9a-f]{32}$/', $value) === 1 ? $value : null;
    }

    private static function textOrNull(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * A quota figure, or null when the server declines to give one.
     *
     * RFC 4331 lets `quota-available-bytes` be absent, and some servers
     * answer a negative sentinel for « no limit at all ». Both are
     * « unknown » rather than « none »: a screen showing a reassuring
     * zero free would be worse than showing nothing.
     */
    private static function bytesOrNull(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        $bytes = (int) $trimmed;

        return $bytes < 0 ? null : $bytes;
    }
}
