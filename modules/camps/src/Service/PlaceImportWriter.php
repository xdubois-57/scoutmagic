<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Geo\GeoPoint;
use Core\Geo\GeoPointException;
use Modules\Camps\Api\ExistingPlace;
use Modules\Camps\Api\ImportedPlace;
use Modules\Camps\Api\PlaceImportWriterInterface;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Support;

/**
 * Camp sites taken over from another site, with no side effect
 * (chantier « Migration de données », lot G).
 *
 * The place is created through PlaceService::create() — the same row and
 * the same « Lieu créé » history line as a place typed in the form — and
 * then given what the form cannot set at creation: the source's
 * coordinates, locked as manual through the core's GeoPointStore rule so
 * the geocoding task never moves them, and the archived state.
 *
 * Nothing here looks anything up. No geocoding: a place without
 * coordinates simply joins the geocoding queue afterwards, exactly like a
 * place created by hand. And no language model: the duplicate comparison
 * runs the detector's textual level alone, archived places included.
 *
 * What does not fit is left out rather than refusing the place — the spec
 * asks for the place to be created « avec les informations reconnues » —
 * and {@see unrecognisedFields()} says beforehand what that will be, so
 * the import's preview can count it.
 */
final class PlaceImportWriter implements PlaceImportWriterInterface
{
    /** camp_places column widths (schema.sql), in characters. */
    private const MAX_LENGTHS = [
        'address' => 255,
        'postal_code' => 20,
        'city' => 120,
        'country' => 80,
        'website_url' => 500,
    ];

    public function __construct(
        private PlaceRepository $places,
        private PlaceService $placeService,
        private DuplicatePlaceDetector $duplicates,
        private AuditService $audit
    ) {
    }

    public function findExisting(ImportedPlace $place): ?ExistingPlace
    {
        $candidates = $this->duplicates->findCandidates([
            'name' => $place->name,
            'address' => $place->address,
            'postal_code' => $place->postalCode,
            'city' => $place->city,
        ], includeArchived: true, askAi: false);

        $best = null;
        foreach ($candidates as $candidate) {
            if ($candidate['certainty'] === 'certain') {
                $best = $candidate;
                break;
            }
            $best ??= $candidate;
        }
        if ($best === null) {
            return null;
        }

        $existing = $best['place'];

        return new ExistingPlace(
            $existing->id,
            $best['certainty'] === 'certain',
            $existing->name,
            Support::clean($existing->city) ?? Support::clean($existing->postalCode)
        );
    }

    public function unrecognisedFields(ImportedPlace $place): array
    {
        return $this->recognise($place)['unrecognised'];
    }

    public function create(ImportedPlace $place, ?int $actorUserAccountId): int
    {
        $name = trim($place->name);
        if ($name === '' || mb_strlen($name) > ImportedPlace::MAX_NAME_LENGTH) {
            throw new \InvalidArgumentException('An imported place needs a name of 1 to 150 characters.');
        }

        $recognised = $this->recognise($place);
        $fields = $recognised['fields'];
        $fields['name'] = $name;

        $id = $this->placeService->create($fields, $actorUserAccountId);
        $this->audit->record(
            PlaceService::ENTITY_TYPE,
            $id,
            'origin',
            null,
            'Ancien site',
            AuditSource::Human,
            'Lieu repris de l\'ancien site par la migration de données',
            null,
            $actorUserAccountId
        );

        $point = $recognised['point'];
        if ($point !== null) {
            $this->places->setManualCoordinates($id, $point->latitude, $point->longitude);
            $this->audit->record(
                PlaceService::ENTITY_TYPE,
                $id,
                'coordinates',
                null,
                $point->line(),
                AuditSource::Human,
                'Coordonnées reprises de l\'ancien site — le géocodage automatique ne touchera pas ce lieu',
                null,
                $actorUserAccountId
            );
        }

        if ($place->isArchived) {
            $this->places->archive($id, true);
            $this->audit->record(
                PlaceService::ENTITY_TYPE,
                $id,
                'archived',
                null,
                'Archivé',
                AuditSource::Human,
                'Lieu archivé sur l\'ancien site, repris archivé',
                null,
                $actorUserAccountId
            );
        }

        return $id;
    }

    /**
     * The values that fit, and the keys of those that do not.
     *
     * @return array{fields: array<string, string|null>, point: ?GeoPoint, unrecognised: list<string>}
     */
    private function recognise(ImportedPlace $place): array
    {
        $values = [
            'address' => $place->address,
            'postal_code' => $place->postalCode,
            'city' => $place->city,
            'country' => $place->country,
            'website_url' => $place->websiteUrl,
        ];

        $fields = [];
        $unrecognised = [];
        foreach ($values as $key => $value) {
            $value = Support::clean($value);
            if ($value !== null && $key === 'website_url') {
                try {
                    $value = $this->placeService->cleanWebsiteUrl($value);
                } catch (CampsException) {
                    $value = null;
                    $unrecognised[] = $key;
                }
            }
            if ($value !== null && mb_strlen($value) > self::MAX_LENGTHS[$key]) {
                $value = null;
                $unrecognised[] = $key;
            }
            $fields[$key] = $value;
        }

        // The place form's own rule, both halves or neither, within
        // bounds — but where the form refuses the whole submission, an
        // import leaves the coordinates out and still creates the place.
        $point = null;
        try {
            $point = GeoPoint::fromInput($place->latitude, $place->longitude);
        } catch (GeoPointException) {
            $unrecognised[] = 'coordinates';
        }

        return ['fields' => $fields, 'point' => $point, 'unrecognised' => $unrecognised];
    }
}
