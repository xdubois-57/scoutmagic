<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Document;

use Core\Config\SettingService;
use Core\Config\UnitAddresses;

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
 * 1. the module's landlord (`landlord_*` in the rental settings);
 * 2. the unit itself: `site_name`, its legal postal address
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
    public const SOURCE_MODULE = 'module';
    public const SOURCE_UNIT = 'unit';

    private function __construct(
        public readonly string $name,
        public readonly ?string $address,
        public readonly ?string $enterpriseNumber,
        public readonly string $source
    ) {
    }

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
