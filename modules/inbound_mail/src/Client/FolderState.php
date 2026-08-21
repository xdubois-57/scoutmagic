<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

/**
 * What a folder reports about itself when it is opened: the UIDVALIDITY
 * that gives its UIDs meaning, and the highest UID it currently holds.
 *
 * Returned separately from the messages so the caller can notice a
 * renumbering (`Mailbox\FolderCursor`) before deciding what to ask for.
 */
class FolderState
{
    public function __construct(
        public readonly string $folder,
        public readonly int $uidValidity,
        public readonly int $highestUid
    ) {
    }
}
