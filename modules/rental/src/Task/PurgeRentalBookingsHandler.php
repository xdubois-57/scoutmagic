<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Task;

use Core\File\FileRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Rental\Repository\RentalAggregateRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalDocumentRepository;
use Modules\Rental\Repository\RentalReminderRepository;
use Modules\Rental\Service\RentalRetentionService;

/**
 * The retention purge (§6.35).
 *
 * Self-reschedules daily rather than being a first-class recurring task —
 * the same pattern as `Core\Notification\Task\PurgeNotificationsHandler`,
 * which §6.35 names as the model.
 *
 * **`inbound_mail` and Finance are null here.** Under a real crontab the
 * composition root that knows whether those modules are enabled has not
 * run, so the handler cannot reach them — which would leave a purged
 * booking's emails behind, and Finance still expecting money for a stay
 * that no longer exists. It is therefore registered explicitly with a
 * wired service in **both** entry points, like the reminder task; this
 * self-built fallback purges everything else, and a test pins both call
 * sites.
 */
class PurgeRentalBookingsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_rental_bookings';
    private const REFERENCE = 'daily';
    private const INTERVAL_SECONDS = 86400;

    public function __construct(private ?RentalRetentionService $service = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        ($this->service ?? $this->selfBuiltService($context))->purge(new \DateTimeImmutable('today'));

        // Unconditionally, and NOT through bootstrap(): SchedulerRunner
        // marks a task done only after handle() returns, so this very task
        // is still `pending` right now and bootstrap()'s guard would find
        // it, skip, and end the chain after a single run.
        SchedulerService::forPdo($pdo)
            ->scheduleAfter('rental', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
    }

    private function selfBuiltService(TaskContext $context): RentalRetentionService
    {
        $pdo = $context->connection->getPdo();

        return new RentalRetentionService(
            new RentalBookingRepository($pdo, $context->encryption),
            new RentalDocumentRepository($pdo),
            new RentalAggregateRepository($pdo),
            new RentalReminderRepository($pdo),
            $context->settings,
            $context->journal,
            $pdo,
            new FileRepository($pdo),
            null,
            $context->storagePath
        );
    }

    public static function bootstrap(SchedulerService $scheduler): void
    {
        $scheduler->rearm(
            'rental',
            self::TASK_KEY,
            self::REFERENCE,
            new \DateTimeImmutable('+' . self::INTERVAL_SECONDS . ' seconds')
        );
    }
}
