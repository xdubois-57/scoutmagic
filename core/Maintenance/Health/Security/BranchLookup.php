<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * What one version source answered for this run: fresh data, the last
 * usable data kept after a failure (`stale`, dated), or nothing.
 */
final class BranchLookup
{
    public const OK = 'ok';
    public const STALE = 'stale';
    public const UNAVAILABLE = 'unavailable';

    /**
     * @param list<ReleaseBranch> $branches
     */
    public function __construct(
        public readonly string $status,
        public readonly array $branches = [],
        public readonly ?int $fetchedAt = null,
    ) {
    }

    public function hasData(): bool
    {
        return $this->status !== self::UNAVAILABLE;
    }

    /**
     * The branch a version belongs to: the longest published name that is
     * a prefix of it, so « 1.1.1w » finds « 1.1.1 » and never « 1.1 ».
     */
    public function branchFor(VersionNumber $version): ?ReleaseBranch
    {
        $best = null;
        $bestLength = -1;
        foreach ($this->branches as $branch) {
            if (!$version->isInBranch($branch->name)) {
                continue;
            }
            $length = substr_count($branch->name, '.');
            if ($length > $bestLength) {
                $best = $branch;
                $bestLength = $length;
            }
        }

        return $best;
    }
}
