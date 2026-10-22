<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Stay;

/**
 * One photo of an incident (#989): a JPEG `Core\Image\PhotoNormalizer`
 * produced, served through `FileAccessGuard` (`file_url()`), its thumbnail
 * the `thumb` derivative beside it (`/files/{id}/thumb`).
 */
final class IncidentPhoto
{
    public function __construct(
        public readonly int $id,
        public readonly int $incidentId,
        public readonly int $bookingId,
        public readonly int $fileId,
        public readonly int $position,
        public readonly \DateTimeImmutable $createdAt
    ) {
    }
}
