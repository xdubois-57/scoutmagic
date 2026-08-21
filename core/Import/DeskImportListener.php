<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Import;

/**
 * Optional hook a module implements to reconcile its own member-referencing
 * data at the end of a Desk import, without core depending on the module
 * (ARCHITECTURE.md §7.4). The composition root wires the concrete
 * implementation in only when the module is enabled.
 *
 * A Desk import is the moment the unit's roster becomes authoritative
 * again: members who left simply stop appearing in the CSV. Any module
 * holding a reference to `members.id` — a per-object permission grant, an
 * assignment, an ownership — has derived state to re-sync at exactly that
 * point. Core already does this for itself
 * (`MemberYearRepository::deactivateAllForYear()`,
 * `MappingResolver::deactivateAllSections()`,
 * `UnitStaffSectionService::syncMembership()`); this interface is the same
 * moment, opened to modules.
 *
 * ## Called inside the import transaction
 *
 * `DeskImportService::import()` invokes listeners after
 * `syncMembership()` and **before** the commit, deliberately: a listener's
 * work is derived state, and a roster half-applied to core but not to a
 * module is worse than an import that failed outright and can be retried. A
 * listener that throws therefore rolls the whole import back. Keep the work
 * to bounded, idempotent queries — never a mail send, an HTTP call, or
 * anything that cannot be replayed.
 *
 * ## Deactivate, do not delete
 *
 * The convention every implementation should follow, and the one core
 * follows itself: a member missing from an import loses access, but the row
 * recording that they had it is kept and simply marked inactive, so a
 * member absent from one import — a data-entry slip, a late registration —
 * comes back without an admin having to re-grant anything.
 */
interface DeskImportListener
{
    /**
     * @param int $scoutYearId The year that was just imported.
     * @param int[] $activeMemberIds Every `members.id` the import left active for that year. A reference to a member id outside this list is what "no longer on the roster" means.
     */
    public function onDeskImportCompleted(int $scoutYearId, array $activeMemberIds): void;
}
