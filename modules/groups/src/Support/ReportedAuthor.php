<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Support;

/**
 * Who wrote the item a report is about — the two identities every post
 * and reply carries, passed to Service\GroupNotificationService so it can
 * do two things at once: keep the author out of the notification about
 * their own content, and notice when that author is one of the group's
 * moderators and the report therefore has to escalate.
 *
 * A tiny value object rather than two more parameters on an already
 * five-parameter method: "int, int" at a call site says nothing, and
 * getting them the wrong way round would silently escalate the wrong
 * reports.
 */
final class ReportedAuthor
{
    public function __construct(
        public readonly int $userAccountId,
        public readonly int $memberId
    ) {
    }
}
