<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Rental\Task;

use Core\Import\MemberYearRepository;
use Core\View\TwigFactory;
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
     * An injected service is for TESTS; production always auto-resolves
     * this handler from the manifest (`new` with no arguments) and builds
     * the full service below from the TaskContext.
     *
     * It used to be the other way around: the money reminders need
     * Finance's public API, only a composition root knew whether that
     * module was enabled, so the handler was hand-registered in both
     * entry points — and the two constructions drifted, `public/cron.php`
     * assembling it WITHOUT Finance while the web path assembled it WITH.
     * The same task said nothing about money under a real crontab and
     * everything about it under the poor man's cron. Finance now arrives
     * through `TaskContext::getOptional()` (ARCHITECTURE.md §7.5 on the
     * scheduled path), so there is exactly ONE construction and the
     * drift cannot recur.
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
        SchedulerService::forPdo($pdo)
            ->scheduleAfter('rental', self::TASK_KEY, self::INTERVAL_SECONDS, [], self::REFERENCE);
    }

    private function selfBuiltService(TaskContext $context): RentalReminderService
    {
        $pdo = $context->connection->getPdo();
        $fileRepository = new \Core\File\FileRepository($pdo);
        $bookingRepository = new RentalBookingRepository($pdo, $context->encryption);
        // No ActorAccountResolver on the scheduled path: every change is
        // the application acting on its own, which Core\Audit renders as
        // an automatic entry — the honest reading and the one a manager
        // needs.
        $bookingAudit = new \Modules\Rental\Audit\BookingAudit(
            new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($pdo, $context->encryption))
        );

        // The money side arrives as capabilities (TaskContext::
        // getOptional(), ARCHITECTURE.md §7.5 on the scheduled path):
        // null with Finance disabled, and RentalPaymentService then says
        // nothing about money rather than guessing what was received.
        $paymentService = new \Modules\Rental\Service\RentalPaymentService(
            new \Modules\Rental\Repository\RentalPaymentRepository($pdo, $context->encryption),
            $bookingAudit,
            $context->journal,
            $context->getOptional(\Modules\Finance\Api\ExpectedReceivableInterface::class),
            $context->getOptional(\Modules\Finance\Api\StructuredCommunicationInterface::class),
            $context->getOptional(\Modules\Finance\Api\SepaQrCodeInterface::class),
            $context->getOptional(\Modules\Finance\Api\FinanceAccountInterface::class)
        );

        $documentService = new \Modules\Rental\Service\RentalDocumentService(
            new \Modules\Rental\Repository\RentalDocumentRepository($pdo),
            $bookingRepository,
            $bookingAudit,
            new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($pdo)),
            $fileRepository,
            new \Core\File\AttachedFileRemover($fileRepository, $context->storagePath),
            new \Core\Pdf\DocumentPdfService(),
            new \Core\Security\HtmlSanitizer(),
            $context->settings,
            $context->journal,
            $context->storagePath
        );

        return new RentalReminderService(
            $bookingRepository,
            new RentalAssetRepository($pdo, $context->encryption),
            new RentalAssetManagerRepository($pdo),
            new RentalComplianceService(
                new RentalComplianceRepository($pdo),
                $context->settings,
                $context->journal,
                $fileRepository
            ),
            new RentalReminderRepository($pdo),
            new ReminderPlanner(),
            new MemberYearRepository($pdo),
            $context->userAccounts,
            $context->journal,
            $context->notifications,
            $paymentService,
            $documentService,
            new \Modules\Rental\Service\RentalStayService(
                new \Modules\Rental\Repository\RentalStayRepository($pdo, $context->encryption),
                $bookingAudit,
                new \Modules\Rental\Service\RentalPricingService(
                    new \Modules\Rental\Repository\RentalPricingRepository($pdo),
                    new \Modules\Rental\Pricing\RentalPricingEngine(),
                    $context->journal
                ),
                new \Modules\Rental\Stay\SettlementCalculator(),
                $context->journal,
                $paymentService
            ),
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
        $scheduler->rearm(
            'rental',
            self::TASK_KEY,
            self::REFERENCE,
            new \DateTimeImmutable('+' . self::INTERVAL_SECONDS . ' seconds')
        );
    }
}
