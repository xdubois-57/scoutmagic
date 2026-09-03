<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 *
 * The scheduler's SHARED composition root, required — and called
 * identically — by both entry points: public/cron.php, which is the only
 * thing that ever RUNS a pass, and public/index.php, which no longer runs
 * one at all but still needs the same wiring to arm the recurring tasks
 * and to schedule work a request creates. Everything the scheduler needs
 * beyond the base services is assembled here and nowhere else:
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
        \Modules\Calendar\Api\CalendarEventLookupInterface::class,
        'calendar',
        static fn (): object => new \Modules\Calendar\Service\CalendarService(
            new \Modules\Calendar\Repository\CalendarRepository($pdo, $encryptionService),
            new \Modules\Calendar\Repository\CalendarEventRepository($pdo),
            new \Core\Member\SectionService(
                \Core\Database\Connection::withPdo($pdo),
                $encryptionService,
                new \Core\Badge\MemberBadgeRepository($pdo)
            ),
            new \Modules\Calendar\Repository\CalendarUnitFeedTokenRepository($pdo, $encryptionService)
            // No retro link lookup on the scheduled path: nothing a task
            // reads through this lookup renders a retro link.
        )
    );

    $capabilities->register(
        \Modules\Retro\Api\RetroBoardCreationInterface::class,
        'retro',
        static fn (): object => new \Modules\Retro\Service\AutoBoardCreationService(
            new \Modules\Retro\Repository\BoardRepository($pdo, $encryptionService),
            $settingService,
            $schedulerService,
            $journalService
        )
    );

    // The per-module usage aggregate the daily report carries
    // (ARCHITECTURE.md §8.93). Registered here for the same reason as
    // every other capability: Core\Statistics\StatisticsServiceFactory
    // rebuilds the payload builder from a TaskContext, and reaching into
    // the module's Service\ classes from core would bypass both the Api\
    // contract and the enablement check this registry re-reads on every
    // resolve.
    $capabilities->register(
        \Modules\UsageStats\Api\ModuleUsageInterface::class,
        'usage_stats',
        static fn (): object => new \Modules\UsageStats\Service\ModuleUsageService(
            new \Modules\UsageStats\Repository\PageViewRepository($pdo)
        )
    );

    $capabilities->register(
        \Modules\InboundMail\Api\InboundMailInterface::class,
        'inbound_mail',
        static fn (): object => new \Modules\InboundMail\Service\InboundMailService(
            new \Modules\InboundMail\Repository\InboundMessageRepository($pdo, $encryptionService),
            new \Modules\InboundMail\Repository\InboundMailboxRepository($pdo, $encryptionService),
            new \Core\File\FileRepository($pdo),
            null,
            null,
            null,
            // Signed reply addresses (§8.58) for the mail a task sends —
            // the rental reminders, for one. No scope service here: the
            // box declared dedicated to the consumer, else the first
            // enabled box whose account is an address.
            new \Modules\InboundMail\Service\ReplyAddressService(
                new \Modules\InboundMail\Repository\InboundMailboxRepository($pdo, $encryptionService),
                $encryptionService,
                null,
                $settingService
            )
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

    // ── The RGPD document: the one CORE handler a bare `new` cannot make ─
    //
    // Every other core handler is instantiated by CoreTaskHandlers with no
    // arguments. This one needs a whole Core\View\RgpdContentService, and
    // that service is only truthful when the two SubProcessorProvider hooks
    // (§7.4) are attached to it: they are what let the generated document
    // state the AI provider and the gallery's S3 storage this installation
    // actually engages. A service built without them would produce a legal
    // document that under-declares its own sub-processors — silently, and
    // only on the trigger that generates it.
    //
    // A lazy factory, so no web request pays for the graph, and the two
    // hooks guarded by their modules' live state exactly as the
    // capabilities above are.
    $runner->registerHandlerFactory(
        'core',
        \Core\View\Task\GenerateRgpdContentHandler::TASK_KEY,
        static function (\Core\Scheduler\TaskContext $context) use (
            $pdo,
            $encryptionService,
            $settingService,
            $schedulerService,
            $journalService,
            $moduleManager,
            $enabledModuleIds
        ): \Core\Scheduler\TaskHandlerInterface {
            return new \Core\View\Task\GenerateRgpdContentHandler(
                new \Core\View\RgpdGenerationRunner(
                    static function () use (
                        $pdo,
                        $encryptionService,
                        $settingService,
                        $journalService,
                        $moduleManager,
                        $enabledModuleIds
                    ): \Core\View\RgpdContentService {
                        $providerRepository = new \Modules\LlmConnector\Repository\ProviderRepository($pdo, $encryptionService);
                        $modelRepository = new \Modules\LlmConnector\Repository\ProviderModelRepository($pdo);
                        $hasLlm = in_array('llm_connector', $enabledModuleIds, true);

                        $service = new \Core\View\RgpdContentService(
                            $moduleManager,
                            $settingService,
                            $hasLlm
                                ? new \Modules\LlmConnector\Service\LlmConnectorService(
                                    $providerRepository,
                                    $modelRepository,
                                    $journalService
                                )
                                : null
                        );

                        if ($hasLlm) {
                            $service->addSubProcessorProvider(
                                new \Modules\LlmConnector\Service\LlmSubProcessorService($providerRepository, $modelRepository)
                            );
                        }

                        if (in_array('gallery', $enabledModuleIds, true)) {
                            $service->addSubProcessorProvider(
                                new \Modules\Gallery\Service\GalleryStorageSubProcessorService(
                                    new \Modules\Gallery\Repository\StorageLocationRepository($pdo, $encryptionService)
                                )
                            );
                        }

                        return $service;
                    },
                    $settingService,
                    $schedulerService,
                    new \Core\View\EditableContentService(
                        new \Core\View\EditableContentRepository($pdo)
                    ),
                    $journalService
                )
            );
        }
    );

    // ── Inbound mail's sync: the one handler a manifest cannot resolve ──
    //
    // It needs the message-consumer registry, which only a composition
    // root can build: the consumers belong to OTHER modules (rental,
    // camps), and inbound_mail deliberately knows nothing about any of
    // them (§8.58). Registered as a lazy factory so the three-module
    // dependency graph below is only ever assembled when a sync task is
    // actually due — never on an ordinary page view.
    if (in_array('inbound_mail', $enabledModuleIds, true)) {
        // The consumer graph, built once per firing and shared by BOTH
        // inbound-mail tasks: the synchronisation's arrival pass and the
        // deferred content pass ask the same consumers, and two copies of
        // this wiring would be two places for it to drift.
        $inboundConsumerRegistry = static function (\Core\Scheduler\TaskContext $context) use ($pdo, $encryptionService, $settingService, $journalService, $storagePath, $enabledModuleIds, $notificationService, $userAccountRepo): \Modules\InboundMail\Service\MessageConsumerRegistry {
                $registry = new \Modules\InboundMail\Service\MessageConsumerRegistry();
                $inboundMail = $context->getOptional(\Modules\InboundMail\Api\InboundMailInterface::class);
                $auditService = new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($pdo, $encryptionService));
                $fileRepository = new \Core\File\FileRepository($pdo);

                // Claims only a message whose subject carries a key this
                // receiver itself issued — the narrowest claim of the lot
                // (roadmap IT-27). Order is immaterial: every consumer
                // the box is open to is asked, and every answer applied.
                if (in_array('support_dashboard', $enabledModuleIds, true)) {
                    $registry->register(new \Modules\SupportDashboard\Mail\SupportMessageConsumer(
                        new \Modules\SupportDashboard\Service\MailProbeService(
                            new \Modules\SupportDashboard\Repository\SupportMailProbeRepository($pdo, $encryptionService),
                            new \Modules\SupportDashboard\Repository\SupportInstallationRepository($pdo),
                            $journalService,
                            $inboundMail
                        )
                    ));
                }

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
                        // The model as a last resort between two bookings
                        // of one renter (§8.59): orders the propositions,
                        // never associates. Null without the connector.
                        modelChoice: new \Modules\Rental\Mail\BookingChoiceByModel(
                            $context->getOptional(\Modules\LlmConnector\Api\LlmConnectorInterface::class)
                        ),
                        // THIS is the path that tells the managers: the
                        // relève runs from the scheduler. Null without a
                        // notification service (cron.php without one).
                        notifier: $notificationService === null ? null : new \Modules\Rental\Mail\RentalMailNotifier(
                            $notificationService,
                            new \Modules\Rental\Repository\RentalAssetManagerRepository($pdo),
                            new \Core\Import\MemberYearRepository($pdo),
                            $userAccountRepo,
                            new \Modules\Rental\Repository\RentalAssetRepository($pdo, $encryptionService)
                        )
                    ));
                }

                // Finance needs no place in any ordering: it proposes, or
                // it files a receipt of its own, and neither costs another
                // consumer anything.
                //
                // **It has no actor here, and it still files.** A
                // synchronisation has nobody making a request, so the
                // resolveActor closure stays null — inventing a session
                // would be this module granting itself an account. What
                // files instead is the unattended route
                // (Api\ExpenseReceiptInterface::storeUnattendedReceipt()),
                // whose authorization is the superadmin having opened this
                // mailbox to this module on the scope screen.
                //
                // This is THE path that matters: the mailbox sync runs from
                // the scheduler, so a consumer built here without a receipt
                // provider and a file reader would decide correctly and
                // file nothing at all.
                if ($inboundMail !== null && in_array('finance', $enabledModuleIds, true)) {
                    $financeScoutYearId = (new \Core\Config\ScoutYearService($pdo))->getCurrentYear()['id'];
                    $financeAccountRepository = new \Modules\Finance\Repository\AccountRepository($pdo, $encryptionService);
                    $financeTreasurerScope = new \Modules\Finance\Service\TreasurerScopeService(
                        \Core\Database\Connection::withPdo($pdo),
                        new \Core\Badge\BadgeRepository($pdo),
                        new \Core\Badge\MemberBadgeRepository($pdo)
                    );
                    $financeFileStorage = new \Core\File\EncryptedFileStorageService(
                        $fileRepository,
                        $encryptionService,
                        $storagePath
                    );
                    $financeStoredFileReader = new \Core\File\StoredFileReader(
                        $fileRepository,
                        $financeFileStorage,
                        $storagePath
                    );
                    $financeReceipts = new \Modules\Finance\Service\ExpenseReceiptService(
                        $financeAccountRepository,
                        $financeTreasurerScope,
                        new \Modules\Finance\Service\ReceiptService(
                            new \Modules\Finance\Repository\AttachmentRepository($pdo, $encryptionService),
                            $financeAccountRepository,
                            new \Modules\Finance\Repository\TransactionAttachmentRepository($pdo),
                            $financeFileStorage,
                            new \Modules\Finance\Repository\TransactionRepository($pdo, $encryptionService),
                            $settingService
                        ),
                        $financeScoutYearId
                    );

                    $registry->register(new \Modules\Finance\Mail\FinanceMessageConsumer(
                        $financeAccountRepository,
                        $financeTreasurerScope,
                        $pdo,
                        $encryptionService,
                        // The current year, resolved here rather than
                        // carried on the context: the scheduled path has no
                        // session and no "effective" year, and the treasurer
                        // rule is about who holds the badge THIS year.
                        $financeScoutYearId,
                        $financeReceipts,
                        // No actor: see above.
                        null,
                        // Core\File\StoredFileReader, never the encrypted
                        // storage directly: the attachment this reads was
                        // written by UploadHandler and is NOT encrypted, so
                        // retrieve() handed plaintext to decrypt(), threw,
                        // and the consumer's catch turned that into « pas
                        // d'octets » — silently, on every message.
                        //
                        // And it says so when it still cannot read: an id
                        // and nothing else, a filename being personal data
                        // (§7.9). THIS is the path that matters — the
                        // relève runs from the scheduler.
                        static function (int $fileId) use ($financeStoredFileReader, $journalService): ?string {
                            $content = $financeStoredFileReader->read($fileId);
                            if ($content === null) {
                                $journalService->log(
                                    'finance',
                                    'inbound_receipt_unreadable',
                                    'warning',
                                    'Pièce jointe illisible : aucun reçu créé',
                                    ['file_id' => $fileId],
                                    null
                                );
                            }

                            return $content;
                        },
                        // « Cette adresse anime-t-elle un seul staff ? ».
                        // $memberEmailRepository is NOT optional here: built
                        // without it, an animateur writing from a confirmed
                        // secondary address staffs no section at all and
                        // their receipt lands in the sorting pile for
                        // nothing.
                        new \Modules\Finance\Mail\SenderStaffAccountResolver(
                            new \Core\Member\SectionStaffAuthorizationService(
                                \Core\Database\Connection::withPdo($pdo),
                                $encryptionService,
                                new \Core\Member\SectionService(
                                    \Core\Database\Connection::withPdo($pdo),
                                    $encryptionService,
                                    new \Core\Badge\MemberBadgeRepository($pdo)
                                ),
                                new \Core\Member\MemberEmailRepository($pdo, $encryptionService)
                            ),
                            $financeAccountRepository,
                            $financeScoutYearId
                        ),
                        new \Modules\Finance\Mail\ForwardedSenderExtractor(),
                        // The treasurers are told from here, where the
                        // relève actually runs.
                        $notificationService === null ? null : new \Modules\Finance\Mail\FinanceMailNotifier(
                            $notificationService,
                            $pdo,
                            new \Core\Badge\BadgeRepository($pdo),
                            new \Core\Badge\MemberBadgeRepository($pdo),
                            $userAccountRepo,
                            $financeScoutYearId
                        )
                    ));
                }

                // Order is immaterial here too (§8.58): a dedicated camps
                // box is open to camps alone by configuration, and on a
                // shared box every consumer the operator allowed is asked.
                if ($inboundMail !== null && in_array('camps', $enabledModuleIds, true)) {
                    $llm = $context->getOptional(\Modules\LlmConnector\Api\LlmConnectorInterface::class);
                    $campsPlaceRepo = new \Modules\Camps\Repository\PlaceRepository($pdo);
                    $campsCampRepo = new \Modules\Camps\Repository\CampRepository($pdo, $encryptionService);
                    $campsMessageReader = new \Modules\Camps\Mail\MessageReader();

                    $registry->register(new \Modules\Camps\Mail\CampsMessageConsumer(
                        $campsCampRepo,
                        $pdo,
                        $encryptionService,
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
                            $llm,
                            // The deferred pass is exactly where an
                            // attachment's BYTES are allowed to be read
                            // (§8.58) — never inside the sync loop.
                            new \Modules\Camps\Mail\AttachmentTextReader(
                                new \Core\File\StoredFileReader(
                                    $fileRepository,
                                    new \Core\File\EncryptedFileStorageService(
                                        $fileRepository,
                                        $encryptionService,
                                        $storagePath
                                    ),
                                    $storagePath
                                ),
                                null,
                                // The last resort: a scanned contract,
                                // transcribed at the OCR tier. A PDF that
                                // carries its own text never costs a call.
                                $llm
                            ),
                            // Every refusal of this path lands in the
                            // journal, named. It is the module's most
                            // asked-about behaviour and was its most silent.
                            $journalService
                        ),
                        // The stay a message names rather than the stay it
                        // would invent. Built beside the service above and
                        // deliberately NOT inside it: it obeys none of
                        // `camps_auto_create_from_mail`'s guards, because
                        // attaching a message to a booking the unit already
                        // made writes nothing new down.
                        new \Modules\Camps\Mail\ExistingStayMatcher(
                            $campsCampRepo,
                            $campsMessageReader,
                            $journalService
                        ),
                        // The model as a last resort between two stays:
                        // orders the propositions, never associates.
                        modelChoice: new \Modules\Camps\Mail\StayChoiceByModel($llm),
                        // The stay's chiefs are told of a proposition, or
                        // of a stay created from a message, from here —
                        // the deferred pass is the one that creates stays.
                        notifier: $notificationService === null ? null : new \Modules\Camps\Mail\CampsMailNotifier(
                            $notificationService,
                            new \Modules\Camps\Service\ReviewNotificationService(
                                $campsCampRepo,
                                new \Core\Member\SectionService(
                                    \Core\Database\Connection::withPdo($pdo),
                                    $encryptionService,
                                    new \Core\Badge\MemberBadgeRepository($pdo)
                                ),
                                $userAccountRepo,
                                $encryptionService,
                                $pdo,
                                $notificationService
                            )
                        )
                    ));
                }

                // Both passes go through this registry, so both get the
                // same journal — including for the failures it swallows.
                $registry->setAnalysisJournal(
                    new \Modules\InboundMail\Service\AnalysisJournal($journalService)
                );

                return $registry;
        };

        $runner->registerHandlerFactory(
            'inbound_mail',
            \Modules\InboundMail\Task\SyncMailboxesHandler::TASK_KEY,
            static function (\Core\Scheduler\TaskContext $context) use (
                $pdo,
                $encryptionService,
                $settingService,
                $journalService,
                $notificationService,
                $inboundConsumerRegistry
            ): \Core\Scheduler\TaskHandlerInterface {
                // The disk ceiling (D5). Its alert is a CLOSURE rather than
                // the notification service itself, so the quota logic stays
                // testable without a mail stack and this file keeps
                // deciding what « tell the superadmin » means here.
                $quota = new \Modules\InboundMail\Service\StorageQuotaService(
                    new \Modules\InboundMail\Repository\InboundMessageRepository($pdo, $encryptionService),
                    $settingService,
                    new \Core\Config\SettingRepository($pdo),
                    $journalService,
                    $notificationService === null ? null : static function (int $quotaMb, int $purged) use (
                        $pdo,
                        $encryptionService,
                        $notificationService
                    ): void {
                        // Only a superadmin can raise the quota or buy
                        // space, so only a superadmin is told. Nothing
                        // about any message is named — a count and a
                        // number of megabytes (§7.9).
                        $accounts = new \Core\Security\UserAccountRepository($pdo, $encryptionService);
                        foreach ($accounts->findSuperAdmins() as $entry) {
                            $notificationService->notify(
                                $entry['account']->id,
                                'Espace de stockage du courrier entrant saturé',
                                sprintf(
                                    'La limite de %d Mo est atteinte : les pièces jointes entrantes ne sont plus '
                                    . 'enregistrées. %d message(s) sans association ont été purgés pour libérer de '
                                    . 'la place. Les messages eux-mêmes restent conservés.',
                                    $quotaMb,
                                    $purged
                                ),
                                '/config/parametres'
                            );
                        }
                    }
                );

                $registry = $inboundConsumerRegistry($context);
                $inboundMailboxes = new \Modules\InboundMail\Repository\InboundMailboxRepository($pdo, $encryptionService);
                // What each box lets each module do (IT-05). Built from
                // the SAME registry the handler gets, so the modules the
                // screen scopes and the modules the sync asks cannot be
                // two different lists.
                $inboundScopes = new \Modules\InboundMail\Service\MailboxScopeService($inboundMailboxes, $registry);

                return new \Modules\InboundMail\Task\SyncMailboxesHandler(
                    $registry,
                    $quota,
                    $inboundScopes,
                    // One journal line per message stored, saying what each
                    // module made of it — including « rien », which is the
                    // answer a unit most often needs and the one this
                    // pipeline never gave.
                    new \Modules\InboundMail\Service\AnalysisJournal($journalService),
                    // The signed reply addresses this site minted, read
                    // off the recipients before any consumer is asked
                    // (§8.58).
                    new \Modules\InboundMail\Service\ReplyAddressService(
                        $inboundMailboxes,
                        $encryptionService,
                        $inboundScopes,
                        $settingService
                    )
                );
            }
        );
        // The interval is a setting (« Intervalle entre deux relèves »), so
        // bootstrap() is handed the settings rather than a constant: it is
        // also what pulls a run queued at an older, longer interval
        // forward, so a shortened delay applies on the next page view
        // instead of after the old one finally elapses.
        \Modules\InboundMail\Task\SyncMailboxesHandler::bootstrap($schedulerService, $settingService);

        // The deferred, content-level pass (§8.58): everything that needs
        // an attachment's BYTES rather than its metadata. Never inside the
        // synchronisation loop — a PDF extraction there would blow through
        // max_execution_time and leave the cursor unmoved, so the same
        // doomed run would repeat on every tick.
        $runner->registerHandlerFactory(
            'inbound_mail',
            \Modules\InboundMail\Task\AnalyzeStoredMessagesHandler::TASK_KEY,
            static fn(\Core\Scheduler\TaskContext $context): \Core\Scheduler\TaskHandlerInterface
                => new \Modules\InboundMail\Task\AnalyzeStoredMessagesHandler(
                    $inboundConsumerRegistry($context),
                    new \Modules\InboundMail\Service\AnalysisJournal($journalService)
                )
        );
        \Modules\InboundMail\Task\AnalyzeStoredMessagesHandler::bootstrap($schedulerService);

        // The retention that makes storing everything defensible (§8.58).
        // Auto-resolved from the manifest — it needs no consumer, only the
        // connection and the settings the context already carries — so it
        // is seeded here and nothing else.
        \Modules\InboundMail\Task\PurgeUnlinkedMessagesHandler::bootstrap($schedulerService);
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
