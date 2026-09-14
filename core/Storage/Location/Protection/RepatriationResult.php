<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Protection;

/**
 * What one run of a repatriation did.
 */
final class RepatriationResult
{
    /**
     * @param array<string, string> $failures key => French reason
     */
    public function __construct(
        public readonly bool $finished,
        public readonly ?string $cursor,
        public readonly int $examinedCount,
        public readonly int $restoredCount,
        public readonly array $failures = []
    ) {
    }
}
