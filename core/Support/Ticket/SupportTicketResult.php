<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

/**
 * What became of one outgoing support ticket (roadmap IT-25).
 *
 * Three outcomes, and the difference matters to whoever pressed the
 * button: **sent** (with the reference the maintainer will quote back),
 * **refused** by the receiver for a reason it named, or **not sent at
 * all** because something on the way stopped it. Only the first is ever
 * recorded locally as « Envoyé » — a ticket the receiver never accepted
 * must not leave a trace saying it did.
 *
 * The description is never carried here. It is not needed to say what
 * happened, and a value object that held it would be one more place it
 * could reach a log line.
 */
final class SupportTicketResult
{
    private function __construct(
        public readonly bool $sent,
        public readonly ?string $reference = null,
        /** A category, never free text and never the receiver's prose. */
        public readonly ?string $failureReason = null
    ) {
    }

    public static function sent(string $reference): self
    {
        return new self(true, $reference);
    }

    public static function failed(string $reason): self
    {
        return new self(false, null, $reason);
    }
}
