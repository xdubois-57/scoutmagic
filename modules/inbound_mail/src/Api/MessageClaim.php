<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * A consumer's answer to "is this message yours?" — the business object it
 * belongs to, and how that was decided.
 *
 * Returning `null` instead of one of these is a complete answer, and the
 * common one: a message nobody claims is discarded unread and unwritten
 * (§7.6).
 */
class MessageClaim
{
    public function __construct(
        public readonly string $businessReference,
        public readonly LinkOrigin $origin
    ) {
    }
}
