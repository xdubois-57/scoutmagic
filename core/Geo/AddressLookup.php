<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

/**
 * What Core\Geo\AddressLocator answers for one address typed in a form
 * (issue #692). It used to answer a point or null, and null covered four
 * different stories — so the form could say nothing at all, and said
 * nothing. The form now tells the chief which one it is:
 *
 * - FOUND: `$point` is set.
 * - NOT_FOUND: Nominatim does not know this line (or could not be reached
 *   — GeocodingService cannot tell the two apart), or the line is too short
 *   or too long to mean a place.
 * - UNAVAILABLE: the site-wide slot stayed busy past the bounded wait, or
 *   the database failed; nothing was sent.
 * - SUPERSEDED: the same account asked for another address while this one
 *   was waiting its turn; nothing was sent, and the newer request is the
 *   one that answers.
 */
final class AddressLookup
{
    public const FOUND = 'found';
    public const NOT_FOUND = 'not_found';
    public const UNAVAILABLE = 'unavailable';
    public const SUPERSEDED = 'superseded';

    private function __construct(public readonly string $status, public readonly ?GeoPoint $point)
    {
    }

    public static function found(GeoPoint $point): self
    {
        return new self(self::FOUND, $point);
    }

    public static function notFound(): self
    {
        return new self(self::NOT_FOUND, null);
    }

    public static function unavailable(): self
    {
        return new self(self::UNAVAILABLE, null);
    }

    public static function superseded(): self
    {
        return new self(self::SUPERSEDED, null);
    }

    public static function of(?GeoPoint $point): self
    {
        return $point !== null ? self::found($point) : self::notFound();
    }
}
