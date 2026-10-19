<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * A passage whose destination, chosen on the Passage page, is not the
 * section the target year's Desk import gives the animé — one entry of
 * Api\PreparedImportGaps::$passagesElsewhere.
 */
final class PassageMismatch
{
    public function __construct(
        public readonly int $memberId,
        /** Their row in the target year — the id `/admin/members/{id}` takes. */
        public readonly int $memberYearId,
        public readonly string $name,
        /** The section chosen on the Passage page. */
        public readonly int $destinationSectionId,
        /** The section the import gives them. */
        public readonly int $importedSectionId
    ) {
    }
}
