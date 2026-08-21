<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * A stored inbound message, as a consumer module sees it.
 *
 * Only messages a consumer claimed are ever stored (§7.6), so every
 * instance of this class belongs to exactly one business object. There is
 * no "all messages" view and no query that returns one — see
 * InboundMailInterface.
 *
 * `$bodyHtml` is already sanitised and stripped of remote images by the
 * time it gets here (§7.9): a consumer never has to remember to do it, and
 * the raw HTML is not kept anywhere it could be reached by forgetting.
 */
class InboundMessage
{
    /**
     * @param InboundAttachment[] $attachments
     */
    public function __construct(
        public readonly int $id,
        public readonly string $consumerId,
        public readonly string $businessReference,
        public readonly LinkOrigin $linkOrigin,
        public readonly string $subject,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly string $messageId,
        public readonly ?string $inReplyTo,
        public readonly \DateTimeImmutable $sentAt,
        public readonly string $bodyText,
        public readonly string $bodyHtml,
        public readonly array $attachments = []
    ) {
    }

    public function displayFrom(): string
    {
        return $this->fromName !== null && $this->fromName !== ''
            ? $this->fromName . ' <' . $this->fromEmail . '>'
            : $this->fromEmail;
    }

    public function hasAttachments(): bool
    {
        return $this->attachments !== [];
    }
}
