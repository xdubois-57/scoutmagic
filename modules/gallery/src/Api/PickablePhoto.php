<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * One photo the picker offers: what the chief sees to choose it — a
 * thumbnail through the gallery's own URL, never its bytes.
 */
final class PickablePhoto
{
    public function __construct(
        public readonly int $mediaId,
        public readonly string $albumTitle,
        public readonly string $thumbUrl,
        public readonly bool $isCover,
    ) {
    }
}
