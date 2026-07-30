<?php

declare(strict_types=1);

namespace Modules\Gallery\Service;

use Core\Config\ScoutYearService;
use Core\Member\SectionService;
use Modules\Gallery\Api\GalleryAlbumProvider;
use Modules\Gallery\Repository\Album;
use Modules\Gallery\Repository\AlbumRepository;
use Modules\Gallery\Repository\MediaRepository;

/**
 * The concrete implementation behind Api\GalleryAlbumProvider — a thin
 * wrapper so the public API surface never exposes Repository\
 * AlbumRepository (or PDO) directly to other modules/core. Mirrors
 * Controller\GalleryController's own list-page resolution logic
 * (section codes -> ids, cover URL) but scoped to a single caller-supplied
 * scout year rather than "current or previous".
 */
class GalleryMemberQueryService implements GalleryAlbumProvider
{
    public function __construct(
        private AlbumRepository $albumRepository,
        private MediaRepository $mediaRepository,
        private MediaService $mediaService,
        private SectionService $sectionService,
        private ScoutYearService $scoutYearService
    ) {
    }

    public function getAlbumsForMember(array $sectionCodes, string $scoutYearLabel, int $limit): array
    {
        $scoutYearId = $this->resolveScoutYearId($scoutYearLabel);
        if ($scoutYearId === null) {
            return [];
        }

        $albums = array_slice(
            $this->albumRepository->findVisible($this->resolveSectionIds($sectionCodes), [$scoutYearId]),
            0,
            $limit
        );

        return array_map(fn(Album $album) => [
            'id' => $album->id,
            'title' => $album->isLocal() || $album->ogTitle === null ? $album->title : $album->ogTitle,
            'album_date' => $album->albumDate,
            'cover_url' => $this->coverUrl($album),
            'url' => $album->isLocal() ? '/gallery/' . $album->id : (string) $album->externalUrl,
        ], $albums);
    }

    private function coverUrl(Album $album): ?string
    {
        if ($album->coverMediaId !== null) {
            $cover = $this->mediaRepository->findById($album->coverMediaId);
            return $cover !== null ? $this->mediaService->resolveUrl($cover, $album, 'thumb') : null;
        }
        return $album->isLocal() || $album->ogImageFileId === null ? null : '/files/' . $album->ogImageFileId;
    }

    /**
     * @param string[] $sectionCodes
     * @return int[]
     */
    private function resolveSectionIds(array $sectionCodes): array
    {
        if ($sectionCodes === []) {
            return [];
        }
        $ids = [];
        foreach ($this->sectionService->getAllWithBranches() as $section) {
            if (in_array($section['desk_code'], $sectionCodes, true)) {
                $ids[] = (int) $section['id'];
            }
        }
        return $ids;
    }

    private function resolveScoutYearId(string $label): ?int
    {
        foreach ($this->scoutYearService->getAll() as $year) {
            if ($year['label'] === $label) {
                return (int) $year['id'];
            }
        }
        return null;
    }
}
