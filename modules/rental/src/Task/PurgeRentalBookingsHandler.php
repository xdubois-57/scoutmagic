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
 * **`inbound_mail` and Finance arrive as capabilities** —
 * `TaskContext::getOptional()` (ARCHITECTURE.md §7.5 on the scheduled
 * path) — so the purge takes a booking's emails and its receivables with
 * it on BOTH entry points, from one single construction. It used to be
 * hand-registered with a wired service in each entry point instead, and
 * the two constructions drifted (`public/cron.php`'s could never reach
 * `inbound_mail`, leaving a purged booking's mail behind under a real
 * crontab). Auto-resolved from the manifest now; the injected-service
 * constructor parameter remains for tests.
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

        // No ActorAccountResolver on the scheduled path: every change is
        // the application acting on its own, rendered by Core\Audit as an
        // automatic entry.
        $bookingAudit = new \Modules\Rental\Audit\BookingAudit(
            new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($pdo, $context->encryption))
        );

        // A purged booking's receivables must go with it: Finance's tables
        // are outside every cascade this module's schema declares. Null —
        // Finance disabled — means there was nothing to forget.
        $payments = new \Modules\Rental\Service\RentalPaymentService(
            new \Modules\Rental\Repository\RentalPaymentRepository($pdo, $context->encryption),
            $bookingAudit,
            $context->journal,
            $context->getOptional(\Modules\Finance\Api\ExpectedReceivableInterface::class),
            $context->getOptional(\Modules\Finance\Api\StructuredCommunicationInterface::class),
            $context->getOptional(\Modules\Finance\Api\SepaQrCodeInterface::class),
            $context->getOptional(\Modules\Finance\Api\FinanceAccountInterface::class)
        );

        return new RentalRetentionService(
            new RentalBookingRepository($pdo, $context->encryption),
            new RentalDocumentRepository($pdo),
            new RentalAggregateRepository($pdo),
            new RentalReminderRepository($pdo),
            $context->settings,
            $context->journal,
            $pdo,
            new FileRepository($pdo),
            // And its correspondence: null with inbound_mail disabled,
            // where no mail was ever attached.
            $context->getOptional(\Modules\InboundMail\Api\InboundMailInterface::class),
            $context->storagePath,
            $payments,
            $bookingAudit
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
