<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Task;

use Core\Audit\AuditRepository;
use Core\Audit\AuditService;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Rental\Audit\BookingAudit;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalChangeRequestRepository;
use Modules\Rental\Service\RentalBookingService;

/**
 * Expires temporary holds whose deadline has passed (§6.14).
 *
 * **One task for both origins**, because there is only one hold mechanism.
 * What differs is the meaning, and the service decides it: an `automatic`
 * hold lapsing releases the dates and leaves the request waiting (nobody
 * promised anything), while a `manager` option lapsing makes the booking
 * expired.
 *
 * Self-reschedules hourly rather than being a first-class recurring task —
 * the same pattern as `Core\Notification\Task\PurgeNotificationsHandler` and
 * `Modules\Registration\Task\PurgeRegistrationRequestsHandler`, because
 * `Core\Scheduler` has no recurring-task concept. Bootstrapped once from
 * **both** `public/index.php` and `public/cron.php`: registering a handler in
 * only one of the two has caused real production bugs (ARCHITECTURE.md
 * §8.17/§8.20).
 *
 * **Availability never waits for this task.** A lapsed hold is one whose
 * deadline has passed, and the calculator reads the deadline directly — so an
 * asset is free the moment the hold expires, not the next time cron runs.
 * This task tidies the stored state; it is not what makes the dates
 * available. That matters on shared hosting, where "hourly" can mean several
 * hours late.
 */
class ExpireRentalHoldsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'expire_rental_holds';
    private const REFERENCE = 'hourly';
    private const INTERVAL_SECONDS = 3600;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $service = new RentalBookingService(
            new RentalBookingRepository($pdo, $context->encryption),
            new JournalService(new JournalRepository($pdo)),
            // A booking this sweep expires has nothing left to decide, so
            // its pending change requests are refused with it — the same
            // rule RentalOperationsService applies when a manager closes a
            // booking by hand, reached here by the other road. Without
            // these two the renter's tracking page kept offering
            // « Accepter » on a proposal for a booking that no longer
            // existed.
            new RentalChangeRequestRepository($pdo, $context->encryption),
            // No ActorAccountResolver: nothing here has an actor. The
            // deadline decided, and the timeline says so (§8.66).
            new BookingAudit(new AuditService(new AuditRepository($pdo, $context->encryption)))
        );

        $service->expireLapsedHolds(new \DateTimeImmutable());

        // Unconditionally, and NOT through bootstrap(): SchedulerRunner
        // marks a task done only after handle() returns, so this very task
        // is still `pending` right now and bootstrap()'s guard would find
        // it, skip, and end the chain after a single run.
        SchedulerService::forPdo($pdo)
            ->rearmAfter('rental', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }

    /**
     * How a composition root queues the very first run.
     *
     * Idempotent, so calling it on every request costs one indexed lookup
     * and never queues a duplicate — and it re-arms the chain by itself if
     * a run ever fails before it could schedule its successor.
     */
    /**
     * Called from a composition root, so on every request — see
     * SchedulerService::seed() for why that must not be rearm().
     */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        $scheduler->seedAfter('rental', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
