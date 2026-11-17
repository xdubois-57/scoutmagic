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
 *
 * **A first read leaves a list behind it** (D11). The first read of a
 * folder takes only the recent messages, chosen by reception date, so
 * they are not a UID range: they are named, one by one, in `$pendingUids`,
 * and the cursor itself already stands at the folder's highest UID of that
 * moment. Each pass reads a batch of the list and removes it; what arrives
 * meanwhile is above `$lastUid` and read the ordinary way. The list is all
 * at or below `$lastUid`, so moving the cursor never touches it.
 */
class FolderCursor
{
    /** @var list<int> ascending, every one at or below $lastUid */
    public readonly array $pendingUids;

    /**
     * @param int[] $pendingUids the retained messages of a first read not read yet
     */
    public function __construct(
        public readonly int $mailboxId,
        public readonly string $folder,
        public readonly int $uidValidity,
        public readonly int $lastUid,
        array $pendingUids = []
    ) {
        $pending = array_values(array_unique(array_filter(
            array_map('intval', $pendingUids),
            static fn(int $uid): bool => $uid > 0 && $uid <= $lastUid
        )));
        sort($pending);
        $this->pendingUids = $pending;
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
            ? new self($this->mailboxId, $this->folder, $this->uidValidity, $uid, $this->pendingUids)
            : $this;
    }

    public function hasPending(): bool
    {
        return $this->pendingUids !== [];
    }

    /**
     * The next at most $count retained messages to read, lowest UID first.
     *
     * @return list<int>
     */
    public function nextPending(int $count): array
    {
        return $count > 0 ? array_slice($this->pendingUids, 0, $count) : [];
    }

    /**
     * This cursor once $uids have been read — whether the server handed
     * each one back or not: a UID asked for by name and not returned is
     * gone from the folder or unreadable, and asking again would only meet
     * it again.
     *
     * @param int[] $uids
     */
    public function withoutPending(array $uids): self
    {
        if ($uids === []) {
            return $this;
        }

        return new self(
            $this->mailboxId,
            $this->folder,
            $this->uidValidity,
            $this->lastUid,
            array_diff($this->pendingUids, $uids)
        );
    }
}
