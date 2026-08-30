<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Fixtures\ReferenceDataset;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Audit\AuditSource;
use Core\Security\EncryptionService;
use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Camps\Service\CampService;
use Modules\Camps\Service\PlaceService;

/**
 * Creates CampsBlueprint's places and stays through the camps module's own
 * services.
 *
 * `PlaceService::create()` and `CampService::create()` are the two calls the
 * chiefs' forms make, and going through them is what gives every row its
 * entry in the shared audit timeline (Core\Audit, ARCHITECTURE.md §8.66) —
 * "Lieu créé", "Séjour créé", the sections it was given. A row written
 * straight into `camp_camps` would look identical on the list and have no
 * history at all, which is precisely the half-built state this dataset exists
 * to avoid.
 *
 * The audit source is `System`, not `Human`: nobody typed these, and the
 * timeline says so rather than crediting a chief who never existed.
 */
final class CampsSeeder
{
    private readonly PlaceService $placeService;

    private readonly CampService $campService;

    /** @param array<string, int> $sectionIds section handle => sections.id */
    public function __construct(
        \PDO $pdo,
        EncryptionService $encryption,
        private readonly array $sectionIds,
        private readonly ?int $actorId,
    ) {
        $audit = new AuditService(new AuditRepository($pdo, $encryption));
        $places = new PlaceRepository($pdo);

        $this->placeService = new PlaceService($places, $audit);
        $this->campService = new CampService(new CampRepository($pdo, $encryption), $audit, $places);
    }

    /**
     * @return array{places: int, camps: int}
     */
    public function seed(): array
    {
        $placeIds = [];
        foreach (CampsBlueprint::PLACES as $place) {
            $placeIds[$place['handle']] = $this->placeService->create(
                [
                    'name' => $place['name'],
                    'address' => $place['address'],
                    'postal_code' => $place['postalCode'],
                    'city' => $place['city'],
                    'country' => $place['country'],
                    'website_url' => $place['website'],
                ],
                $this->actorId,
                AuditSource::System,
            );
        }

        $camps = 0;
        foreach (CampsBlueprint::CAMPS as $camp) {
            $sectionIds = [];
            foreach ($camp['sections'] as $handle) {
                if (isset($this->sectionIds[$handle])) {
                    $sectionIds[] = $this->sectionIds[$handle];
                }
            }

            $this->campService->create(
                $placeIds[$camp['place']],
                [
                    'stay_type' => $camp['stayType'],
                    'status' => $camp['status'],
                    'start_date' => $camp['start'],
                    'end_date' => $camp['end'],
                    'year_only' => $camp['yearOnly'],
                    'price' => $camp['price'],
                    'participant_count' => $camp['participants'],
                    'booked_by_member_id' => null,
                    'booked_by_name' => $camp['bookedByName'],
                    'section_ids' => $sectionIds,
                ],
                $this->actorId,
                fn (array $ids): string => $this->describeSections($ids),
                AuditSource::System,
            );
            $camps++;
        }

        return ['places' => count($placeIds), 'camps' => $camps];
    }

    /**
     * What the change history writes when a stay is given its sections — the
     * section NAMES, since an id in a timeline tells a chief nothing.
     *
     * @param list<int> $ids
     */
    private function describeSections(array $ids): string
    {
        $names = [];
        foreach ($this->sectionIds as $handle => $sectionId) {
            if (in_array($sectionId, $ids, true)) {
                $names[] = UnitBlueprint::SECTIONS[$handle]['name'];
            }
        }

        return $names === [] ? 'Aucune section' : implode(', ', $names);
    }
}
