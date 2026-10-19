<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * Thrown by ScoutYearAdminService::activatePublicYear() when the switch
 * for everyone is refused: the staff year is not that year, it has no
 * successful import, or the registration module vetoes (specifications.md
 * §16.3, hard block 3; §19.2).
 */
final class PublicYearActivationBlockedException extends ScoutYearActivationBlockedException
{
    public const ACTIVATION = 'Public activation';

    /** 0 when the registration veto is not among the blockers. */
    public function getBlockingRequestCount(): int
    {
        foreach ($this->getBlockers() as $blocker) {
            if ($blocker->code === ActivationBlocker::REGISTRATION_VETO) {
                return $blocker->count;
            }
        }

        return 0;
    }
}
