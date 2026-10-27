<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\MassMail\Repository;

/**
 * One member a communication is meant for (mass_mail_targets, issue #999) —
 * a classic list's member, or a mail-merge row's Tiers. The unit of the
 * member page's history and of the notification, counted apart from the
 * messages (Delivery), which are per address.
 */
final class Target
{
    public function __construct(
        public readonly int $id,
        public readonly int $emailId,
        public readonly int $memberId,
        // The year whose member_years row the member page link goes
        // through; null when the member had none at freeze time.
        public readonly ?int $scoutYearId,
        // The file row (mail merge only), null otherwise and after the purge.
        public readonly ?int $audienceRowId,
        public readonly string $createdAt
    ) {
    }
}
