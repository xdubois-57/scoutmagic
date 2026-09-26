<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

/**
 * A free communication: a title written on the card, the text of the post,
 * and one image — a gallery photo or an uploaded file, never both.
 */
final class Communication
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly string $body,
        public readonly ?int $galleryMediaId,
        public readonly ?int $fileId,
        public readonly ?int $createdBy,
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function hasImage(): bool
    {
        return $this->galleryMediaId !== null || $this->fileId !== null;
    }
}
