<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\View\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\View\RgpdGenerationRunner;

/**
 * Background generation of the RGPD document (`core`/`generate_rgpd_content`).
 *
 * A background task rather than a synchronous request because the job is
 * minutes long — see `Core\View\RgpdGenerationRunner` for the
 * measurement, and for why raising the provider timeout only moves the
 * wall. The configuration page polls `GET /config/rgpd/generate/status`
 * for the outcome, exactly like `backup-status`, `update-status` and
 * `support/package-status`.
 *
 * The runner is handed in by the composition root
 * (`SchedulerRunner::registerHandlerFactory()`), not assembled here: it
 * needs the same `RgpdContentService` the page uses, sub-processor hooks
 * attached and all, and a second one built from a PDO would answer a
 * different question about which modules are active.
 */
class GenerateRgpdContentHandler implements TaskHandlerInterface
{
    public const TASK_KEY = RgpdGenerationRunner::TASK_KEY;

    public function __construct(private RgpdGenerationRunner $runner)
    {
    }

    public function handle(array $payload, TaskContext $context): void
    {
        $requestedBy = $payload['requested_by_user_account_id'] ?? null;

        $this->runner->runInBackground(
            is_string($payload['prompt'] ?? null) ? $payload['prompt'] : '',
            is_int($requestedBy) ? $requestedBy : null
        );
    }
}
