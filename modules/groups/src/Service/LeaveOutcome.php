<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

/**
 * Why leaving a group succeeded or did not.
 *
 * A named outcome rather than a bool because the two refusals need
 * different French sentences, and a Controller reconstructing "which of
 * the two was it" from a false would be re-deriving something the service
 * had just established.
 */
enum LeaveOutcome
{
    case LEFT;

    /** Membership comes from a linked section, so there is nothing to leave. */
    case DERIVED_MEMBERSHIP;

    /** The group's last explicit moderator — somebody has to stay in charge. */
    case LAST_MODERATOR;

    public function message(): string
    {
        return match ($this) {
            self::LEFT => 'Vous avez quitté ce groupe.',
            self::DERIVED_MEMBERSHIP => 'Vous faites partie de ce groupe via votre section : cette appartenance suit '
                . 'les données de la fédération et ne peut pas être quittée ici.',
            self::LAST_MODERATOR => 'Vous êtes le dernier modérateur de ce groupe : désignez d\'abord un autre '
                . 'modérateur avant de le quitter.',
        };
    }
}
