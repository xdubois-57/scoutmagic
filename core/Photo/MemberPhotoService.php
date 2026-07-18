<?php

declare(strict_types=1);

namespace Core\Photo;

/**
 * Generic "photo per person per year" core component (ARCHITECTURE.md §8):
 * every photo is tied to a member AND a scout year. Resolving a member's
 * photo for the site's current scout year falls back to the most recent
 * earlier photo, and finally to no photo at all (callers render an
 * initials-in-a-circle avatar in that case — see TwigFactory::create()'s
 * member_photo() function).
 *
 * Not specific to any one module: any page that shows a member's photo for
 * the current scout year should go through this service.
 */
class MemberPhotoService
{
    public function __construct(private MemberPhotoRepository $repository)
    {
    }

    public function resolveFileId(int $memberId, int $scoutYearId): ?int
    {
        return $this->repository->findFileIdForYearOrEarlier($memberId, $scoutYearId);
    }

    /**
     * Set (create or replace) the photo for a member at a given scout year.
     */
    public function setPhoto(int $memberId, int $scoutYearId, int $fileId, ?int $createdBy): void
    {
        $this->repository->upsert($memberId, $scoutYearId, $fileId, $createdBy);
    }
}
