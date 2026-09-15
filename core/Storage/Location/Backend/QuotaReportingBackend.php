<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location\Backend;

use Core\Storage\Location\StorageQuota;

/**
 * A backend that can say how much room is left without walking its own
 * content — declared as
 * {@see \Core\Storage\Location\StorageCapability::Quota}.
 *
 * **The capability existed before this interface did**, declared by
 * nobody, because the first two backends are precisely the two that
 * cannot answer: a local directory's free space is a property of the
 * VOLUME it sits on (`Core\Storage\DiskBudget` measures that, and a
 * per-location answer would report the same figure three times for three
 * folders on one disk), and an S3 bucket's size is only knowable by
 * listing every object in it. Google Drive is the first destination that
 * simply says, so this is where the method lands.
 *
 * **What it is worth is a failure that is silent by construction.** Nobody
 * looks at the free space of an account they set up two years ago; the
 * send that runs out of room does so at four in the morning, and the
 * operator finds out on the night the server is gone. Fifteen gibibytes
 * shared with a mailbox fills faster than anyone expects, which is what
 * `Core\Alert\Check\RemoteQuotaCheck` exists to notice.
 */
interface QuotaReportingBackend extends StorageBackendInterface
{
    /**
     * How much room the storage says is left, or **null when it will not
     * say** — an account with no quota at all answers this way, and so
     * does one whose provider simply has no such notion.
     *
     * Null is « unknown », never « none »: a caller that renders a
     * percentage must show nothing rather than a reassuring zero.
     *
     * @throws \RuntimeException when the storage could not be asked
     */
    public function quota(): ?StorageQuota;
}
