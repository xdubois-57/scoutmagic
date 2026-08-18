<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * A delegated album as seen from outside the gallery module — just enough
 * for the owning module to keep the id (to pass back into
 * DelegatedAlbumManager's other methods) and render a title, never
 * gallery's own internal Repository\Album (storage_location_id,
 * migration state, etc. stay module-private).
 */
final class DelegatedAlbum
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $albumDate
    ) {
    }
}
