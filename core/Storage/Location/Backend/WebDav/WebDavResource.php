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
        public readonly ?string $contentMd5 = null,
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
            self::contentMd5Of($properties),
            self::textOrNull((string) $dav->getlastmodified),
            self::bytesOrNull((string) $dav->{'quota-available-bytes'}),
            self::bytesOrNull((string) $dav->{'quota-used-bytes'})
        );
    }

    /**
     * An `href` as a path, decoded one segment at a time.
     *
     * A key may hold anything a file name holds — a space, an accent, a
     * plus sign — so `rawurldecode()` rather than `urldecode()`, which
     * turns `+` into a space and `scoutmagic+1.zip` is a name somebody
     * will have.
     *
     * **The order matters twice.** Decoding the whole href first and
     * parsing afterwards loses a file called `photo?1.jpg`: the server
     * sends `photo%3F1.jpg`, the decode makes it `photo?1.jpg`, and
     * `parse_url()` then reads everything past the `?` as a query string,
     * leaving the key `photo`. And it hands a hostile server a way to
     * smuggle separators: `..%2F..%2Fsecret.jpg` is ONE segment, and
     * decoding before splitting turns it into three. Parsed first, then
     * decoded per segment, a `%2F` stays inside the name it belongs to.
     */
    private static function decodeHref(string $href): string
    {
        $path = parse_url($href, PHP_URL_PATH);
        $path = is_string($path) ? $path : $href;

        return implode('/', array_map(
            static fn (string $segment): string => rawurldecode($segment),
            explode('/', $path)
        ));
    }

    /** ownCloud's and Nextcloud's namespace, where the real digest lives. */
    public const OWNCLOUD_NS = 'http://owncloud.org/ns';

    /**
     * An MD5 of the CONTENT, and only from a property that claims to be
     * one — `<oc:checksums><oc:checksum>MD5:…</oc:checksum></oc:checksums>`.
     *
     * **`getetag` is not a checksum and looking like one does not make it
     * one.** This used to answer the etag whenever it was 32 hexadecimal
     * characters, on the reasoning that such a shape could only be an MD5
     * of the bytes. Nextcloud — the server this type is aimed at first —
     * writes `md5(mtime . inode . dev . size)` for a file on local
     * storage: 32 hexadecimal characters that describe an inode. Handing
     * that over as an announced checksum makes `ProtectedCopier` compare
     * it against the source's real MD5, find a mismatch, **delete the copy
     * it has just uploaded** and report the file as corrupt — on every
     * file, so a safety copy to a Nextcloud would never finish and would
     * say the whole album was damaged.
     *
     * So nothing is announced unless the server states a digest as a
     * digest. Where it does not, the verification falls back to comparing
     * sizes, which is exactly what it already does for an S3 multipart
     * ETag.
     */
    private static function contentMd5Of(\SimpleXMLElement $properties): ?string
    {
        // `children()` answers null for an element carrying nothing in
        // that namespace at all, which is every server that does not know
        // this property — the ordinary case, not an exception.
        $owncloud = $properties->children(self::OWNCLOUD_NS);
        if ($owncloud === null || !isset($owncloud->checksums)) {
            return null;
        }
        $stated = $owncloud->checksums->children(self::OWNCLOUD_NS);
        if ($stated === null) {
            return null;
        }

        // One element per algorithm on some servers, all of them
        // space-separated inside one element on others.
        foreach ($stated->checksum as $entry) {
            foreach (preg_split('/\s+/', trim((string) $entry)) ?: [] as $candidate) {
                if (preg_match('/^md5:([0-9a-f]{32})$/i', $candidate, $matches) === 1) {
                    return strtolower($matches[1]);
                }
            }
        }

        return null;
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
