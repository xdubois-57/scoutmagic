<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ScoutYear;

/**
 * Thrown by ScoutYearAdminService::activateStaffYear() when the year has
 * no successful Desk import on record: the staff would be served an empty
 * year (hard block 2).
 */
final class StaffYearActivationBlockedException extends ScoutYearActivationBlockedException
{
    public const ACTIVATION = 'Staff activation';
}
