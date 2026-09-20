<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * An attachment's **bytes**, handed to a consumer that asked for them by
 * type and that will not keep them.
 *
 * **The deliberate opposite of {@see CandidateAttachment}**, and the
 * exception that proves its rule. That class carries metadata and no
 * bytes because `analyze()` runs inside the synchronisation, where reading
 * a fifteen-megabyte PDF would blow through `max_execution_time` on shared
 * hosting and leave the cursor unmoved — the same doomed run repeating on
 * every tick.
 *
 * Nothing about that reasoning changes here. What changes is the size of
 * what is read: a consumer taking this route declares the types it wants
 * and a ceiling per payload ({@see PayloadConsumerInterface}), and the
 * module hands it nothing larger. A DMARC aggregate report is a few
 * kilobytes of gzipped XML; a machine wrote it for another machine, and no
 * person will ever open it.
 *
 * **Which is also why nothing is stored.** These bytes never become a file
 * — `Service\AttachmentPolicy` is not consulted and its allowlist is not
 * widened, because that list answers a different question: which
 * attachments become documents a person opens. An archive is rightly
 * refused there and would be refused here too, if here were about keeping
 * anything. {@see \Core\Mail\Feedback\Bounce\BounceConsumer} has the same
 * shape one layer up: it reads a machine's report out of the body, records
 * a counter, claims nothing, and the message is forgotten on the ordinary
 * retention schedule.
 */
class MessagePayload
{
    public function __construct(
        public readonly string $filename,
        /**
         * Sniffed from the bytes by `Service\AttachmentPolicy`, never the
         * `Content-Type` the sender wrote. A message announcing
         * `application/gzip` over something else is the ordinary shape of
         * an attack, not an edge case.
         */
        public readonly string $mimeType,
        public readonly string $bytes
    ) {
    }

    public function sizeBytes(): int
    {
        return strlen($this->bytes);
    }
}
