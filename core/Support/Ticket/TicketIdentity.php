<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

/**
 * What authenticates one outgoing support ticket: the installation's own
 * identifier, and the secret that proves it (roadmap IT-24).
 *
 * The secret travels in an `Authorization: Bearer` header and **never** in
 * a body, a log line or a view — the same rule the statistics report
 * follows, for the same reason.
 */
final class TicketIdentity
{
    public function __construct(
        public readonly string $installationId,
        public readonly string $secret
    ) {
    }
}
