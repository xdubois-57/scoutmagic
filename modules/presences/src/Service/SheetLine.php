<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Service;

use Modules\Presences\Value\PresenceStatus;

/**
 * One animé's line on a sheet: who they are, where they stand, and what
 * the staff wrote beside them.
 *
 * The name is carried as its three parts rather than pre-composed, because
 * a roll call is read « Nom, Prénom » (the section roster's own shape) and
 * every other screen of the site says « totem ?? prénom » — one DTO,
 * whichever the template needs, and no display rule decided in a service.
 */
final class SheetLine
{
    public function __construct(
        public readonly int $memberId,
        public readonly string $lastName,
        public readonly string $firstName,
        public readonly ?string $totem,
        public readonly PresenceStatus $status,
        public readonly ?string $comment
    ) {
    }
}
