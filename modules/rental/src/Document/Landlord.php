<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Core\Config\SettingService;
use Core\Config\UnitAddresses;
use Modules\Rental\Repository\RentalAsset;

/**
 * Who lets the asset: the party printed as « le bailleur » at the head of a
 * contract and an invoice (issue #497).
 *
 * **Not simply the unit.** The contract used to print the unit's name
 * (`site_name`) over an address read from `unit_address` — a setting
 * nothing declared, so the address was empty on every real installation.
 * And the name was wrong as often as not: the hall a unit lets is
 * frequently owned by a separate non-profit (an ASBL), which is the party
 * that signs.
 *
 * Resolution goes from the most specific level to the least, and **a level
 * is taken whole**: its name, its address and its enterprise number
 * together. Mixing levels field by field is exactly the defect this class
 * replaces — an ASBL's name over the unit's address, or the other way
 * round. A level applies as soon as its name or its address is written:
 *
 * 1. the asset's own (`rental_assets.landlord_*`, on the asset's settings
 *    page), for a unit whose hall and meadow have different owners;
 * 2. the module's landlord (`landlord_*` in the rental settings);
 * 3. the unit itself: `site_name`, its legal postal address
 *    (`UnitAddresses`), and no enterprise number — most units have none.
 *
 * A level written with an address but no name is the unit letting from
 * another address, so the name falls back to the unit's; a level with a
 * name and no address keeps the missing address missing rather than
 * borrowing the unit's, which would print the wrong place under the right
 * name.
 *
 * Static, taking the settings as an argument, for the same reason as
 * `AssetConditions`: stateless, and every caller already holds the store.
 */
final class Landlord
{
    public const SOURCE_ASSET = 'asset';
    public const SOURCE_MODULE = 'module';
    public const SOURCE_UNIT = 'unit';

    /**
     * A Belgian enterprise number: ten digits starting with 0 or 1, with or
     * without « BE », dots or spaces. The same pattern as the module
     * setting's `validation_regex` in module.json.
     */
    public const ENTERPRISE_NUMBER_PATTERN = '/^(BE)?\s?[01][0-9]{3}[.\s]?[0-9]{3}[.\s]?[0-9]{3}$/';

    private function __construct(
        public readonly string $name,
        public readonly ?string $address,
        public readonly ?string $enterpriseNumber,
        public readonly string $source
    ) {
    }

    /**
     * The landlord of $asset: its own when it has one, else the module's,
     * else the unit.
     */
    public static function forAsset(SettingService $settingService, RentalAsset $asset): self
    {
        $name = self::text($asset->landlordName);
        $address = self::text($asset->landlordAddress);
        if ($name !== null || $address !== null) {
            return new self(
                $name ?? self::unitName($settingService),
                $address,
                self::text($asset->landlordEnterpriseNumber),
                self::SOURCE_ASSET
            );
        }

        return self::resolve($settingService);
    }

    /**
     * The landlord of an asset with none of its own: the module's, else
     * the unit.
     */
    public static function resolve(SettingService $settingService): self
    {
        $name = self::text($settingService->get('landlord_name', 'rental'));
        $address = self::text($settingService->get('landlord_address', 'rental'));
        if ($name !== null || $address !== null) {
            return new self(
                $name ?? self::unitName($settingService),
                $address,
                self::text($settingService->get('landlord_enterprise_number', 'rental')),
                self::SOURCE_MODULE
            );
        }

        return new self(
            self::unitName($settingService),
            UnitAddresses::postalAddress($settingService),
            null,
            self::SOURCE_UNIT
        );
    }

    public static function isValidEnterpriseNumber(string $value): bool
    {
        return preg_match(self::ENTERPRISE_NUMBER_PATTERN, trim($value)) === 1;
    }

    private static function unitName(SettingService $settingService): string
    {
        return self::text($settingService->get('site_name')) ?? 'Unité scoute';
    }

    private static function text(mixed $value): ?string
    {
        $text = trim(is_scalar($value) ? (string) $value : '');

        return $text === '' ? null : $text;
    }
}
