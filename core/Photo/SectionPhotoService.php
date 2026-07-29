<?php

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
    public function __construct(private SectionPhotoRepository $repository)
    {
    }

    public function resolveFileId(int $sectionId, int $scoutYearId): ?int
    {
        return $this->repository->findFileIdForYearOrEarlier($sectionId, $scoutYearId);
    }

    /**
     * Set (create or replace) the photo for a section at a given scout year.
     */
    public function setPhoto(int $sectionId, int $scoutYearId, int $fileId, ?int $createdBy): void
    {
        $this->repository->upsert($sectionId, $scoutYearId, $fileId, $createdBy);
    }
}
