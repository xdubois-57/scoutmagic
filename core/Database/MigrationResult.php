<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Database;

class MigrationResult
{
    /**
     * @param array<string> $executedStatements
     * @param array<string> $warnings
     * @param bool $complete False when MigrationRunner::migrate() stopped
     *   early because it hit its time budget — a real, in-progress
     *   migration, not a failure. The schema hash is never cached in this
     *   case, so the next migrate() call for the same schema files
     *   resumes automatically (MigrationRunner persists exactly where it
     *   left off). A caller that treats "the update/restore/module-load
     *   finished" as final (e.g. Core\Maintenance\Task\
     *   InstallUpdateHandler) must check this before doing so.
     * @param bool $converged False when the migration gave up: the same
     *   statements failed on several consecutive passes, so the attempt
     *   was abandoned rather than retried forever (MigrationRunner::
     *   CONVERGENCE_ATTEMPTS). $complete is still true — the runner is
     *   done, and the schema hash is cached so the site stops serving the
     *   progress page — but the schema did NOT reach what the files
     *   declare. A caller for whom that is a failed operation rather than
     *   a degraded one (Core\Maintenance\Task\InstallUpdateHandler, which
     *   has a backup to roll back to) must check this, not just $complete.
     * @param float $progressFraction 0.0–1.0 estimate of how much of the
     *   in-progress attempt is done — meaningless (always 1.0) when
     *   $complete is true. Drives the progress bar on the migration-in-
     *   progress page (Core\Http\Controller\SystemController) across
     *   repeated short migrate() calls.
     */
    public function __construct(
        public readonly array $executedStatements,
        public readonly array $warnings,
        public readonly bool $complete = true,
        public readonly float $progressFraction = 1.0,
        public readonly bool $converged = true
    ) {
    }

    public function hasChanges(): bool
    {
        return count($this->executedStatements) > 0;
    }
}
