<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Api;

/**
 * An attachment that arrived and was **not kept**.
 *
 * Deliberately a different type from `InboundAttachment`, and deliberately
 * held in a different property of `InboundMessage`. An omitted attachment
 * has no file id, so a consumer that iterated one list and read `->fileId`
 * would be handed a hole; keeping the two apart means every existing
 * consumer stays correct without knowing this type exists.
 *
 * What it is for is the screen: telling a reader that the message arrived
 * whole and one file was not kept, rather than showing one attachment fewer
 * than the sender sent and leaving them to wonder.
 */
class OmittedAttachment
{
    public function __construct(
        public readonly int $id,
        public readonly int $messageId,
        public readonly string $filename,
        public readonly string $mimeType,
        public readonly int $sizeBytes,
        public readonly AttachmentOmission $reason
    ) {
    }

    /**
     * The French sentence shown next to the name — always ending with
     * where the file still is, because this module never touches the
     * remote mailbox and the reader's next move is to go and fetch it.
     */
    public function explanation(): string
    {
        return $this->reason->explanation();
    }
}
