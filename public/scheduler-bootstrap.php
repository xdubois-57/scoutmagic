<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The scheduler's SHARED composition root, required — and called
 * identically — by both entry points: public/index.php (the poor man's
 * cron at the tail of every web request) and public/cron.php (a real
 * crontab). Everything the scheduler needs beyond the base services is
 * assembled here and nowhere else:
 *
 *   - the TaskContext handlers run with, including the optional
 *     cross-module CAPABILITIES (Core\Scheduler\TaskCapabilities) a
 *     handler reaches through TaskContext::getOptional() — the §7.5
 *     pattern applied to the scheduled path;
 *   - the registration of every core handler (CoreTaskHandlers);
 *   - the registration of the one module handler that cannot be
 *     auto-resolved from its manifest (inbound mail's sync needs the
 *     consumer registry, which spans three modules — registered as a LAZY
 *     factory so no web request pays for that graph);
 *   - the seeding of the recurring module tasks that used to be seeded on
 *     the web path only.
 *
 * WHY ONE FILE. The two entry points used to hand-maintain two copies of
 * this wiring, and the copies drifted — first create_backup missing from
 * cron.php (§8.17), then NotificationService built without role
 * resolution (§8.17 again), then rental's reminder handler built WITHOUT
 * Finance under a real crontab while the web path built it WITH: the same
 * task, two behaviours, decided by which trigger fired it. A single
 * shared function makes that whole class of drift impossible; a test
 * (Tests\Core\Scheduler\SchedulerBootstrapParityTest) pins that both
 * entry points delegate here and register nothing of their own.
 *
 * WHY IN public/. This file is a composition root, and composition roots
 * are the one place allowed to name modules' internal classes — core/
 * must never (ARCHITECTURE.md §7.5), which is exactly why this cannot be
 * a Core\Scheduler class. It sits beside the two entry points that share
 * it, inside phpstan's analysed paths like them.
 */

declare(strict_types=1);

// Never reachable over HTTP on its own: public/.htaccess only rewrites
// paths that do NOT exist on disk, so GET /scheduler-bootstrap.php would
// otherwise execute this file directly. Both entry points define the
// constant before requiring it; nothing else does. Same in-code-guard
// authority as public/cron.php's PHP_SAPI check (.htaccess does not apply
// on nginx). Defining a function and running nothing, a direct hit would
// be harmless anyway — the guard makes that explicit rather than
// incidental.
if (!defined('SCOUTMAGIC_ENTRYPOINT')) {
    http_response_code(404);
    exit;
}

/**
 * Wire the scheduler: capabilities, context, core handlers, the one
 * hand-registered module handler, and the recurring-task seeds.
 *
 * Returns the TaskContext it built and set on the runner, so an entry
 * point that needs it for something else keeps a handle.
 */
function scoutmagic_bootstrap_scheduler(
    \Core\Scheduler\SchedulerRunner $runner,
    \Core\Scheduler\SchedulerService $schedulerService,
    \Core\Module\ModuleManager $moduleManager,
    \Core\Database\Connection $connection,
    \Core\Security\EncryptionService $encryptionService,
    \Core\Mail\MailService $mailService,
    \Core\Journal\JournalService $journalService,
    \Core\Config\SettingService $settingService,
    \Core\Security\UserAccountRepository $userAccountRepo,
    string $storagePath,
    ?\Core\Notification\NotificationService $notificationService
): \Core\Scheduler\TaskContext {
    $pdo = $connection->getPdo();

    // ── Capabilities: what a handler may getOptional() ──────────────────
    //
    // One registration per published Api\ interface, each guarded by its
    // providing module's live enabled state (TaskCapabilities re-checks on
    // every resolve). Factories are hand-written constructions, exactly
    // like the composition roots' own wiring — deferred, never auto-wired.
    $capabilities = new \Core\Scheduler\TaskCapabilities($moduleManager);

    $capabilities->register(
        \Modules\LlmConnector\Api\LlmConnectorInterface::class,
        'llm_connector',
        static fn (): object => new \Modules\LlmConnector\Service\LlmConnectorService(
            new \Modules\LlmConnector\Repository\ProviderRepository($pdo, $encryptionService),
            new \Modules\LlmConnector\Repository\ProviderModelRepository($pdo),
            $journalService
        )
    );

    $capabilities->register(
        \Modules\InboundMail\Api\InboundMailInterface::class,
        'inbound_mail',
        static fn (): object => new \Modules\InboundMail\Service\InboundMailService(
            new \Modules\InboundMail\Repository\InboundMessageRepository($pdo, $encryptionService),
            new \Modules\InboundMail\Repository\InboundMailboxRepository($pdo, $encryptionService),
            new \Core\File\FileRepository($pdo)
        )
    );

    // Finance's four published interfaces, as one shared construction —
    // the same graph public/cron.php used to assemble for the rental purge
    // alone, now available to any handler. AccountVisibility runs as the
    // system caller: nothing on a scheduled path has a session to narrow
    // the account partition against (same stance as the audit entries a
    // task writes: the application acting on its own).
    $financeReceivables = static function () use ($pdo, $encryptionService): \Modules\Finance\Service\ExpectedReceivableService {
        $receivableRepository = new \Modules\Finance\Repository\ExpectedReceivableRepository($pdo, $encryptionService);

        return new \Modules\Finance\Service\ExpectedReceivableService(
            $receivableRepository,
            new \Modules\Finance\Service\ReceivableAllocationService(
                $receivableRepository,
                new \Modules\Finance\Repository\ReceivableAllocationRepository($pdo),
                new \Modules\Finance\Repository\TransactionRepository($pdo, $encryptionService),
                new \Modules\Finance\Repository\AccountRepository($pdo, $encryptionService),
                new \Modules\Finance\Service\AccountVisibility(
                    \Modules\Finance\Service\TreasurerScope::systemCaller()
                )
            )
        );
    };
    $capabilities->register(\Modules\Finance\Api\ExpectedReceivableInterface::class, 'finance', $financeReceivables);
    $capabilities->register(
        \Modules\Finance\Api\StructuredCommunicationInterface::class,
        'finance',
        static fn (): object => new \Modules\Finance\Service\StructuredCommunicationService(
            new \Modules\Finance\Repository\ExpectedReceivableRepository($pdo, $encryptionService)
        )
    );
    $capabilities->register(
        \Modules\Finance\Api\SepaQrCodeInterface::class,
        'finance',
        static fn (): object => new \Modules\Finance\Service\SepaQrCodeService()
    );
    $capabilities->register(
        \Modules\Finance\Api\FinanceAccountInterface::class,
        'finance',
        static fn (): object => new \Modules\Finance\Service\FinanceAccountService(
            new \Modules\Finance\Repository\AccountRepository($pdo, $encryptionService)
        )
    );

    // ── Context and core handlers ───────────────────────────────────────
    $context = new \Core\Scheduler\TaskContext(
        $connection,
        $encryptionService,
        $mailService,
        $journalService,
        $settingService,
        $userAccountRepo,
        $storagePath,
        $notificationService,
        $capabilities
    );
    $runner->setModuleManager($moduleManager);
    $runner->setTaskContext($context);
    \Core\Scheduler\CoreTaskHandlers::registerAll($runner);

    $enabledModuleIds = $moduleManager->getEnabledModuleIds();

    // ── Inbound mail's sync: the one handler a manifest cannot resolve ──
    //
    // It needs the message-consumer registry, which only a composition
    // root can build: the consumers belong to OTHER modules (rental,
    // camps), and inbound_mail deliberately knows nothing about any of
    // them (§8.58). Registered as a lazy factory so the three-module
    // dependency graph below is only ever assembled when a sync task is
    // actually due — never on an ordinary page view.
    if (in_array('inbound_mail', $enabledModuleIds, true)) {
        $runner->registerHandlerFactory(
            'inbound_mail',
            \Modules\InboundMail\Task\SyncMailboxesHandler::TASK_KEY,
            static function (\Core\Scheduler\TaskContext $context) use ($pdo, $encryptionService, $settingService, $journalService, $storagePath, $enabledModuleIds): \Core\Scheduler\TaskHandlerInterface {
                $registry = new \Modules\InboundMail\Service\MessageConsumerRegistry();
                $inboundMail = $context->getOptional(\Modules\InboundMail\Api\InboundMailInterface::class);
                $auditService = new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($pdo, $encryptionService));
                $fileRepository = new \Core\File\FileRepository($pdo);

                if ($inboundMail !== null && in_array('rental', $enabledModuleIds, true)) {
                    $rentalBookingRepository = new \Modules\Rental\Repository\RentalBookingRepository($pdo, $encryptionService);
                    // No ActorAccountResolver on the scheduled path:
                    // every change is the application acting on its own,
                    // which Core\Audit renders as an automatic entry.
                    $rentalBookingAudit = new \Modules\Rental\Audit\BookingAudit($auditService);
                    $registry->register(new \Modules\Rental\Mail\RentalMessageConsumer(
                        $rentalBookingRepository,
                        $inboundMail,
                        new \Modules\Rental\Service\RentalDocumentService(
                            new \Modules\Rental\Repository\RentalDocumentRepository($pdo),
                            $rentalBookingRepository,
                            $rentalBookingAudit,
                            new \Core\View\EditableContentService(new \Core\View\EditableContentRepository($pdo)),
                            $fileRepository,
                            new \Core\File\AttachedFileRemover($fileRepository, $storagePath),
                            new \Core\Pdf\DocumentPdfService(),
                            new \Core\Security\HtmlSanitizer(),
                            $settingService,
                            $journalService,
                            $storagePath
                        ),
                        (new \Modules\Rental\Mail\MailboxSelection($settingService, $inboundMail))->selectedIds()
                    ));
                }

                // The camps consumer is registered LAST, and that ordering
                // is load-bearing: MessageConsumerRegistry is
                // first-claim-wins in registration order, and a dedicated
                // camps mailbox claims EVERYTHING it is offered.
                // Registered earlier, it would swallow the mail another
                // module was waiting for.
                if ($inboundMail !== null && in_array('camps', $enabledModuleIds, true)) {
                    $llm = $context->getOptional(\Modules\LlmConnector\Api\LlmConnectorInterface::class);
                    $campsPlaceRepo = new \Modules\Camps\Repository\PlaceRepository($pdo);
                    $campsCampRepo = new \Modules\Camps\Repository\CampRepository($pdo, $encryptionService);
                    $campsMessageReader = new \Modules\Camps\Mail\MessageReader();

                    $registry->register(new \Modules\Camps\Mail\CampsMessageConsumer(
                        $campsCampRepo,
                        $pdo,
                        $encryptionService,
                        $settingService,
                        $inboundMail,
                        new \Modules\Camps\Service\DocumentService(
                            new \Modules\Camps\Repository\DocumentRepository($pdo),
                            new \Core\File\AttachedFileRemover($fileRepository, $storagePath),
                            new \Core\File\UploadHandler($fileRepository, $storagePath),
                            $auditService
                        ),
                        new \Modules\Camps\Mail\MailFieldCompletionService(
                            $campsCampRepo,
                            new \Modules\Camps\Repository\FieldProposalRepository($pdo, $encryptionService),
                            $auditService,
                            $campsMessageReader
                        ),
                        new \Modules\Camps\Mail\StayFromMailService(
                            $campsCampRepo,
                            new \Modules\Camps\Service\CampService($campsCampRepo, $auditService, $campsPlaceRepo),
                            new \Modules\Camps\Service\PlaceService($campsPlaceRepo, $auditService),
                            new \Modules\Camps\Service\DuplicatePlaceDetector($campsPlaceRepo, $llm),
                            $campsMessageReader,
                            $settingService,
                            $inboundMail,
                            $llm
                        )
                    ));
                }

                return new \Modules\InboundMail\Task\SyncMailboxesHandler($registry);
            }
        );
        \Modules\InboundMail\Task\SyncMailboxesHandler::bootstrap($schedulerService);
    }

    // ── Recurring-task seeds that used to live on the web path only ─────
    //
    // rearm() is idempotent (one indexed lookup when the occurrence is
    // already queued), so seeding from both entry points costs nothing and
    // means a site reached only by its crontab still runs them. The rental
    // handlers themselves are auto-resolved from the manifest: their
    // self-built services read the finance and inbound-mail capabilities
    // off the context, so the web path and the crontab now produce the
    // SAME reminders — including the money ones a real crontab used to
    // silently omit.
    if (in_array('rental', $enabledModuleIds, true)) {
        \Modules\Rental\Task\SendRentalRemindersHandler::bootstrap($schedulerService);
        \Modules\Rental\Task\PurgeRentalBookingsHandler::bootstrap($schedulerService);
    }

    return $context;
}
