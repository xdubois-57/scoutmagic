<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Config;

/**
 * The unit's two addresses, which are not the same place (issue #497).
 *
 * - The **legal postal address**: where letters go, what an official
 *   document prints under the unit's name — a contract, an invoice.
 * - The **premises**: where the unit actually meets. Often a hall with a
 *   street address, sometimes a meadow with none, which is why it may
 *   carry GPS coordinates instead of, or besides, a text.
 *
 * **Declared here rather than inline in `public/index.php`**, and called
 * from there, so a test can go through the real declarations instead of
 * registering its own. That is the whole lesson of #497: `unit_address`
 * was read by the rental contract for its whole life, and registered
 * nowhere but in the contract's own test fixture — so the test was green
 * and every real contract printed an empty landlord address. A fixture
 * that calls `register()` here fails the day a key is renamed, dropped or
 * given a validation it does not meet. `ModuleSettingsAreReadInTheirScopeTest`
 * reads this file among the composition roots for the same reason: these
 * are core's keys, read without a scope from modules.
 *
 * Both are editable from Configuration › Paramètres, core group — the page
 * lists every editable core setting, so there is no screen to build.
 */
final class UnitAddresses
{
    public const POSTAL_ADDRESS = 'unit_postal_address';
    public const PREMISES_ADDRESS = 'unit_premises_address';
    public const PREMISES_LATITUDE = 'unit_premises_latitude';
    public const PREMISES_LONGITUDE = 'unit_premises_longitude';

    /**
     * A decimal degree within range, nothing else: `SettingService` checks
     * `number` with `is_numeric()`, which would take 1e3 or 400 — a point
     * nobody can put on a map. Six decimals are about ten centimetres,
     * more than a map pin needs.
     */
    private const LATITUDE_PATTERN = '^-?(90(\.0{1,6})?|[1-8]?[0-9](\.[0-9]{1,6})?)$';
    private const LONGITUDE_PATTERN = '^-?(180(\.0{1,6})?|(1[0-7][0-9]|[1-9]?[0-9])(\.[0-9]{1,6})?)$';

    public static function register(SettingService $settingService): void
    {
        $settingService->register(
            self::POSTAL_ADDRESS,
            '',
            'textarea',
            'Adresse postale de l\'unité',
            'L\'adresse légale de l\'unité : rue, numéro, code postal et localité. Elle figure sur les '
                . 'documents officiels, par exemple comme adresse du bailleur d\'une location quand '
                . 'aucun autre bailleur n\'est configuré.',
            null,
            null,
            null,
            true,
            11
        );
        $settingService->register(
            self::PREMISES_ADDRESS,
            '',
            'textarea',
            'Adresse des locaux',
            'L\'endroit où l\'unité se réunit, s\'il diffère de l\'adresse postale. Pour un terrain sans '
                . 'véritable adresse, décrivez le lieu et renseignez aussi ses coordonnées GPS.',
            null,
            null,
            null,
            true,
            12
        );
        $settingService->register(
            self::PREMISES_LATITUDE,
            '',
            'text',
            'Latitude des locaux',
            'Facultatif. En degrés décimaux, par exemple 50.8466 ; à renseigner avec la longitude.',
            null,
            self::LATITUDE_PATTERN,
            null,
            true,
            13
        );
        $settingService->register(
            self::PREMISES_LONGITUDE,
            '',
            'text',
            'Longitude des locaux',
            'Facultatif. En degrés décimaux, par exemple 4.3528 ; à renseigner avec la latitude.',
            null,
            self::LONGITUDE_PATTERN,
            null,
            true,
            14
        );
    }

    /**
     * The legal postal address, or null while nobody has written one.
     */
    public static function postalAddress(SettingService $settingService): ?string
    {
        return self::text($settingService, self::POSTAL_ADDRESS);
    }

    /**
     * The premises, or null while nobody has written them.
     */
    public static function premisesAddress(SettingService $settingService): ?string
    {
        return self::text($settingService, self::PREMISES_ADDRESS);
    }

    /**
     * The premises' coordinates, or null unless BOTH are there: half a
     * point is not a place, and a map centred on latitude 50, longitude 0
     * would put the unit in the English Channel.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    public static function premisesCoordinates(SettingService $settingService): ?array
    {
        $latitude = self::text($settingService, self::PREMISES_LATITUDE);
        $longitude = self::text($settingService, self::PREMISES_LONGITUDE);
        if ($latitude === null || $longitude === null
            || preg_match('/' . self::LATITUDE_PATTERN . '/', $latitude) !== 1
            || preg_match('/' . self::LONGITUDE_PATTERN . '/', $longitude) !== 1
        ) {
            return null;
        }

        return ['latitude' => (float) $latitude, 'longitude' => (float) $longitude];
    }

    private static function text(SettingService $settingService, string $key): ?string
    {
        $value = trim((string) ($settingService->get($key) ?? ''));

        return $value === '' ? null : $value;
    }
}
