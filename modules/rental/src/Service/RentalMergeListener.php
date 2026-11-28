<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Service;

use Core\Member\Duplicate\RepointingMergeListener;

/**
 * Brings a staff member's rental rows along when two of their identities
 * are merged (issue #1462): an asset they manage is still theirs, and what
 * they recorded on a booking is still signed by them.
 *
 * **Collision rule:** one row per asset and manager — an asset managed
 * under both identities keeps the active assignment (the kept identity's
 * on a tie). Every « by » column simply follows.
 */
final class RentalMergeListener extends RepointingMergeListener
{
    protected const UNIQUE = [
        'rental_asset_managers' => ['member_id', ['asset_id'], 'is_active'],
    ];

    protected const PLAIN = [
        ['rental_blocks', 'created_by_member_id'],
        ['rental_booking_comments', 'author_member_id'],
        ['rental_booking_milestone_marks', 'marked_by_member_id'],
        ['rental_booking_modifications', 'closed_by_member_id'],
        ['rental_booking_modifications', 'created_by_member_id'],
        ['rental_booking_modifications', 'sent_by_member_id'],
        ['rental_documents', 'created_by_member_id'],
        ['rental_meter_readings', 'recorded_by_member_id'],
        ['rental_inventory_validations', 'validated_by_member_id'],
        ['rental_incidents', 'created_by_member_id'],
        ['rental_incidents', 'decided_by_member_id'],
        ['rental_incident_photos', 'created_by_member_id'],
        ['rental_settlements', 'created_by_member_id'],
        ['rental_settlements', 'validated_by_member_id'],
    ];

    protected const PREVIEW = [
        'bien(s) en location géré(s)' => ['rental_asset_managers', 'member_id'],
    ];

    public function moduleId(): string
    {
        return 'rental';
    }
}
