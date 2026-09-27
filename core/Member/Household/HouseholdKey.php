<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Member\Household;

/**
 * Which household an address belongs to, as an identity the core mints and
 * nobody else can make or read (issue #630).
 *
 * It is the blind index of the normalized address, but that is the core's
 * business and nobody else's. The registration module keys its count of
 * incoming requests on a household
 * ({@see \Modules\Registration\Api\HouseholdRegistrationCountProvider}),
 * and until #630 it had to know how one is derived — which normalizer,
 * which purpose string — because it recomputed the index itself when a
 * request was created. Two derivations of one key, in two layers: the day
 * either changed its normalization, the other would silently stop matching,
 * and no test or page would fail, since a blind index never throws. It
 * simply finds nobody.
 *
 * So the core keeps the derivation (the maintainer's decision on #630), and
 * what crosses the module boundary is this object. A module may **store**
 * it ({@see storable()}) and compare what it stored with what it is later
 * handed; it never builds one. `Tests\Architecture\ModulesNeverMintAHouseholdKeyTest`
 * holds that line, because PHP has no package-private constructor to hold
 * it for us.
 */
final class HouseholdKey
{
    private function __construct(private readonly string $value)
    {
    }

    /**
     * For the core only: its repositories derive the key
     * ({@see HouseholdRepository::keyForAddress()}), or read one the core
     * stored itself (`member_addresses.address_normalized_blind_index`).
     */
    public static function fromStorable(string $value): self
    {
        if ($value === '') {
            throw new \InvalidArgumentException('A household key is never empty: an unusable address has none.');
        }

        return new self($value);
    }

    /**
     * The value to store in a column and compare against later. Opaque: it
     * is not an address, and no part of it can be read back as one.
     */
    public function storable(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return hash_equals($this->value, $other->value);
    }
}
