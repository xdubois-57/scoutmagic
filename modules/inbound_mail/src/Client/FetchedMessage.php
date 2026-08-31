<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

/**
 * One message as a client hands it over: decoded, but untrusted and
 * un-sanitised.
 *
 * The HTML here is still exactly what the sender wrote — sanitising is
 * `Service\MessageContentSanitizer`'s job, done once on the way in, before
 * a consumer ever sees the message (§7.9). A client that sanitised on its
 * own would mean two implementations of the rule and one of them going
 * stale.
 */
class FetchedMessage
{
    /**
     * @param string[] $references
     * @param string[] $toEmails
     * @param FetchedAttachment[] $attachments
     */
    public function __construct(
        public readonly int $uid,
        public readonly string $folder,
        public readonly string $messageId,
        public readonly string $subject,
        public readonly string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $toEmails,
        public readonly ?string $inReplyTo,
        public readonly array $references,
        public readonly \DateTimeImmutable $sentAt,
        public readonly string $bodyText,
        public readonly string $bodyHtml,
        public readonly array $attachments = [],
        /**
         * Automatic mail — a newsletter, a bounce, an acknowledgement,
         * spam. Read off the headers by `Mime\BulkMailDetector`, once, on
         * arrival.
         *
         * It changes nothing about whether the message is stored or
         * offered to the consumers: a great many booking platforms send
         * with `Precedence: bulk`, and a flag that suppressed analysis
         * would quietly lose a unit its rental enquiries.
         */
        public readonly bool $isBulk = false,
        /**
         * The message's raw header block, verbatim, exactly as it arrived
         * — every folded line, in order, with nothing decoded.
         *
         * Carried for the consumers that asked to keep it
         * (Api\MessageRetentionPreference, roadmap IT-22): the
         * authentication verdict and the relay chain are only readable
         * here, since everything else on this object is the parse's
         * conclusions rather than what was written. Empty when a client
         * cannot supply it, which is a complete answer — the column is
         * then simply left NULL.
         */
        public readonly string $rawHeaders = ''
    ) {
    }
}
