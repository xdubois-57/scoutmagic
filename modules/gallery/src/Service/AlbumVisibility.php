<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Modules\Gallery\Repository\Album;

/**
 * Which ordinary (non-delegated) albums one caller may see — the ONE rule
 * the album list and every direct route (an album page, its ZIP, one
 * media's download, one rendition) answer with, so the two can never
 * disagree again (issue #1112, audit SEC-013).
 *
 * Below `chief`, an album is visible when BOTH hold:
 *
 *  - its scout year is one of the relevant years (the current one and the
 *    one before it — Core\Config\ScoutYearService::currentAndPreviousYearIds());
 *  - it is unit-wide (no section) or belongs to one of the caller's linked
 *    sections.
 *
 * The list used to apply both halves in SQL while the direct routes asked
 * only the second, so a parent could open — and ZIP — any past year's
 * album by counting ids. The period is an ACCESS rule, not a display
 * preference: an album from four years ago is refused to a parent whether
 * it is the unit's or their own child's section's.
 *
 * `chief` and above see every album of every year, whatever its section
 * (everything()).
 *
 * The list cannot call allows() row by row without reading the whole
 * album history, so Repository\AlbumRepository::findVisible() states the
 * same two conditions in SQL from scoutYearIds() and sectionIds(); a test
 * runs both over a matrix of years × sections × roles and requires the
 * same answer (Tests\Modules\Gallery\Service\AlbumVisibilityTest).
 *
 * Delegated albums are not this class's business: their owning module
 * decides (Service\DelegatedAlbumAccessRegistry).
 */
final class AlbumVisibility
{
    /**
     * @param int[] $scoutYearIds
     * @param int[] $sectionIds
     */
    private function __construct(
        private readonly bool $everything,
        private readonly array $scoutYearIds,
        private readonly array $sectionIds
    ) {
    }

    /** `chief` and above: every album, every year, every section. */
    public static function everything(): self
    {
        return new self(true, [], []);
    }

    /**
     * Everybody below `chief`.
     *
     * @param int[] $relevantScoutYearIds the current and previous scout year
     * @param int[] $linkedSectionIds     the sections the caller's linked members belong to
     */
    public static function forMember(array $relevantScoutYearIds, array $linkedSectionIds): self
    {
        return new self(
            false,
            array_values(array_unique(array_map('intval', $relevantScoutYearIds))),
            array_values(array_unique(array_map('intval', $linkedSectionIds)))
        );
    }

    public function seesEverything(): bool
    {
        return $this->everything;
    }

    /**
     * The years a member may see albums of — empty for everything(), which
     * is not restricted by year at all.
     *
     * @return int[]
     */
    public function scoutYearIds(): array
    {
        return $this->scoutYearIds;
    }

    /**
     * The sections, besides unit-wide albums, a member may see albums of.
     *
     * @return int[]
     */
    public function sectionIds(): array
    {
        return $this->sectionIds;
    }

    public function allows(Album $album): bool
    {
        if ($this->everything) {
            return true;
        }
        if (!in_array($album->scoutYearId, $this->scoutYearIds, true)) {
            return false;
        }

        return $album->sectionId === null || in_array($album->sectionId, $this->sectionIds, true);
    }
}
