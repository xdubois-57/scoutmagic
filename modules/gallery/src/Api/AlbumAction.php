<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * One button another module adds to an album's management page: what it
 * says, where it leads, which Bootstrap icon it carries (`bi-…`).
 */
final class AlbumAction
{
    public function __construct(
        public readonly string $label,
        public readonly string $url,
        public readonly string $icon,
    ) {
    }
}
