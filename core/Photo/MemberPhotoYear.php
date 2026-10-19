<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Photo;

/**
 * The photo shown for one member in one scout year, and the year it was
 * taken for — MemberPhotoRepository::findPhotoYears().
 *
 * `$photoScoutYearId` and `$fileId` are the photo's own: the asked year
 * itself for `PhotoOrigin::Exact`, an earlier one for `Earlier`, both null
 * for `None`. The file id is the one `member_photo()` renders, so a
 * consumer never resolves the photo a second, different way.
 */
final class MemberPhotoYear
{
    public function __construct(
        public readonly int $memberId,
        public readonly PhotoOrigin $origin,
        public readonly ?int $photoScoutYearId,
        public readonly ?int $fileId
    ) {
    }
}
