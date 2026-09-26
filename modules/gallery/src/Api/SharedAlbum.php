<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * What another module may publish of an album: its title, its cover image's
 * bytes, and the page it lives on.
 *
 * `coverContents` is null when the album has no cover yet, or when its
 * storage cannot be read right now. The cover is a gallery photo, with
 * everything that implies — the consumer must treat it as such (the social
 * module blurs it, always).
 */
final class SharedAlbum
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $coverContents,
        public readonly string $path,
    ) {
    }
}
