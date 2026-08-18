<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Gallery\Api;

/**
 * One media of a delegated album, as seen from outside the gallery
 * module. Deliberately carries no storage path or URL: every rendition is
 * addressed only through GET /gallery/media/{id}/{size}, whatever the
 * storage backend — the owning module builds that URL itself from `id`
 * and the size it wants ('thumb', 'medium', 'large', 'original'), the
 * same well-known route an ordinary album's own templates use.
 * `processingStatus` is 'pending'|'processing'|'done'|'failed'
 * (Repository\Media's own constants) — a size is only actually servable
 * once processing reaches 'done'.
 */
final class DelegatedMedia
{
    public function __construct(
        public readonly int $id,
        public readonly string $mediaType,
        public readonly string $processingStatus,
        public readonly int $sortOrder,
        public readonly ?string $originalFilename,
        public readonly string $createdAt
    ) {
    }
}
