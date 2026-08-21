<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Mailbox;

/**
 * What the last synchronisation of a mailbox did.
 *
 * `NEVER` is a real state, distinct from `OK`: "we have not tried yet" and
 * "we tried and it worked" are different facts, and a page that showed both
 * as a green tick would hide a box that has never once connected.
 */
enum SyncState: string
{
    case NEVER = 'never';
    case OK = 'ok';
    case ERROR = 'error';

    public function label(): string
    {
        return match ($this) {
            self::NEVER => 'Jamais synchronisée',
            self::OK => 'Synchronisée',
            self::ERROR => 'En erreur',
        };
    }

    public function badgeClass(): string
    {
        return match ($this) {
            self::NEVER => 'text-bg-secondary',
            self::OK => 'text-bg-success',
            self::ERROR => 'text-bg-danger',
        };
    }
}
