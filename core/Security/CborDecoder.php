<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security;

/**
 * Minimal CBOR (RFC 8949) decoder — just the subset WebAuthn actually puts
 * on the wire: attestation objects and COSE keys.
 *
 * Core\Security\WebAuthnService used to "parse" both by scanning raw bytes
 * for marker values (the RP ID hash, then 0x21/0x22 for a COSE key's x and
 * y labels). That happens to work on common authenticators and is wrong in
 * principle: those byte values also occur INSIDE lengths, keys and
 * coordinates, so the first match is not reliably the field being looked
 * for, and nothing bounded the structure. Decoding properly is both safer
 * and what makes RS256 support possible at all — an RSA key's modulus
 * cannot be found by scanning.
 *
 * Deliberately strict: anything malformed, truncated, or using a feature
 * outside this subset throws rather than returning a best guess. Indefinite
 * lengths, tags, floats and simple values are not supported — no
 * authenticator emits them here.
 */
final class CborDecoder
{
    private const MAJOR_UNSIGNED_INT = 0;
    private const MAJOR_NEGATIVE_INT = 1;
    private const MAJOR_BYTE_STRING = 2;
    private const MAJOR_TEXT_STRING = 3;
    private const MAJOR_ARRAY = 4;
    private const MAJOR_MAP = 5;

    /**
     * Decode the single CBOR item at the start of $data.
     *
     * Trailing bytes after that item are allowed and ignored — an
     * attestation object is followed by nothing, but a COSE key inside
     * authenticator data is followed by the extensions block.
     *
     * @return array{0: mixed, 1: int} the decoded value and the offset just past it
     * @throws \RuntimeException on malformed or unsupported input
     */
    public static function decodeFirst(string $data): array
    {
        return self::decodeItem($data, 0);
    }

    /**
     * @return array{0: mixed, 1: int}
     */
    private static function decodeItem(string $data, int $offset): array
    {
        $initial = self::byteAt($data, $offset);
        $major = $initial >> 5;
        $additional = $initial & 0x1f;
        $offset++;

        [$value, $offset] = self::readArgument($data, $offset, $additional);

        switch ($major) {
            case self::MAJOR_UNSIGNED_INT:
                return [$value, $offset];

            case self::MAJOR_NEGATIVE_INT:
                return [-1 - $value, $offset];

            case self::MAJOR_BYTE_STRING:
            case self::MAJOR_TEXT_STRING:
                if ($offset + $value > strlen($data)) {
                    throw new \RuntimeException('CBOR string runs past the end of the buffer.');
                }
                return [substr($data, $offset, $value), $offset + $value];

            case self::MAJOR_ARRAY:
                $items = [];
                for ($i = 0; $i < $value; $i++) {
                    [$item, $offset] = self::decodeItem($data, $offset);
                    $items[] = $item;
                }
                return [$items, $offset];

            case self::MAJOR_MAP:
                $map = [];
                for ($i = 0; $i < $value; $i++) {
                    [$key, $offset] = self::decodeItem($data, $offset);
                    [$item, $offset] = self::decodeItem($data, $offset);
                    if (!is_int($key) && !is_string($key)) {
                        throw new \RuntimeException('Unsupported CBOR map key type.');
                    }
                    // Integer and text keys share one PHP array here. COSE
                    // and attestation objects never mix the two in a single
                    // map, so no collision is possible in practice.
                    $map[$key] = $item;
                }
                return [$map, $offset];

            default:
                throw new \RuntimeException('Unsupported CBOR major type ' . $major . '.');
        }
    }

    /**
     * Read the argument that follows an initial byte — either packed into
     * the low 5 bits, or in the following 1/2/4/8 bytes.
     *
     * @return array{0: int, 1: int}
     */
    private static function readArgument(string $data, int $offset, int $additional): array
    {
        if ($additional < 24) {
            return [$additional, $offset];
        }

        $lengths = [24 => 1, 25 => 2, 26 => 4, 27 => 8];
        if (!isset($lengths[$additional])) {
            // 28-30 are reserved; 31 is an indefinite length.
            throw new \RuntimeException('Unsupported CBOR argument encoding ' . $additional . '.');
        }

        $count = $lengths[$additional];
        $value = 0;
        for ($i = 0; $i < $count; $i++) {
            $value = ($value << 8) | self::byteAt($data, $offset + $i);
        }

        if ($value < 0) {
            throw new \RuntimeException('CBOR argument overflows a PHP integer.');
        }

        return [$value, $offset + $count];
    }

    private static function byteAt(string $data, int $offset): int
    {
        if ($offset >= strlen($data)) {
            throw new \RuntimeException('Unexpected end of CBOR buffer.');
        }

        return ord($data[$offset]);
    }
}
