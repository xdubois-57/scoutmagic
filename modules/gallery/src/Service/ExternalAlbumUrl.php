<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

/**
 * The comparable form of an external album's address: how the gallery
 * decides that two links name the same album.
 *
 * **Safe, and therefore narrow.** Only what never changes the page a link
 * opens is folded:
 * - the scheme and the host, which are case-insensitive by definition
 *   (RFC 3986, sections 3.1 and 3.2.2): `HTTPS://Photos.Example.org/a` is
 *   `https://photos.example.org/a`;
 * - the fragment (`#…`), which a browser never sends to the server;
 * - the blanks around the address.
 *
 * Everything else is kept byte for byte — the path, the query string, a
 * trailing slash, the port, `http` against `https` — because a photo host
 * is free to make any of them meaningful, and a normalisation that merged
 * two different albums would silently drop one of them from an import.
 */
final class ExternalAlbumUrl
{
    /**
     * The normalised address, or null when this is not an absolute
     * `http`/`https` address with a host.
     */
    public static function normalize(string $url): ?string
    {
        $url = trim($url);
        // A blank or a control character inside an address is not one a
        // browser would send as is; refusing it is safer than guessing.
        if ($url === '' || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
            return null;
        }
        if (preg_match('~^([A-Za-z][A-Za-z0-9+.\-]*)://([^/?#]*)([^#]*)~', $url, $parts) !== 1) {
            return null;
        }

        $scheme = strtolower($parts[1]);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }

        // userinfo@host:port — only the host is case-insensitive.
        $authority = $parts[2];
        $at = strrpos($authority, '@');
        $userInfo = $at === false ? '' : substr($authority, 0, $at + 1);
        $hostAndPort = strtolower($at === false ? $authority : substr($authority, $at + 1));
        // A host — a name or a bracketed IPv6 literal — and, if any, a port
        // that is a number a socket can open.
        if (preg_match('~^(\[[0-9a-f:.]+\]|[^:\[\]]+)(?::(\d{1,5}))?$~', $hostAndPort, $host) !== 1) {
            return null;
        }
        if (isset($host[2]) && ((int) $host[2] < 1 || (int) $host[2] > 65535)) {
            return null;
        }

        return $scheme . '://' . $userInfo . $hostAndPort . $parts[3];
    }
}
