<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Presences\Api;

/**
 * Public contract DTO for consuming modules (ARCHITECTURE.md §7.5) — an
 * attendance sheet as seen from outside this module: the short address to
 * write into an agenda, and nothing else.
 *
 * No event id, no section, no counts. What a calendar feed needs is a
 * line of text; anything more would be this module's internals travelling
 * through its own contract, and a reader of the feed is not somebody this
 * module has decided anything about beyond « may open this sheet ».
 */
final class PresenceSheetLink
{
    public function __construct(
        /** The full address, composed by the provider — scheme, host and all. */
        public readonly string $url
    ) {
    }
}
