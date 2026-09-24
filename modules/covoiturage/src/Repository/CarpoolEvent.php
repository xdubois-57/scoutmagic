<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Covoiturage\Repository;

/**
 * A calendar event a carpool serves, as copied when it was linked: its
 * title for display and its section for the staff visibility (D3).
 */
final class CarpoolEvent
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $title,
        public readonly ?int $sectionId,
        public readonly ?string $sectionName
    ) {
    }
}
