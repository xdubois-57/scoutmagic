<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Transport;

use Core\Mail\MailPurpose;

/**
 * One message a lane could not take, waiting for its next attempt (D9).
 *
 * The payload is the ARGUMENTS of `MailService::send()`, never a rendered
 * message — see {@see DeferredMailRepository} for why that choice decides
 * everything else about this queue.
 */
final class DeferredMessage
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_ABANDONED = 'abandoned';

    /**
     * @param array{
     *     to: string,
     *     subject: string,
     *     bodyHtml: string,
     *     bodyText: string,
     *     replyTo: ?string,
     *     fromAddressOverride: ?string,
     *     fromNameOverride: ?string,
     *     extraHeaders: array<string, string>,
     *     attachments: array<int, array{name: string, content: string}>
     * } $payload
     */
    public function __construct(
        public readonly int $id,
        public readonly MailLane $lane,
        public readonly MailPurpose $purpose,
        public readonly array $payload,
        public readonly string $status,
        public readonly int $attempts,
        public readonly string $lastReason,
        public readonly string $nextAttemptAt,
        public readonly string $expiresAt,
        public readonly string $createdAt,
        public readonly ?string $settledAt
    ) {
    }

    /**
     * Whether this message's own deadline has passed (D9).
     *
     * A reminder that arrives three days late is noise, so past this the
     * message is abandoned rather than sent. The deadline is fixed when
     * the message is queued, not recomputed from the retention setting,
     * so lengthening the setting never resurrects something already
     * given up on.
     */
    public function hasExpired(?string $now = null): bool
    {
        return strtotime($this->expiresAt) <= strtotime($now ?? date('Y-m-d H:i:s'));
    }
}
