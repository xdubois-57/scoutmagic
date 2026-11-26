<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Geo;

use Core\Service\TextNormalizerService;

/**
 * What a form carrying the core's point picker
 * (`partials/point_picker.html.twig`, `public/assets/js/point-picker.js`)
 * says about its point — and whether that point is a human's (#1353).
 *
 * The picker posts five fields: `latitude` and `longitude`, `point_automatic`
 * (« the pin sits where the address lookup put it, nobody touched it »),
 * `point_address` (the address that automatic pin was found for) and
 * `point_manual` (« a human placed, moved or removed the pin »). Without
 * JavaScript only the two coordinates are posted, and a point typed there
 * is a human's.
 *
 * The rules were the carpool's (#642, #692) and every module that shows a
 * point picker needs the same ones, so they live here rather than in each
 * module's service:
 *
 * - an automatic pin found for ANOTHER address than the one posted — the
 *   form was sent before the lookup of the new address answered — is not
 *   this address's point: the form is taken as not carrying one, and the
 *   module's geocoding task finds it;
 * - « Retirer » posts empty coordinates and `point_manual`: a human's « no
 *   point », locked like a placed one, or the task would put back the pin
 *   the person just took away;
 * - an untouched point posted back as it was changes nothing.
 *
 * What each module does with the answer — `GeoPointStore::setManual()` for
 * a human's point, `recordGeocoding()` for an automatic one — stays the
 * module's: the core decides what the form meant, never who may save it.
 */
final class PointSubmission
{
    private function __construct(
        /** The posted point, or null — none, removed, or dropped as stale. */
        public readonly ?GeoPoint $point,
        /** The form carried the coordinates, and they are this address's. */
        public readonly bool $given,
        /** The pin came from the address lookup and nobody touched it. */
        public readonly bool $automatic,
        /** A human removed the pin with « Retirer ». */
        public readonly bool $removed
    ) {
    }

    /**
     * @param array<string, mixed> $input the posted form
     * @param string $address the address the module will save with it
     * @throws GeoPointException when the coordinates themselves are refused
     */
    public static function fromInput(array $input, string $address): self
    {
        $given = array_key_exists('latitude', $input) || array_key_exists('longitude', $input);
        $point = GeoPoint::fromInput(
            isset($input['latitude']) ? (string) $input['latitude'] : null,
            isset($input['longitude']) ? (string) $input['longitude'] : null
        );
        $automatic = (string) ($input['point_automatic'] ?? '') === '1';
        if (
            $automatic && $point !== null
            && TextNormalizerService::fold((string) ($input['point_address'] ?? ''))
                !== TextNormalizerService::fold($address)
        ) {
            $given = false;
            $point = null;
        }

        return new self(
            $point,
            $given,
            $automatic,
            $given && $point === null && !$automatic && (string) ($input['point_manual'] ?? '') === '1'
        );
    }

    /**
     * Whether this submission is worth writing over what is saved.
     *
     * Moved, typed or removed by hand, or found for a NEW address — written
     * back even when it lands on the same coordinates, because the module
     * forgot the old address's geocoding when the address changed. A form
     * that did not carry the point, or posts the saved one back untouched,
     * changes nothing.
     *
     * @param bool $addressChanged whether the saved address is a different one
     */
    public function changes(?GeoPoint $saved, bool $savedIsManual, bool $addressChanged): bool
    {
        if (!$this->given) {
            return false;
        }

        return $this->point?->line() !== $saved?->line()
            || ($addressChanged && $this->automatic && $this->point !== null)
            // « Retirer » on a row the task has not placed yet: nothing to
            // compare, but a human's « no point » all the same.
            || ($this->removed && !$savedIsManual);
    }

    /**
     * Whether a form shown again shows a human's pin — the picker's
     * `data-manual`. A form shown after a refusal carries the answer
     * itself: the script posts `point_manual`; without the script, a point
     * posted without `point_automatic` was typed — unless it is the saved
     * automatic point, which the edit form posts back untouched.
     *
     * @param array<string, mixed> $submitted the refused form, or [] on a first display
     */
    public static function showsManual(array $submitted, ?GeoPoint $saved, bool $savedIsManual): bool
    {
        if ($savedIsManual) {
            return true;
        }
        if ($submitted === []) {
            return false;
        }
        if ((string) ($submitted['point_manual'] ?? '') === '1') {
            return true;
        }
        if (
            trim((string) ($submitted['latitude'] ?? '')) === ''
            || (string) ($submitted['point_automatic'] ?? '') === '1'
        ) {
            return false;
        }

        return $saved === null || $saved->line() !== self::postedLine($submitted);
    }

    /**
     * @param array<string, mixed> $submitted
     */
    private static function postedLine(array $submitted): ?string
    {
        try {
            return GeoPoint::fromInput(
                (string) ($submitted['latitude'] ?? ''),
                (string) ($submitted['longitude'] ?? '')
            )?->line();
        } catch (GeoPointException) {
            return null;
        }
    }
}
