<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Repository;

use Modules\Presences\Value\PresenceStatus;

/**
 * One recorded decision about one animé at one evening — the row as the
 * rest of the module reads it, comment already decrypted (SECURITY.md §5:
 * decryption happens in the Repository and nowhere else).
 *
 * $comment is null when there is none; it is never an empty string, so a
 * caller asking « is there a comment » has one question to ask rather
 * than two.
 */
final class PresenceRecord
{
    public function __construct(
        public readonly int $id,
        public readonly int $calendarEventId,
        public readonly int $memberId,
        public readonly PresenceStatus $status,
        public readonly ?string $comment,
        public readonly string $updatedAt
    ) {
    }
}
