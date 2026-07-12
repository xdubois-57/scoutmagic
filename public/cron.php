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
use Core\Database\Connection;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Security\SecretManager;

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

// Create services
$settingService = new SettingService(new SettingRepository($pdo));
$journalRepo = new JournalRepository($pdo);
$journalService = new JournalService($journalRepo);
$schedulerRepo = new SchedulerRepository($pdo);
$runner = new SchedulerRunner($schedulerRepo, $journalService);

// Process overdue tasks
$processed = $runner->processOverdue();

// Cleanup old journal entries
$retentionDays = (int) ($settingService->get('journal_retention_days') ?: '730');
$deleted = $journalService->cleanup($retentionDays);

if ($processed > 0 || $deleted > 0) {
    echo "Processed {$processed} task(s), deleted {$deleted} old journal entry/entries.\n";
}
