<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\InboundMail\Client;

/**
 * What the first read of a folder takes, decided once (D11).
 *
 * A folder read for the first time — no cursor yet, or a UIDVALIDITY that
 * changed — is not read from UID 0 any more: only what the server received
 * in the last months, and at most the most recent of those **by reception
 * date** (`INTERNALDATE`). Never by UID: an old message imported into the
 * box gets a higher UID than this week's mail, and a « highest UIDs »
 * rule would keep the import and lose the week.
 *
 * The selection is a list of UIDs read by name, plus the folder's highest
 * UID at the moment it was taken. The cursor starts from that UID, so
 * everything that arrives later is read the ordinary way, nothing retained
 * is skipped, and nothing set aside is ever reached by the cursor.
 */
final class FirstReadSelection
{
    /** @var list<int> */
    public readonly array $uids;

    /**
     * @param int[] $uids the retained messages, in any order
     * @param int $highestUid the folder's highest UID when the selection was taken
     * @param int $leftAside how many messages of the folder were not retained
     */
    public function __construct(
        array $uids,
        public readonly int $highestUid,
        public readonly int $leftAside
    ) {
        $uids = array_values(array_unique(array_filter(
            array_map('intval', $uids),
            static fn(int $uid): bool => $uid > 0 && $uid <= $highestUid
        )));
        sort($uids);
        $this->uids = $uids;
    }
}
