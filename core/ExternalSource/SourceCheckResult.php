<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * The verdict on one registered source.
 *
 * A DIVERGENCE is something that fails the check (and the release gate):
 * the page is gone, no longer says what it must, or its amounts changed. A
 * NOTE is something a human should read but that proves nothing broken —
 * a console that now redirects to a new host, the amounts as read. The
 * weekly skill works from both; the exit code from divergences only.
 */
final class SourceCheckResult
{
    /**
     * @param list<string> $divergences
     * @param list<string> $notes
     */
    public function __construct(
        public readonly ExternalSource $source,
        public readonly int $status,
        public readonly array $divergences,
        public readonly array $notes = [],
        public readonly ?FederalScale $scale = null,
    ) {
    }

    public function isConform(): bool
    {
        return $this->divergences === [];
    }
}
