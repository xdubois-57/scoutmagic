<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * The gallery's photos, offered to another module that composes an image
 * from one of them (the social module's free communication). What a caller
 * may see is the gallery's rule, answered here — never recomputed by the
 * caller.
 */
interface PhotoPickerInterface
{
    /**
     * At most thirty photos, newest first: the covers of the fifteen most
     * recent local albums the caller may see, whatever their year,
     * completed by the most recent other photos.
     *
     * @param array<int, int> $linkedMemberIds the members the session is linked to
     * @return list<PickablePhoto>
     */
    public function pickablePhotos(string $role, array $linkedMemberIds): array;

    /**
     * The bytes of one photo's large rendition, or null when it is not a
     * processed photo of an album the caller may see.
     *
     * @param array<int, int> $linkedMemberIds
     */
    public function photoContents(int $mediaId, string $role, array $linkedMemberIds): ?string;
}
