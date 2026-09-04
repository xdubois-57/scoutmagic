<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

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
    /**
     * Resolutions for the lifetime of this instance, misses included —
     * member_photo() calls this once per person rendered, and a roster
     * page repeats the same people across nav, cards and lists.
     *
     * @var array<string, int|null>
     */
    private array $resolved = [];

    public function __construct(private MemberPhotoRepository $repository)
    {
    }

    public function resolveFileId(int $memberId, int $scoutYearId): ?int
    {
        $key = $memberId . ':' . $scoutYearId;
        if (!array_key_exists($key, $this->resolved)) {
            $this->resolved[$key] = $this->repository->findFileIdForYearOrEarlier($memberId, $scoutYearId);
        }

        return $this->resolved[$key];
    }

    /**
     * Resolve many members' photos in one query and remember them, so
     * that the resolveFileId() calls a page's templates make afterwards
     * (Core\View\TwigFactory's member_photo()) hit the memo instead of the
     * database. A page listing N members used to issue N photo queries —
     * 132 on the trombinoscope of a large unit; a controller that knows
     * which members it is about to render primes them here first.
     *
     * @param array<int, int> $memberIds
     */
    public function primeFileIds(array $memberIds, int $scoutYearId): void
    {
        $missing = [];
        foreach (array_unique(array_map('intval', $memberIds)) as $memberId) {
            if (!array_key_exists($memberId . ':' . $scoutYearId, $this->resolved)) {
                $missing[] = $memberId;
            }
        }
        if ($missing === []) {
            return;
        }

        $found = $this->repository->findFileIdsForYearOrEarlier($missing, $scoutYearId);
        foreach ($missing as $memberId) {
            $this->resolved[$memberId . ':' . $scoutYearId] = $found[$memberId] ?? null;
        }
    }

    /**
     * Set (create or replace) the photo for a member at a given scout year.
     */
    public function setPhoto(int $memberId, int $scoutYearId, int $fileId, ?int $createdBy): void
    {
        $this->repository->upsert($memberId, $scoutYearId, $fileId, $createdBy);
        // A photo set at year N also answers "year N or earlier" for later
        // years — drop everything rather than track the fallback chain.
        $this->resolved = [];
    }
}
