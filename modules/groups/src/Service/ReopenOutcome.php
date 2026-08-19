<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

/**
 * Why reopening a closed group succeeded or did not — the counterpart of
 * LeaveOutcome, and a named outcome for the same reason.
 */
enum ReopenOutcome
{
    case REOPENED;

    case NOT_CLOSED;

    /** An archived group stays a read-only archive (prompt 3's rule). */
    case PAST_YEAR;

    public function message(): string
    {
        return match ($this) {
            self::REOPENED => 'Le groupe est rouvert : vous pouvez y publier à nouveau.',
            self::NOT_CLOSED => 'Ce groupe n\'est pas clôturé.',
            self::PAST_YEAR => 'Ce groupe appartient à une année scoute passée : il reste consultable, mais ne peut '
                . 'plus être rouvert.',
        };
    }
}
