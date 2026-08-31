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
 * A message is associated with zero, one or several business objects
 * (`$links`). `$consumerId`, `$businessReference` and `$linkOrigin`
 * describe the **association this instance was read through** — the
 * consumer asked for one of its own references and gets the message
 * presented from that angle. They are not a claim that the message belongs
 * to that object alone, and a consumer must not read them as one.
 *
 * `$bodyHtml` is already sanitised and stripped of remote images by the
 * time it gets here (§7.9): a consumer never has to remember to do it, and
 * the raw HTML is not kept anywhere it could be reached by forgetting.
 */
class InboundMessage
{
    /**
     * @param string[] $toEmails
     * @param InboundAttachment[] $attachments
     * @param MessageLink[] $links every association the message carries,
     *   this consumer's and everybody else's
     * @param OmittedAttachment[] $omittedAttachments what arrived and was
     *   not kept — deliberately NOT in `$attachments`, which only ever
     *   holds files a consumer can actually open
     */
    public function __construct(
        public readonly int $id,
        public readonly int $mailboxId,
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
        /**
         * Every address the message named. Shown on a consumer's timeline
         * so a manager can see whether a reply also went to somebody else —
         * which is the difference between a private answer and a message
         * copied to the whole staff.
         */
        public readonly array $toEmails = [],
        public readonly array $attachments = [],
        public readonly array $links = [],
        public readonly array $omittedAttachments = []
    ) {
    }

    /**
     * Whether the sender attached something ScoutMagic did not keep. What
     * a screen asks before deciding to explain itself.
     */
    public function hasOmittedAttachments(): bool
    {
        return $this->omittedAttachments !== [];
    }

    /**
     * The associations belonging to one consumer, whatever else the message
     * is associated with.
     *
     * @return MessageLink[]
     */
    public function linksFor(string $consumerId): array
    {
        return array_values(array_filter(
            $this->links,
            static fn(MessageLink $link) => $link->consumerId === $consumerId
        ));
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
