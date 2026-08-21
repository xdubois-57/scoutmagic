<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Mailbox;

/**
 * How far a folder has been read.
 *
 * **An IMAP UID only means anything alongside its folder's UIDVALIDITY.**
 * The server is allowed to renumber a folder — a restore from backup, a
 * migration, some servers on a simple rename — and it announces that by
 * changing UIDVALIDITY. A cursor that remembered only "last UID 4212"
 * would, after such a change, skip every message below the new 4212 and
 * silently lose weeks of mail. So the pair is stored, and a changed
 * UIDVALIDITY resets the UID to zero (§7.5).
 *
 * Re-reading a renumbered folder from the start is not a duplication
 * problem: the message-level dedup is on Message-ID within the mailbox, so
 * a message already stored is recognised whatever UID it now carries.
 */
class FolderCursor
{
    public function __construct(
        public readonly int $mailboxId,
        public readonly string $folder,
        public readonly int $uidValidity,
        public readonly int $lastUid
    ) {
    }

    /**
     * The cursor to use against a folder that just reported
     * $observedUidValidity — this one when it still matches, a fresh one
     * from the beginning of the renumbered folder when it does not.
     */
    public function forUidValidity(int $observedUidValidity): self
    {
        if ($observedUidValidity === $this->uidValidity) {
            return $this;
        }

        return new self($this->mailboxId, $this->folder, $observedUidValidity, 0);
    }

    public function wasReset(int $observedUidValidity): bool
    {
        return $observedUidValidity !== $this->uidValidity;
    }

    public function advancedTo(int $uid): self
    {
        return $uid > $this->lastUid
            ? new self($this->mailboxId, $this->folder, $this->uidValidity, $uid)
            : $this;
    }
}
