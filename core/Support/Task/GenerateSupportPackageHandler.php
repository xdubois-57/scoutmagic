<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Task;

use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Support\SupportPackageFactory;

/**
 * Background generation of the diagnostic support package
 * (`core`/`generate_support_package`) — ARCHITECTURE.md §8.44.
 *
 * A background task rather than a synchronous request for the same reason
 * as `create_backup` (§8.15): the system collectors shell out, walk the
 * filesystem and read logs, and none of that belongs inside a page load.
 * The Support page polls `GET /api/support/package-status/{id}` for the
 * outcome, exactly like `backup-status` and `update-status`.
 */
class GenerateSupportPackageHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'generate_support_package';

    public function handle(array $payload, TaskContext $context): void
    {
        $requestedBy = $payload['requested_by_user_account_id'] ?? null;
        $requestedBy = is_int($requestedBy) ? $requestedBy : null;

        $fileId = SupportPackageFactory::service($context)->generate($requestedBy);

        $context->journal->log(
            'core',
            'support_package_generated',
            'info',
            'Paquet de support généré',
            ['file_id' => $fileId],
            $requestedBy
        );

        if ($requestedBy !== null) {
            $context->notifications?->dispatch(
                'core.support_package_ready',
                [['userAccountId' => $requestedBy, 'memberId' => null]],
                [
                    'title' => 'Paquet de support prêt',
                    'body' => 'Votre archive de diagnostic est prête à être téléchargée.',
                    'url' => '/config/support',
                ]
            );
        }
    }
}
