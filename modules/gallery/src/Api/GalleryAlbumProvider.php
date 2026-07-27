<?php

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * Public contract for consuming modules/core — the only entry point into
 * gallery for anything outside the module itself (ARCHITECTURE.md §7.5).
 * Core\Http\Controller\MemberController takes this as a nullable
 * dependency to render a member's "Galerie" section; when gallery is
 * disabled, that dependency is never wired and the section simply doesn't
 * appear — no other part of core ever references this module by name.
 */
interface GalleryAlbumProvider
{
    /**
     * Albums relevant to a member during a given scout year — unit-wide
     * albums plus any scoped to one of the given sections. Newest first.
     *
     * @param string[] $sectionCodes desk_codes of the sections the member belongs to that year
     * @return array<int, array{id: int, title: string, album_date: string, cover_url: ?string, url: string}>
     */
    public function getAlbumsForMember(array $sectionCodes, string $scoutYearLabel, int $limit): array;
}
