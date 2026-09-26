<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Task;

use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Social\Card\CardRenderer;
use Modules\Social\Card\CardService;
use Modules\Social\Repository\CardRepository;

/**
 * Deletes the cards whose hour is over, files and rows.
 *
 * An expired card is already unreachable — {@see CardService::open()}
 * checks the expiry on every request — so this is housekeeping, not the
 * guarantee: it keeps `storage/social/cards/` from holding composed images
 * nobody can fetch any more. Hence daily rather than hourly.
 *
 * Re-arms itself daily through rearm(), never schedule()
 * (Tests\Architecture\RecurringTasksRearmTest).
 */
class PurgeCardsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_cards';
    public const REFERENCE = 'daily';

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $service = new CardService(
            new CardRepository($pdo),
            new CardRenderer(),
            $context->settings,
            $context->journal,
            $context->storagePath . '/' . CardService::DIRECTORY
        );

        $deleted = $service->purgeExpired(new \DateTimeImmutable());
        if ($deleted > 0) {
            $context->journal->log(
                'social',
                'cards_purged',
                'info',
                sprintf('%d image(s) de publication expirée(s) effacée(s).', $deleted),
                ['count' => $deleted]
            );
        }

        SchedulerService::forPdo($pdo)->rearm(
            'social',
            self::TASK_KEY,
            self::REFERENCE,
            new \DateTimeImmutable('tomorrow 04:35')
        );
    }
}
