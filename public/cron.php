<?php

/**
 * Cron entry point for scheduled tasks.
 * Usage: php public/cron.php
 * Cron: * * * * * /usr/bin/php /path/to/public/cron.php
 */

declare(strict_types=1);

// CLI only. This file lives inside the document root (public/) so the same
// PHP-FPM/CGI runtime that serves the site can run it from crontab, but a
// web request must never reach it: public/.htaccess only rewrites paths
// that do NOT exist on disk, so GET /cron.php would otherwise execute the
// full scheduler pass — backups, updates, resets, journal purge — for any
// anonymous visitor, with none of the once-per-60s throttle the in-request
// scheduler tail in public/index.php applies. There is nothing to serve to
// a browser here, so a non-CLI entry is a flat 404.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$composerAutoloader = require_once __DIR__ . '/../vendor/autoload.php';

// Same wall clock as public/index.php — a scheduled action written by a web
// request and claimed by this pass has to be read on the clock it was
// written on. See Core\Config\AppClock.
\Core\Config\AppClock::apply();

// Same safety net as public/index.php — see Core\System\ComposerAutoloadSync's
// own docblock. Needed here too: a scheduled task from a module whose PSR-4
// entry predates the last time vendor/ was regenerated would otherwise fail
// with "Class not found" the moment cron runs it, even though every web
// request already works fine.
\Core\Http\ErrorHandler::register(false);
\Core\System\ComposerAutoloadSync::apply($composerAutoloader, __DIR__ . '/../composer.json');

use Core\Config\ScoutYearService;
use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Http\Router;
use Core\Import\MemberYearRepository;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailServiceFactory;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Notification\VapidKeyPairFactory;
use Core\Scheduler\CoreTaskHandlers;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Member\MemberEmailRepository;
use Core\Security\Role;
use Core\Security\RoleResolver;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use Core\View\MenuBuilder;
use Minishlink\WebPush\WebPush;

// Check initialization
$secretManager = new SecretManager(
    __DIR__ . '/../storage/keys/master.key',
    __DIR__ . '/../storage/config/secrets.enc'
);

if (!$secretManager->isInitialized()) {
    echo "Site not initialized.\n";
    exit(1);
}

$secrets = $secretManager->readSecrets();

$connection = new Connection(
    $secrets['db_host'] ?? 'localhost',
    (int) ($secrets['db_port'] ?? 3306),
    $secrets['db_name'] ?? '',
    $secrets['db_user'] ?? '',
    $secrets['db_password'] ?? ''
);

$pdo = $connection->getPdo();

$encryptionService = EncryptionService::fromEncodedKeys(
    (string) ($secrets['encryption_key'] ?? ''),
    (string) ($secrets['blind_index_key'] ?? '')
);

// Create services
$settingService = new SettingService(new SettingRepository($pdo));

// Marks that THIS entry point (a real crontab), not just the poor-man's-
// cron in public/index.php (which stamps its own 'scheduler_last_run' on
// every web hit), actually ran — Core\Http\Controller\
// NotificationConfigController warns on /config/notifications when this
// is missing or stale, since a member-facing push relying solely on the
// poor-man's-cron only ever fires on someone's next page view.
$settingService->register('cron_last_run', '0', 'number', 'Dernier passage du cron réel',
    'Horodatage (timestamp Unix) du dernier passage de public/cron.php — jamais mis à jour par le pseudo-cron. Lecture seule.',
    null, null, null, false, 999);
(new SettingRepository($pdo))->updateValue(null, 'cron_last_run', (string) time());
$journalRepo = new JournalRepository($pdo);
$journalService = new JournalService($journalRepo);
$schedulerRepo = new SchedulerRepository($pdo);
$runner = new SchedulerRunner($schedulerRepo, $journalService);
$userAccountRepo = new UserAccountRepository($pdo, $encryptionService);
// Role resolution, wired exactly as public/index.php wires it — see the
// NotificationService construction below for why this entry point can no
// longer do without it.
$scoutYearService = new ScoutYearService($pdo);
$roleResolver = new RoleResolver(
    new MemberYearRepository($pdo),
    $encryptionService,
    $pdo,
    new MemberEmailRepository($pdo, $encryptionService)
);
$dkimManager = new DkimManager(__DIR__ . '/../storage/keys');
// short_name, mail_from_address, mail_from_name and dkim_selector all live
// in the settings table (migrated out of secrets.enc by public/index.php's
// one-time migration) — merge them back in, same fix as public/index.php's
// own MailService construction (see its comment for why an empty
// mail_from_address is worse than the missing "[XX]" subject prefix: it
// makes PHPMailer reject every send outright with "Invalid address: (From): ").
foreach (['short_name', 'mail_from_address', 'mail_from_name', 'dkim_selector'] as $mailSecretKey) {
    $secrets[$mailSecretKey] = (string) ($settingService->get($mailSecretKey) ?: ($secrets[$mailSecretKey] ?? ''));
}
$mailService = MailServiceFactory::create($secrets, $dkimManager);

// Web Push (Core\Notification) — same construction as public/index.php.
// Null when VAPID keys aren't provisioned yet (e.g. this script running
// before the site has ever been reached over HTTP, where the keys are
// self-healed) or aren't actually valid (VAPID::createVapidKeys() has been
// observed to intermittently produce a key WebPush's own constructor
// rejects — see VapidKeyPairFactory) — TaskContext::$notifications is
// nullable and every handler calls it via ?->, so either case degrades to
// "no push, everything else still runs" rather than crashing the whole
// cron pass silently and invisibly.
//
// Built BEFORE ModuleManager on purpose: loadEnabledModules() below is
// what registers each module's notification TYPES into this service, and
// it can only do that for a service that already exists. When this block
// lived after it, ModuleManager got null, no module type was ever
// registered under a real crontab, and the first handler to dispatch one
// (mass_mail's send_batch, for a recipient holding a login account) died
// mid-batch on "Undeclared notification type" — stranding every recipient
// after that one as 'pending' forever (caught by
// tests/e2e/specs/mass-mail-merge.spec.js).
$notificationService = null;
if (VapidKeyPairFactory::isValid(
    (string) ($secrets['vapid_public_key'] ?? ''),
    (string) ($secrets['vapid_private_key'] ?? '')
)) {
    $vapidSubjectEmail = (string) ($settingService->get('contact_email') ?: $settingService->get('mail_from_address') ?: '');
    $vapidSubject = $vapidSubjectEmail !== '' ? 'mailto:' . $vapidSubjectEmail : (string) ($settingService->get('base_url') ?: 'https://localhost');
    $webPush = new WebPush(['VAPID' => [
        'subject' => $vapidSubject,
        'publicKey' => (string) $secrets['vapid_public_key'],
        'privateKey' => (string) $secrets['vapid_private_key'],
    ]]);
    $notificationService = new NotificationService(
        new NotificationRepository($pdo, $encryptionService),
        new PushSubscriptionRepository($pdo, $encryptionService),
        new NotificationPreferenceRepository($pdo),
        $webPush,
        $settingService,
        $journalService,
        new SchedulerService($schedulerRepo),
        $userAccountRepo,
        // RoleResolver/ScoutYearService, same as public/index.php (§8.17:
        // the two entry points must not drift). This used to be left out
        // deliberately, on the reading that a cron-triggered dispatch()
        // never had a real recipient list — which stopped being true the
        // moment a background task started ANNOUNCING something to an
        // audience defined by role rather than answering the person who
        // asked for it. Without these, dispatch() skips the role_min
        // re-check entirely: an automatic update installed by the real
        // crontab would notify every account on the site, member and
        // parent included, instead of the superadmins the type is
        // declared for.
        $roleResolver,
        $scoutYearService
    );
}

// Load enabled modules so their scheduled task handlers (module.json
// "scheduled_tasks") are resolvable — without this, every module-registered
// task fails unconditionally with "No handler registered", since
// SchedulerRunner only knows about handlers a ModuleManager has loaded.
// Router/MenuBuilder are only needed here to satisfy ModuleManager's
// constructor; their route/menu output is never used in a CLI context.
$migrationRunner = new MigrationRunner(
    $connection,
    new SchemaIntrospector($pdo),
    new SchemaComparator(),
    new SqlParser()
);
$moduleManager = new ModuleManager(
    __DIR__ . '/../modules',
    $settingService,
    new CookieConsentService(),
    new MenuBuilder(Role::SUPERADMIN),
    new ModuleRegistryRepository($pdo),
    $migrationRunner,
    $journalService,
    new Router(),
    $notificationService,
    new \Core\Offline\OfflineWhitelist(),
    // Same profile resolution as public/index.php, through the same
    // resolver — a module gated by visible_when must have its scheduled
    // tasks resolvable under a real crontab too.
    \Core\Module\InstallationProfile::resolve(
        (string) ($settingService->get('base_url') ?? ''),
        (string) ($settingService->get('statistics_destination') ?? '')
    ),
    null,
    // Same manifest cache as public/index.php (mtime-keyed).
    dirname(__DIR__) . '/storage/temp'
);
$moduleManager->loadEnabledModules();
$runner->setModuleManager($moduleManager);

// Core (not module) scheduled task handlers. Declared once in
// Core\Scheduler\CoreTaskHandlers and registered identically here and in
// public/index.php — this file used to carry its own hand-maintained copy
// of the list, and create_backup being absent from it meant a real crontab
// failed every background backup with "No handler registered" (§8.17).
CoreTaskHandlers::registerAll($runner);

// The rentals reminder pass (§6.29), for the same reason: its money
// reminders need Finance's public API, which only a composition root can
// build. Registered in public/index.php too — a handler registered in one
// entry point only fails unconditionally under the other (§8.17/§8.20).
//
// Under a real crontab there is no request, so the service is assembled
// here from what this file already has. Finance is genuinely absent in this
// construction, and RentalReminderService says nothing about money rather
// than guessing what was received.
if (in_array('rental', $moduleManager->getEnabledModuleIds(), true)) {
    $runner->registerHandler(
        'rental',
        \Modules\Rental\Task\SendRentalRemindersHandler::TASK_KEY,
        new \Modules\Rental\Task\SendRentalRemindersHandler()
    );

    // The retention purge (§6.35), same shape — and assembled here rather
    // than left to the handler's self-built fallback, because that fallback
    // cannot reach Finance either. A purged booking whose receivable
    // survives leaves the unit being owed money by a stay that no longer
    // exists, under a label carrying the renter's name: the deletion would
    // be incomplete in exactly the way §6.35 forbids. `inbound_mail` is
    // still absent from this construction; a purged booking's emails are
    // cleaned up on the next web-path run.
    $rentalPurgePaymentService = null;
    if (in_array('finance', $moduleManager->getEnabledModuleIds(), true)) {
        $rentalPurgePaymentService = new \Modules\Rental\Service\RentalPaymentService(
            new \Modules\Rental\Repository\RentalPaymentRepository($pdo, $encryptionService),
            // No ActorAccountResolver: nothing on the cron path has an
            // actor to resolve. Every change it records is the application
            // acting on its own, which Core\Audit renders as an automatic
            // entry — the honest reading and the one a manager needs.
            new \Modules\Rental\Audit\BookingAudit(
                new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($pdo, $encryptionService))
            ),
            $journalService,
            (static function () use ($pdo, $encryptionService): \Modules\Finance\Service\ExpectedReceivableService {
                $receivableRepository = new \Modules\Finance\Repository\ExpectedReceivableRepository($pdo, $encryptionService);

                return new \Modules\Finance\Service\ExpectedReceivableService(
                    $receivableRepository,
                    new \Modules\Finance\Service\ReceivableAllocationService(
                        $receivableRepository,
                        new \Modules\Finance\Repository\ReceivableAllocationRepository($pdo),
                        new \Modules\Finance\Repository\TransactionRepository($pdo, $encryptionService),
                        new \Modules\Finance\Repository\AccountRepository($pdo, $encryptionService),
                        // Nothing on the cron path has a session to
                        // narrow the account partition against — the same
                        // "acting for the installation" stance as the
                        // audit entries just above.
                        new \Modules\Finance\Service\AccountVisibility(
                            \Modules\Finance\Service\TreasurerScope::systemCaller()
                        )
                    )
                );
            })()
        );
    }

    $runner->registerHandler(
        'rental',
        \Modules\Rental\Task\PurgeRentalBookingsHandler::TASK_KEY,
        new \Modules\Rental\Task\PurgeRentalBookingsHandler(
            new \Modules\Rental\Service\RentalRetentionService(
                new \Modules\Rental\Repository\RentalBookingRepository($pdo, $encryptionService),
                new \Modules\Rental\Repository\RentalDocumentRepository($pdo),
                new \Modules\Rental\Repository\RentalAggregateRepository($pdo),
                new \Modules\Rental\Repository\RentalReminderRepository($pdo),
                $settingService,
                $journalService,
                $pdo,
                new \Core\File\FileRepository($pdo),
                null,
                dirname(__DIR__) . '/storage',
                $rentalPurgePaymentService,
                // Its change history too: `entity_changes` carries no
                // foreign key, so nothing about it cascades (§8.66).
                new \Modules\Rental\Audit\BookingAudit(
                    new \Core\Audit\AuditService(new \Core\Audit\AuditRepository($pdo, $encryptionService))
                )
            )
        )
    );
}

// Inbound mail's polling task (§7.4) is the one module task that cannot be
// auto-resolved from its manifest: it needs the consumer registry, and only
// a composition root can build one. The same registration exists in
// public/index.php — a handler registered in only one of the two entry
// points fails unconditionally under the other with "No handler
// registered" (§8.17/§8.20), which is precisely the bug create_backup once
// shipped.
//
// Under a real crontab there is no HTTP request and therefore no consuming
// module's block to append to this registry; each enabled consumer
// registers itself below, exactly as it does on the web path.
if (in_array('inbound_mail', $moduleManager->getEnabledModuleIds(), true)) {
    $inboundMailConsumerRegistry = new \Modules\InboundMail\Service\MessageConsumerRegistry();

    $runner->registerHandler(
        'inbound_mail',
        \Modules\InboundMail\Task\SyncMailboxesHandler::TASK_KEY,
        new \Modules\InboundMail\Task\SyncMailboxesHandler($inboundMailConsumerRegistry)
    );
}

// Camps' summary refresher is the second hand-registered module task, for
// the same reason and with the same failure mode: it consumes
// `llm_connector`'s public API (ARCHITECTURE.md §7.5), only a composition
// root knows whether that module is enabled, and a handler registered in
// only one of the two entry points fails unconditionally under the other.
// Null connector — llm_connector absent or disabled — means no summary is
// written and every other camps screen is unaffected.
if (in_array('camps', $moduleManager->getEnabledModuleIds(), true)) {
    $campsLlmConnector = null;
    if (in_array('llm_connector', $moduleManager->getEnabledModuleIds(), true)) {
        $campsLlmConnector = new \Modules\LlmConnector\Service\LlmConnectorService(
            new \Modules\LlmConnector\Repository\ProviderRepository($pdo, $encryptionService),
            new \Modules\LlmConnector\Repository\ProviderModelRepository($pdo),
            $journalService
        );
    }

    $runner->registerHandler(
        'camps',
        \Modules\Camps\Task\RefreshPlaceSummariesHandler::TASK_KEY,
        new \Modules\Camps\Task\RefreshPlaceSummariesHandler($campsLlmConnector)
    );
}

// Task handlers need the same shared services a real request builds (DB,
// encryption, mail, journal, settings, and the super-admin lookup used for
// system-alert emails) — see Core\Scheduler\TaskContext.
$runner->setTaskContext(new TaskContext(
    $connection,
    $encryptionService,
    $mailService,
    $journalService,
    $settingService,
    $userAccountRepo,
    dirname(__DIR__) . '/storage',
    $notificationService
));

// Process overdue tasks
$processed = $runner->processOverdue();

// Cleanup old journal entries
$retentionDays = (int) ($settingService->get('journal_retention_days') ?: '730');
$deleted = $journalService->cleanup($retentionDays);

// Same rhythm, same reason: bots probing /login and PDF previews both
// leave artifacts nothing else ever deletes (see
// LoginThrottler::purgeStale() / PdfThumbnailCache::purgeStale()).
(new \Core\Security\LoginThrottler($connection))->purgeStale();
\Core\File\PdfThumbnailCache::purgeStale(dirname(__DIR__) . '/storage');

if ($processed > 0 || $deleted > 0) {
    echo "Processed {$processed} task(s), deleted {$deleted} old journal entry/entries.\n";
}
