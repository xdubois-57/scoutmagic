<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * The module's own public API (ARCHITECTURE.md §7.5) letting Core\ScoutYear\
 * ScoutYearAdminService optionally veto the year-transition workflow's step 4
 * (activating a new public year for everyone) while registration requests are
 * still open — wired as a nullable constructor dependency in the composition
 * root, only when this module is enabled. Core never depends on the module
 * directly; this interface is the only contract between them.
 */
interface ScoutYearTransitionVetoProvider
{
    /**
     * Count of registration requests in a non-final state (pending or
     * accepted), across every target scout year — not just the one being
     * activated. A request that targeted a past year and was simply never
     * processed still counts: this deliberately makes it impossible to
     * accumulate a forgotten backlog by switching years around it.
     */
    public function countBlockingRequests(): int;
}
