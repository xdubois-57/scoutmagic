<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Camps\Service;

use Core\Member\Duplicate\RepointingMergeListener;

/**
 * Brings who booked a camp, and who reviewed it, along when two identities
 * of a chef are merged (issue #1462). Neither column is unique: both
 * simply follow.
 */
final class CampMergeListener extends RepointingMergeListener
{
    protected const PLAIN = [
        ['camp_camps', 'booked_by_member_id'],
        ['camp_reviews', 'author_member_id'],
    ];

    protected const PREVIEW = [
        'camp(s) réservé(s) ou évalué(s)' => ['camp_camps', 'booked_by_member_id'],
    ];

    public function moduleId(): string
    {
        return 'camps';
    }
}
