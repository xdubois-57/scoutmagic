<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

/**
 * One provider's place in one lane's fallback chain
 * (ARCHITECTURE.md §8.106).
 */
final class LaneEntry
{
    public function __construct(
        public readonly MailLane $lane,
        public readonly int $providerId,
        public readonly int $position,
        public readonly bool $enabled
    ) {
    }

    public function isLocal(): bool
    {
        return $this->providerId === MailProvider::LOCAL_ID;
    }
}
