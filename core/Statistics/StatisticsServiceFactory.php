<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

use Core\Cookie\CookieConsentService;
use Core\Database\MigrationRunner;
use Core\Database\SchemaComparator;
use Core\Database\SchemaIntrospector;
use Core\Database\SqlParser;
use Core\Http\Router;
use Core\Maintenance\VersionFile;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Scheduler\TaskContext;
use Core\Security\Role;
use Core\Security\SecretManager;
use Core\View\MenuBuilder;

/**
 * Rebuilds the statistics stack from a Core\Scheduler\TaskContext.
 *
 * `SchedulerRunner` constructs every handler with a bare `new`, so a task
 * gets only what `TaskContext` carries — which is deliberately generic and
 * knows nothing about modules or secrets. Without this, the scheduled
 * report would have to give up the module list entirely (D-15: knowing
 * which module version is running is half the value of a bug report), so
 * the factory assembles the same objects the composition root does, from
 * the pieces the context does provide.
 *
 * `discoverModules()` is a read-only scan — the ModuleManager built here is
 * never asked to load or migrate anything.
 */
final class StatisticsServiceFactory
{
    public static function projectRoot(TaskContext $context): string
    {
        return dirname($context->storagePath);
    }

    public static function secretManager(TaskContext $context): SecretManager
    {
        return new SecretManager(
            $context->storagePath . '/keys/master.key',
            $context->storagePath . '/config/secrets.enc'
        );
    }

    public static function identityService(TaskContext $context): InstallationIdentityService
    {
        return new InstallationIdentityService(
            $context->settings,
            self::secretManager($context),
            $context->journal
        );
    }

    public static function payloadBuilder(TaskContext $context): StatisticsPayloadBuilder
    {
        $pdo = $context->connection->getPdo();

        return new StatisticsPayloadBuilder(
            $context->settings,
            $pdo,
            self::identityService($context),
            self::projectRoot($context),
            self::moduleManager($context),
            $context->mailService,
            // The usage_stats module's aggregate, resolved the way the
            // scheduled path resolves every cross-module capability
            // (ARCHITECTURE.md §7.5 applied to the scheduler): null when
            // the module is absent or disabled, which the report then
            // states as a null field rather than as an empty list.
            $context->getOptional(\Modules\UsageStats\Api\ModuleUsageInterface::class)
        );
    }

    public static function moduleManager(TaskContext $context): ModuleManager
    {
        $pdo = $context->connection->getPdo();

        return new ModuleManager(
            self::projectRoot($context) . '/modules',
            $context->settings,
            new CookieConsentService(),
            new MenuBuilder(Role::SUPERADMIN),
            new ModuleRegistryRepository($pdo),
            $context->journal,
            new Router()
        );
    }

    public static function sender(TaskContext $context, ?StatisticsTransportInterface $transport = null): StatisticsSender
    {
        return new StatisticsSender(
            $context->settings,
            self::payloadBuilder($context),
            self::identityService($context),
            $transport ?? new StreamStatisticsTransport(),
            $context->journal,
            $context->connection->getPdo(),
            VersionFile::read(self::projectRoot($context))
        );
    }
}
