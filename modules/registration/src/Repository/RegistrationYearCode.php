<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Repository;

/**
 * The in-year registration code for one scout year — see schema.sql's
 * registration_year_codes comment. Not a security barrier: $code is
 * plain text, permanently visible to the chief.
 */
final class RegistrationYearCode
{
    public function __construct(
        public readonly int $scoutYearId,
        public readonly string $code,
        public readonly bool $isActive
    ) {
    }
}
