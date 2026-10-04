<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Modules\Camps\Repository\CampRepository;
use Modules\Camps\Repository\PlaceRepository;
use Modules\Gallery\Api\DelegatedAlbumDescriber;

/**
 * Tells gallery's administration pages what a stay's delegated album is
 * called — "Grand camp — Ferme de la Hulotte — 12–19 juillet 2028" rather
 * than "camp_camp #10", which is what an administrator saw on
 * Configuration › Galerie › Albums until issue #749.
 *
 * The read-only twin of Service\CampAlbumAccessChecker beside it, keyed by
 * the same OWNER_TYPE constant so the two can never claim different types.
 * It answers a NAME and nothing else: who may open the album is the
 * checker's question, and an administrator reading a list of albums in
 * order to move one between storage locations is not thereby allowed to
 * look inside it.
 *
 * The label is computed from the stay as it stands now, never read back
 * from the album's stored title. Correcting a date or renaming a place
 * therefore moves the label on the next page load, with no media touched
 * and no `gallery_albums` row rewritten — the dynamic half of issue #749,
 * and the same way Modules\Groups' own describer has always worked.
 */
class CampDelegatedAlbumDescriber implements DelegatedAlbumDescriber
{
    public function __construct(
        private CampRepository $camps,
        private PlaceRepository $places
    ) {
    }

    public function supports(string $ownerType): bool
    {
        return $ownerType === CampAlbumAccessChecker::OWNER_TYPE;
    }

    public function describe(int $ownerId): ?string
    {
        $camp = $this->camps->findById($ownerId);
        if ($camp === null) {
            return null;
        }

        // A stay whose place row is gone still has a type and a date, and
        // those are enough to recognise it. Only the whole stay missing
        // means "nobody can name this album any more".
        return CampLabels::albumOwnerLabel(
            $camp->stayType,
            $this->places->findById($camp->placeId)?->name,
            $camp->startDate,
            $camp->endDate,
            $camp->yearOnly
        );
    }
}
