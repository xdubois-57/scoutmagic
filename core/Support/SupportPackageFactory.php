<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support;

use Core\File\EncryptedFileStorageService;
use Core\File\FileRepository;
use Core\Scheduler\TaskContext;
use Core\Security\SecretManager;
use Core\Statistics\StatisticsServiceFactory;
use Core\Support\Collector\BackgroundExecutionCollector;
use Core\Support\Collector\CommandsCollector;
use Core\Support\Collector\ConfigurationParametersCollector;
use Core\Support\Collector\CronCadenceCollector;
use Core\Support\Collector\DatabaseStructureCollector;
use Core\Support\Collector\EventJournalCollector;
use Core\Support\Collector\ExtensionsCollector;
use Core\Support\Collector\FilesystemCollector;
use Core\Support\Collector\LogsCollector;
use Core\Support\Collector\OpcacheCollector;
use Core\Support\Collector\PhpInfoCollector;
use Core\Support\Collector\ScheduledTasksCollector;
use Core\Support\Collector\StatisticsCollector;
use Core\Support\Collector\UpdateHistoryCollector;
use Core\Support\Collector\WebServerCollector;

/**
 * Assembles the support-package stack from a Core\Scheduler\TaskContext —
 * the same role Core\Statistics\StatisticsServiceFactory plays for the
 * statistics stack, and for the same reason: a handler built by
 * SchedulerRunner with a bare `new` gets only what the context carries.
 *
 * This is also the single place that decides **which collectors run and in
 * what order**, so adding one is a one-line change here rather than a
 * change spread over an entry point and a handler.
 */
final class SupportPackageFactory
{
    public static function service(TaskContext $context): SupportPackageService
    {
        $pdo = $context->connection->getPdo();
        $fileRepository = new FileRepository($pdo);

        return new SupportPackageService(
            $context->connection,
            $context->settings,
            $fileRepository,
            new EncryptedFileStorageService($fileRepository, $context->encryption, $context->storagePath),
            StatisticsServiceFactory::projectRoot($context),
            $context->storagePath,
            self::collectors($context),
            self::secretsToRedact(StatisticsServiceFactory::secretManager($context))
        );
    }

    /**
     * @return array<int, SupportCollectorInterface>
     */
    private static function collectors(TaskContext $context): array
    {
        return [
            new StatisticsCollector(StatisticsServiceFactory::payloadBuilder($context)),
            new DatabaseStructureCollector(),
            new ConfigurationParametersCollector(),
            new EventJournalCollector(),
            new ScheduledTasksCollector(StatisticsServiceFactory::moduleManager($context)),
            new UpdateHistoryCollector(),
            new PhpInfoCollector(),
            new ExtensionsCollector(),
            new OpcacheCollector(),
            new FilesystemCollector(),
            new CommandsCollector(),
            new BackgroundExecutionCollector(),
            new CronCadenceCollector(),
            new WebServerCollector(),
            new LogsCollector(),
        ];
    }

    /**
     * Every literal secret this installation holds, so a collector's failure
     * message can never smuggle one into `collection-status.json`.
     *
     * Reading `secrets.enc` here is deliberate and narrow: the values are
     * used only as needles for a string replacement and never written
     * anywhere.
     *
     * @return array<int, string>
     */
    public static function secretsToRedact(SecretManager $secretManager): array
    {
        if (!$secretManager->isInitialized()) {
            return [];
        }

        try {
            $secrets = $secretManager->readSecrets();
        } catch (\Throwable) {
            return [];
        }

        $values = [];
        foreach ($secrets as $value) {
            if (is_string($value)) {
                $values[] = $value;
            }
        }

        return $values;
    }
}
