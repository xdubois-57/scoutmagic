<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Repository;

/**
 * One content sent — or tried — to one destination.
 */
final class Publication
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PUBLISHED = 'published';
    public const STATUS_FAILED = 'failed';

    public function __construct(
        public readonly string $sourceKind,
        public readonly int $sourceId,
        public readonly string $destination,
        public readonly string $status,
        public readonly ?string $errorMessage,
        public readonly \DateTimeImmutable $attemptedAt,
        public readonly ?\DateTimeImmutable $publishedAt,
    ) {
    }

    public function isPublished(): bool
    {
        return $this->status === self::STATUS_PUBLISHED;
    }

    /**
     * Failed, or left pending by a request that never finished — both can
     * be tried again, the second after `$staleBefore`.
     */
    public function isRetryable(\DateTimeImmutable $staleBefore): bool
    {
        return $this->status === self::STATUS_FAILED
            || ($this->status === self::STATUS_PENDING && $this->attemptedAt <= $staleBefore);
    }
}
