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
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Security\SecretManager;
use Core\Security\UserAccountRepository;
use Core\View\MenuBuilder;

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
$journalRepo = new JournalRepository($pdo);
$journalService = new JournalService($journalRepo);
$schedulerRepo = new SchedulerRepository($pdo);
$runner = new SchedulerRunner($schedulerRepo, $journalService);
$userAccountRepo = new UserAccountRepository($pdo, $encryptionService);
$dkimManager = new DkimManager(__DIR__ . '/../storage/keys');
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

// Task handlers need the same shared services a real request builds (DB,
// encryption, mail, journal, settings, and the super-admin lookup used for
// system-alert emails) — see Core\Scheduler\TaskContext.
$runner->setTaskContext(new TaskContext(
    $connection,
    $encryptionService,
    $mailService,
    $journalService,
    $settingService,
    $userAccountRepo
));

// Process overdue tasks
$processed = $runner->processOverdue();

// Cleanup old journal entries
$retentionDays = (int) ($settingService->get('journal_retention_days') ?: '730');
$deleted = $journalService->cleanup($retentionDays);

if ($processed > 0 || $deleted > 0) {
    echo "Processed {$processed} task(s), deleted {$deleted} old journal entry/entries.\n";
}
