<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * What the advisory source answered for one CPE name this run.
 *
 * `pending` is the NVD's own rate limit, not a failure: without a key it
 * accepts five requests per rolling thirty seconds, so a run asks at most
 * {@see HostSecurityService::NVD_REQUESTS_PER_RUN} and the next run, one
 * minute later, asks the rest ({@see \Core\Maintenance\Task\
 * CheckHostSecurityHandler}).
 */
final class AdvisoryLookup
{
    public const OK = 'ok';
    public const STALE = 'stale';
    public const UNAVAILABLE = 'unavailable';
    public const PENDING = 'pending';

    /**
     * @param list<Advisory> $advisories already deduplicated and sorted
     * @param int $total how many the source reported, which may exceed what was kept
     */
    public function __construct(
        public readonly string $status,
        public readonly array $advisories = [],
        public readonly int $total = 0,
        public readonly ?int $fetchedAt = null,
    ) {
    }

    public function hasData(): bool
    {
        return $this->status === self::OK || $this->status === self::STALE;
    }
}
