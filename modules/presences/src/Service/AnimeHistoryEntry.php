<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Modules\Presences\Value\PresenceStatus;

/**
 * One evening of one animé's history, with what the staff wrote beside
 * it — this is what somebody re-reads before phoning a family, and it is
 * the reason the page exists rather than a fold-out panel on the
 * register.
 */
final class AnimeHistoryEntry
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $title,
        public readonly string $startDate,
        public readonly PresenceStatus $status,
        public readonly ?string $comment
    ) {
    }
}
