<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Repository;

/**
 * One (asset, member) management grant. Deliberately holds no name, email
 * or phone: the person behind $memberId is resolved at render time from the
 * core member tables, so this module's schema stores no personal data.
 */
final class RentalAssetManager
{
    public function __construct(
        public readonly int $id,
        public readonly int $assetId,
        /** members.id — the persistent identity, never member_years.id. */
        public readonly int $memberId,
        /**
         * Whether this manager's contact details are shown to the renter on
         * their own tracking page. Never means "publicly visible": no
         * manager's name, phone or email is ever shown to the public.
         */
        public readonly bool $isRenterContact,
        /**
         * Cleared when a Desk import stops listing the member, restored
         * when they reappear. An inactive grant confers nothing, but is
         * kept as the record of who was responsible.
         */
        public readonly bool $isActive
    ) {
    }
}
