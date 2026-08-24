<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Modules\Camps\Repository\Place;
use Modules\Camps\Repository\PlaceRepository;

/**
 * Camp sites: creation, edition, and the change history that goes with
 * them (Core\Audit entity type 'camp_place').
 */
class PlaceService
{
    public const ENTITY_TYPE = 'camp_place';

    public function __construct(
        private PlaceRepository $places,
        private AuditService $audit
    ) {
    }

    /**
     * @param array<string, string|null> $fields name/address/postal_code/city/country/website_url
     */
    public function create(array $fields, ?int $actorUserAccountId, AuditSource $source = AuditSource::Human): int
    {
        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '') {
            throw new CampsException('Un lieu a besoin d\'un nom.');
        }

        $id = $this->places->create(
            $name,
            $this->clean($fields['address'] ?? null),
            $this->clean($fields['postal_code'] ?? null),
            $this->clean($fields['city'] ?? null),
            $this->clean($fields['country'] ?? null),
            $this->cleanUrl($fields['website_url'] ?? null),
        );

        $this->audit->record(
            self::ENTITY_TYPE, $id, 'name', null, $name, $source,
            'Lieu créé', null, $actorUserAccountId
        );

        return $id;
    }

    /**
     * @param array<string, string|null> $fields
     */
    public function update(Place $place, array $fields, ?int $actorUserAccountId, AuditSource $source = AuditSource::Human): void
    {
        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '') {
            throw new CampsException('Un lieu a besoin d\'un nom.');
        }

        $address = $this->clean($fields['address'] ?? null);
        $postalCode = $this->clean($fields['postal_code'] ?? null);
        $city = $this->clean($fields['city'] ?? null);
        $country = $this->clean($fields['country'] ?? null);
        $websiteUrl = $this->cleanUrl($fields['website_url'] ?? null);

        $this->places->update($place->id, $name, $address, $postalCode, $city, $country, $websiteUrl);

        // Recorded field by field rather than as one "lieu modifié": a
        // history is read to find out what a value used to be, which a
        // single line naming none of them cannot answer.
        $this->recordChange($place->id, 'name', $place->name, $name, $source, $actorUserAccountId);
        $this->recordChange(
            $place->id,
            'address',
            $this->addressLine($place->address, $place->postalCode, $place->city, $place->country),
            $this->addressLine($address, $postalCode, $city, $country),
            $source,
            $actorUserAccountId
        );
        $this->recordChange($place->id, 'website', $place->websiteUrl, $websiteUrl, $source, $actorUserAccountId);
    }

    /**
     * The address as one readable line, which is also how the history
     * shows it: five separate entries for one address correction is
     * noise, and the reader wants "what was the address before".
     */
    public function addressLine(?string $address, ?string $postalCode, ?string $city, ?string $country): ?string
    {
        $parts = array_values(array_filter([
            $address,
            trim(($postalCode ?? '') . ' ' . ($city ?? '')),
            $country,
        ], static fn(?string $p): bool => $p !== null && trim($p) !== ''));

        return $parts !== [] ? implode(', ', $parts) : null;
    }

    private function recordChange(
        int $placeId,
        string $fieldKey,
        ?string $from,
        ?string $to,
        AuditSource $source,
        ?int $actorUserAccountId
    ): void {
        if ($from === $to) {
            return;
        }

        $this->audit->record(self::ENTITY_TYPE, $placeId, $fieldKey, $from, $to, $source, null, null, $actorUserAccountId);
    }

    private function clean(?string $value): ?string
    {
        $value = $value !== null ? trim($value) : null;

        return $value !== null && $value !== '' ? $value : null;
    }

    /**
     * A website that is not http(s) is refused rather than stored and
     * rendered as a link — a "javascript:" in an href is the whole reason
     * this check exists, and a place sheet renders this value as one.
     */
    private function cleanUrl(?string $value): ?string
    {
        $value = $this->clean($value);
        if ($value === null) {
            return null;
        }
        if (!preg_match('~^https?://~i', $value)) {
            $value = 'https://' . $value;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) === false) {
            throw new CampsException('L\'adresse du site web n\'est pas une adresse valide.');
        }

        return $value;
    }
}
