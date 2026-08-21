<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Task;

use Core\Import\MemberYearRepository;
use Core\View\TwigFactory;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\Rental\Reminder\ReminderPlanner;
use Modules\Rental\Repository\RentalAssetManagerRepository;
use Modules\Rental\Repository\RentalAssetRepository;
use Modules\Rental\Repository\RentalBookingRepository;
use Modules\Rental\Repository\RentalComplianceRepository;
use Modules\Rental\Repository\RentalReminderRepository;
use Modules\Rental\Service\RentalComplianceService;
use Modules\Rental\Service\RentalReminderService;

/**
 * The daily reminder pass (§6.29).
 *
 * **Daily, not hourly.** Every reminder here is about a date — a due date
 * passed, a stay approaching, a certificate expiring — and none of them
 * becomes true at a particular hour. Running more often would only mean
 * asking the same questions more often, and on shared hosting the run is
 * not free.
 *
 * Self-reschedules at the end of every run, the same pattern as
 * `ExpireRentalHoldsHandler` — `Core\Scheduler` has no recurring-task
 * concept.
 *
 * **A missed day loses nothing.** Nothing here fires "on" a date: every
 * rule is "is this true today", and the sent-reminders table is what stops
 * a repeat. So a shared host whose cron ran six hours late, or not at all
 * yesterday, sends the reminder today rather than never — which is the
 * behaviour the cron warning on the configuration page exists to set
 * expectations about.
 */
class SendRentalRemindersHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'send_rental_reminders';
    private const REFERENCE = 'daily';
    private const INTERVAL_SECONDS = 86400;

    /**
     * The fully-wired service, when a composition root supplied one.
     *
     * It has to be injected rather than built here for one reason: the
     * money reminders need Finance's public API, and only a composition
     * root knows whether that module is enabled. So this handler is
     * registered explicitly in **both** `public/index.php` and
     * `public/cron.php`, like the inbound-mail one — and a test pins both
     * call sites, because a handler registered in only one entry point
     * fails unconditionally under the other (§8.17/§8.20).
     *
     * Auto-resolved from the manifest instead, it falls back to the
     * self-built service below: everything still fires except the money
     * reminders, which stay silent rather than guessing (see
     * RentalReminderService::paymentStatus()).
     */
    public function __construct(private ?RentalReminderService $service = null)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        ($this->service ?? $this->selfBuiltService($context))->run(new \DateTimeImmutable('today'));

        // Unconditionally, and NOT through bootstrap(): SchedulerRunner
        // marks a task done only after handle() returns, so this very task
        // is still `pending` right now and bootstrap()'s guard would find
        // it, skip, and end the chain after a single run.
        $scheduler = new SchedulerService(new SchedulerRepository($pdo));
        $scheduler->scheduleAfter('rental', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
    }

    private function selfBuiltService(TaskContext $context): RentalReminderService
    {
        $pdo = $context->connection->getPdo();

        return new RentalReminderService(
            new RentalBookingRepository($pdo, $context->encryption),
            new RentalAssetRepository($pdo, $context->encryption),
            new RentalAssetManagerRepository($pdo),
            new RentalComplianceService(
                new RentalComplianceRepository($pdo),
                $context->settings,
                $context->journal
            ),
            new RentalReminderRepository($pdo),
            new ReminderPlanner(),
            new MemberYearRepository($pdo),
            $context->userAccounts,
            $context->journal,
            $context->notifications,
            null,
            null,
            null,
            // The renter's practical-info email needs a renderer, and
            // TaskContext carries no Twig — the same construction every
            // other module's mailing task does (Calendar\Task\
            // MultidayEventReminderHandler).
            new \Modules\Rental\Service\RentalBookingMailService(
                $context->mailService,
                TwigFactory::create(
                    dirname(__DIR__, 4) . '/core/View/templates',
                    false,
                    ['rental' => dirname(__DIR__, 4) . '/modules/rental/views']
                ),
                $context->settings,
                $context->journal
            )
        );
    }

    /**
     * Queue the very first run. Idempotent, so calling it on every request
     * costs one indexed lookup and re-arms the chain by itself if a run
     * ever failed before scheduling its successor.
     */
    public static function bootstrap(SchedulerService $scheduler): void
    {
        if ($scheduler->find('rental', self::TASK_KEY, self::REFERENCE) !== null) {
            return;
        }

        $scheduler->scheduleAfter('rental', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
    }
}
