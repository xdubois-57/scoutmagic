<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

/**
 * Generic "photo per section per year" core component, mirroring
 * MemberPhotoService exactly (ARCHITECTURE.md §8): a section's staff group
 * photo for the site's current scout year falls back to the most recent
 * earlier photo, and finally to no photo at all (callers render a
 * landscape placeholder in that case — see TwigFactory::create()'s
 * section_photo() function). Shown on the Staffs page, one per section.
 */
class SectionPhotoService
{
    /**
     * Resolutions for the lifetime of this instance, misses included —
     * same per-request memo as MemberPhotoService.
     *
     * @var array<string, int|null>
     */
    private array $resolved = [];

    public function __construct(private SectionPhotoRepository $repository)
    {
    }

    public function resolveFileId(int $sectionId, int $scoutYearId): ?int
    {
        $key = $sectionId . ':' . $scoutYearId;
        if (!array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = $this->repository->findFileIdForYearOrEarlier($sectionId, $scoutYearId);
        }

        return $this->resolved[$key];
    }

    /**
     * Set (create or replace) the photo for a section at a given scout year.
     */
    public function setPhoto(int $sectionId, int $scoutYearId, int $fileId, ?int $createdBy): void
    {
        $this->repository->upsert($sectionId, $scoutYearId, $fileId, $createdBy);
        // A photo set at year N also answers "year N or earlier" for later
        // years — drop everything rather than track the fallback chain.
        $this->resolved = [];
    }
}
