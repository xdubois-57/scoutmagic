<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SosStaff\Service;

use Core\Member\Duplicate\RepointingMergeListener;

/**
 * Brings the on-call planning along when two identities of a staff member
 * are merged (issue #1462): a duty planned under the absorbed identity is
 * still on the planning, and still diverts the SOS number.
 *
 * **Collision rule:** one row per member and day — a day planned under
 * both identities keeps the kept identity's. The default number simply
 * follows.
 */
final class SosMergeListener extends RepointingMergeListener
{
    protected const UNIQUE = [
        'sos_oncall_assignments' => ['member_id', ['assignment_date'], 'kept'],
    ];

    protected const PLAIN = [
        ['sos_settings', 'default_number_member_id'],
    ];

    protected const PREVIEW = [
        'jour(s) au planning des gardes SOS' => ['sos_oncall_assignments', 'member_id'],
    ];

    public function moduleId(): string
    {
        return 'sos_staff';
    }
}
