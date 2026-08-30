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
     * @param float $progressFraction 0.0–1.0 estimate of how much of the
     *   in-progress attempt is done — meaningless (always 1.0) when
     *   $complete is true. Drives the progress bar on the migration-in-
     *   progress page (Core\Http\Controller\SystemController) across
     *   repeated short migrate() calls.
     * @param bool $converged False when the migration gave up: the same
     *   statements failed on several consecutive passes, so the attempt
     *   was abandoned rather than retried forever (MigrationRunner::
     *   CONVERGENCE_ATTEMPTS). $complete is still true — the runner is
     *   done, and the schema hash is cached so the site stops serving the
     *   progress page — but the schema did NOT reach what the files
     *   declare. A caller for whom that is a failed operation rather than
     *   a degraded one (Core\Maintenance\Task\InstallUpdateHandler, which
     *   has a backup to roll back to) must check this, not just $complete.
     * @param bool $backupCreated **Upgrade shim. Nothing reads it.**
     *
     * Removing this parameter took production down, and the mechanism is
     * worth understanding because it will happen again otherwise. During a
     * self-update, `Task\InstallUpdateHandler` replaces the files on disk
     * and then runs the migration IN THE SAME PHP PROCESS. Classes already
     * loaded are the old ones; a class not yet loaded is autoloaded from
     * the NEW files. `MigrationResult` is only ever constructed by
     * `MigrationRunner::migrate()`, which nothing calls on an ordinary
     * request — so it is reliably *not* loaded when an update begins, and
     * reliably loaded from the new files a moment later.
     *
     * The old runner, still in memory, calls
     * `new MigrationResult(..., backupCreated: false)`. Against a class
     * that no longer declared it, PHP threw
     * `Error: Unknown named parameter $backupCreated`, the handler caught
     * it, and the update rolled back. Every time, for good: the retry runs
     * the same old code, so the site can never reach the version that
     * would fix it. Six consecutive rollbacks on scoutmagic.be, all
     * identical, all around 32 seconds.
     *
     * Kept as a real (if inert) property rather than a discarded argument
     * so the compatibility promise is visible in the type rather than
     * hidden in a signature nobody reads. Removable once no installation
     * predates the version that reintroduced it — a decision about the
     * field, not about the code.
     *
     * The same rule is why $converged was ADDED at the end with a default
     * rather than inserted among the existing parameters, and why nothing
     * here may ever be removed or reordered in a release an installation
     * could update *into*: the code doing the constructing during that
     * update is the code of the version being replaced.
     */
    public function __construct(
        public readonly array $executedStatements,
        public readonly array $warnings,
        public readonly bool $complete = true,
        public readonly float $progressFraction = 1.0,
        public readonly bool $backupCreated = false,
        public readonly bool $converged = true
    ) {
    }

    public function hasChanges(): bool
    {
        return count($this->executedStatements) > 0;
    }
}
