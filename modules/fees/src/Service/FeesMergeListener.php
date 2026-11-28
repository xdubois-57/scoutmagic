<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Fees\Service;

use Core\Member\Duplicate\RepointingMergeListener;

/**
 * Brings the people of a federation invoice along when two identities of
 * a member are merged (issue #1462): a person matched to the absorbed
 * identity is matched to the kept one. Not unique: rows simply follow.
 */
final class FeesMergeListener extends RepointingMergeListener
{
    protected const PLAIN = [
        ['fees_invoice_people', 'member_id'],
    ];

    protected const PREVIEW = [
        'personne(s) sur une facture fédérale' => ['fees_invoice_people', 'member_id'],
    ];

    public function moduleId(): string
    {
        return 'fees';
    }
}
