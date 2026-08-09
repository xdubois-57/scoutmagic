<?php

/**
 * Cron entry point for scheduled tasks.
 * Usage: php public/cron.php
 * Cron: * * * * * /usr/bin/php /path/to/public/cron.php
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\DkimManager;
use Core\Mail\MailServiceFactory;
use Core\Maintenance\Task\AutoBackupHandler;
use Core\Maintenance\Task\CheckStableUpdateHandler;
use Core\Maintenance\Task\CreateBackupHandler;
use Core\Maintenance\Task\FullResetHandler;
use Core\Maintenance\Task\InstallUpdateHandler;
use Core\Maintenance\Task\ResetSettingsHandler;
use Core\Maintenance\Task\RestoreBackupHandler;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Notification\NotificationPreferenceRepository;
use Core\Notification\NotificationRepository;
use Core\Notification\NotificationService;
use Core\Notification\PushSubscriptionRepository;
use Core\Notification\VapidKeyPairFactory;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\Role;
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

$encryptionService = new EncryptionService(
    $secrets['encryption_key'] ?? '',
    $secrets['blind_index_key'] ?? ''
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
    new Router()
);
$moduleManager->loadEnabledModules();
$runner->setModuleManager($moduleManager);

// Core (not module) scheduled task handlers — registered directly since
// module.json's scheduled_tasks mechanism only applies to module handlers.
// Missing from this file before this fix (only public/index.php's own
// poor-man's-cron registered them), which meant a real crontab running
// this script would fail every core background task — backups, update
// checks, and update installs — with "No handler registered", unless a web
// request happened to win the race first.
$runner->registerHandler('core', 'create_backup', new CreateBackupHandler());
$runner->registerHandler('core', 'install_update', new InstallUpdateHandler());
$runner->registerHandler('core', 'reset_settings', new ResetSettingsHandler());
$runner->registerHandler('core', 'full_reset', new FullResetHandler());
$runner->registerHandler('core', 'restore_backup', new RestoreBackupHandler());
$runner->registerHandler('core', 'auto_backup', new AutoBackupHandler());
$runner->registerHandler('core', 'check_stable_update', new CheckStableUpdateHandler());
$runner->registerHandler('core', 'compress_section_document', new \Core\Member\Task\CompressSectionDocumentHandler());
$runner->registerHandler('core', 'send_notifications', new \Core\Notification\Task\SendNotificationsHandler());
$runner->registerHandler('core', 'purge_notifications', new \Core\Notification\Task\PurgeNotificationsHandler());

// Web Push (Core\Notification) — same construction as public/index.php.
// Null when VAPID keys aren't provisioned yet (e.g. this script running
// before the site has ever been reached over HTTP, where the keys are
// self-healed) or aren't actually valid (VAPID::createVapidKeys() has been
// observed to intermittently produce a key WebPush's own constructor
// rejects — see VapidKeyPairFactory) — TaskContext::$notifications is
// nullable and every handler calls it via ?->, so either case degrades to
// "no push, everything else still runs" rather than crashing the whole
// cron pass silently and invisibly.
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
        $userAccountRepo
        // No RoleResolver/ScoutYearService here — a cron-triggered
        // dispatch() (e.g. CreateBackupHandler's auto_backup path, which
        // has no human requester and so never actually calls dispatch()
        // with a real recipient anyway) simply skips the role_min re-check
        // rather than reject every recipient, same documented degradation
        // as NotificationService's own class docblock.
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

if ($processed > 0 || $deleted > 0) {
    echo "Processed {$processed} task(s), deleted {$deleted} old journal entry/entries.\n";
}
