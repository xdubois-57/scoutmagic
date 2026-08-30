<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Attestations\Repository;

use Modules\Attestations\Value\AttestationCategory;
use Modules\Attestations\Value\BatchStatus;

/**
 * One deposited file. A read model: `public readonly` properties and no
 * logic beyond reading its own fields.
 *
 * It carries counts and never names. The lines a chef d'unité unchecked are
 * deleted at validation, and what survives them is `discardedCount` — the
 * answer to « pourquoi 43 attestations pour 55 membres ? » six months
 * later, without keeping a list of who was left out.
 */
final class Batch
{
    public function __construct(
        public readonly int $id,
        public readonly int $scoutYearId,
        public readonly AttestationCategory $category,
        public readonly string $label,
        public readonly BatchStatus $status,
        public readonly int $pageCount,
        public readonly int $pagesPerDocument,
        public readonly int $documentCount,
        public readonly int $discardedCount,
        public readonly string $createdAt,
        public readonly ?string $publishedAt,
        public readonly ?int $createdBy
    ) {
    }

    public function isPublished(): bool
    {
        return $this->status === BatchStatus::Published;
    }
}
