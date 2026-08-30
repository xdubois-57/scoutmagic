<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * One association between a message and a business object.
 *
 * A message carries **zero, one or several** of these. That is the whole
 * point of the table behind it: the same email can be a booking's
 * correspondence and an invoice at once, and used to have to be stored
 * twice — or belong to one module only — to be both.
 */
class MessageLink
{
    public function __construct(
        public readonly string $consumerId,
        public readonly string $businessReference,
        public readonly LinkOrigin $origin,
        /**
         * Which attachment this association is about, or **0 for the whole
         * message** — never null. Two NULLs are distinct inside a MySQL
         * unique index, so a nullable column here would let the same
         * association be created twice.
         */
        public readonly int $attachmentId = 0,
        /**
         * Null means the association was made automatically, by an
         * analysis rather than by somebody.
         */
        public readonly ?int $createdByUserAccountId = null,
        public readonly ?\DateTimeImmutable $createdAt = null
    ) {
    }

    /** Whether this association is about the message as a whole. */
    public function isWholeMessage(): bool
    {
        return $this->attachmentId === 0;
    }
}
