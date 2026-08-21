<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Calendar\Api;

use Core\Security\Role;

/**
 * Who is looking at the calendar, handed to every virtual-event provider
 * (ARCHITECTURE.md §7.6).
 *
 * **Resolved once per generation, never per event.** A month view can carry
 * forty bars and an ICS feed several hundred; re-deriving the viewer's
 * rights for each of them would turn one page into hundreds of queries.
 * Providers are told the same thing once and cache whatever they need for
 * the length of the call.
 *
 * `$email` is null for a feed with no identified reader at all — a
 * calendar's public ICS token, for instance. That is a meaningful distinct
 * case, not a missing value: a provider must give such a reader the least
 * detailed rendering it has, and never guess an identity from the token.
 */
final class VirtualEventViewer
{
    public function __construct(
        public readonly Role $role,
        public readonly ?string $email,
        public readonly int $scoutYearId,
        /**
         * The calendars this reader is actually looking at. A provider
         * publishing onto a calendar absent from this list must return
         * nothing for it — otherwise a bare `/calendar/feed/{token}.ics`
         * for the Animateurs calendar would quietly carry another
         * calendar's events too.
         *
         * @var int[]
         */
        public readonly array $calendarIds = []
    ) {
    }

    /** Whether $calendarId is one this reader is looking at. */
    public function seesCalendar(?int $calendarId): bool
    {
        return $calendarId !== null && in_array($calendarId, $this->calendarIds, true);
    }

    /** Whether anybody is actually identified behind this request. */
    public function isIdentified(): bool
    {
        return $this->email !== null && $this->email !== '';
    }
}
