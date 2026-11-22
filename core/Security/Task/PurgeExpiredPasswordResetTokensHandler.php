<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Security\Task;

use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Core\Security\PasswordResetRepository;

/**
 * Deletes the password-reset tokens that can no longer serve anyone (issue
 * #1257): expired or used, and older than the hour the per-address rate
 * limit counts (PasswordResetRepository::deleteExpired()). The purge
 * existed and nothing called it — the first audit's SEC-033 — so every
 * token ever requested stayed in the table. Magic links got theirs in
 * #1099 (Task\PurgeExpiredMagicLinksHandler); this is the same task for the
 * other single-use credential.
 *
 * Self-reschedules daily, like the other purges; seeded by
 * public/index.php.
 */
class PurgeExpiredPasswordResetTokensHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_expired_password_reset_tokens';
    public const REFERENCE = 'daily';
    private const INTERVAL_SECONDS = 86400;

    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        (new PasswordResetRepository($pdo))->deleteExpired();

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
