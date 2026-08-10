<?php

declare(strict_types=1);

namespace Modules\Registration\Api;

/**
 * The module's own public API (ARCHITECTURE.md §7.5) letting Core\Http\
 * Controller\ImportController optionally trigger Desk reconciliation right
 * after a successful import — wired as a nullable constructor dependency
 * in the composition root, only when this module is enabled. Core never
 * depends on the module directly.
 */
interface ReconciliationTrigger
{
    /**
     * Confronts every 'accepted' registration request targeting
     * $scoutYearId against the members just imported for that year — see
     * Service\ReconciliationService for the matching rules.
     */
    public function reconcileForYear(int $scoutYearId): void;
}
