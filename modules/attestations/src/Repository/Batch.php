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
        public readonly ?int $createdBy,
        public readonly ?string $distributionStartedAt = null,
        public readonly ?string $notifiedAt = null
    ) {
    }

    public function isPublished(): bool
    {
        return $this->status === BatchStatus::Published;
    }

    /**
     * Published, and nobody told yet — the state the screen has to shout
     * about. A tax certificate has a short window of use: a family that
     * does not know theirs is there will ask for it in June, by e-mail, to
     * the treasurer.
     */
    public function awaitsDistribution(): bool
    {
        return $this->isPublished() && $this->distributionStartedAt === null;
    }

    /** The send is under way: asked for, not finished. */
    public function isDistributing(): bool
    {
        return $this->distributionStartedAt !== null && $this->notifiedAt === null;
    }
}
