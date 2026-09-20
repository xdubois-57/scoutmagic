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
use Core\Support\Collector\RequestTimelinesCollector;
use Core\Support\Collector\ScheduledTasksCollector;
use Core\Support\Collector\StatisticsCollector;
use Core\Storage\Location\Backend\StorageBackendFactory;
use Core\Storage\Location\StorageLocationConsumerRegistry;
use Core\Storage\Location\StorageLocationRepository;
use Core\Storage\Location\StorageLocationService;
use Core\Storage\Volume\VolumeInventory;
use Core\Support\Collector\OutboundMailCollector;
use Core\Support\Collector\StorageLocationsCollector;
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
            new RequestTimelinesCollector(),
            new ScheduledTasksCollector(StatisticsServiceFactory::moduleManager($context)),
            new UpdateHistoryCollector(),
            new PhpInfoCollector(),
            new ExtensionsCollector(),
            new OpcacheCollector(),
            new FilesystemCollector(),
            new CommandsCollector(),
            new BackgroundExecutionCollector(),
            new CronCadenceCollector(),
            self::outboundMailCollector($context),
            self::storageLocationsCollector($context),
            new WebServerCollector(),
            new LogsCollector(),
        ];
    }

    /**
     * The collectors' names, in the order the archive is built — for the
     * screen that has to say what an administrator is agreeing to
     * transmit (roadmap IT-26, `Core\Support\Ticket\ArchiveContents`).
     *
     * Declared here rather than duplicated on the page, so the list a
     * consent screen shows is the list the archive is actually made of:
     * two places would drift the first time a collector is added, and the
     * one that drifts is the one somebody ticked a box beside.
     *
     * @return list<string>
     */
    public static function collectorNames(): array
    {
        return [
            'statistics',
            'database_structure',
            'configuration_parameters',
            'event_journal',
            'request_timelines',
            'scheduled_tasks',
            'update_history',
            'phpinfo',
            'extensions',
            'opcache',
            'filesystem',
            'commands',
            'background_execution',
            'cron_cadence',
            'outbound_mail',
            'storage_locations',
            'webserver',
            'logs',
        ];
    }

    /**
     * Where this installation writes, and onto which filesystems.
     *
     * The consumer registry is built EMPTY here, and that is not an
     * oversight. This runs in a scheduled task rather than in the request
     * that serves a page, so no module has registered anything. What the
     * collector must therefore print is « indéterminé », NOT « rien »:
     * those two are opposite answers, and a location a gallery is standing
     * on would be read as a location nobody uses — which is exactly the
     * conclusion somebody deletes on.
     * {@see StorageLocationConsumerRegistry::isEmpty()} is what lets it
     * tell « nobody uses this » from « nobody was asked », from memory and
     * without a query. The screen is where that question is answered
     * completely; what this file is for is the health results, the
     * capability consequences and the volume grouping, none of which
     * depends on a consumer.
     */
    private static function storageLocationsCollector(TaskContext $context): StorageLocationsCollector
    {
        $pdo = $context->connection->getPdo();
        $repository = new StorageLocationRepository($pdo, $context->encryption);
        $consumers = new StorageLocationConsumerRegistry();
        $backends = new StorageBackendFactory($repository, $context->storagePath);

        return new StorageLocationsCollector(
            $repository,
            $consumers,
            new VolumeInventory(
                $context->storagePath,
                $context->settings,
                new StorageLocationService($repository, $backends, $consumers),
                $backends
            )
        );
    }

    /**
     * The outbound chains, resolved (ARCHITECTURE.md §8.106).
     *
     * A provider's host and port live in `secrets.enc` beside its
     * credentials, so resolving one means reading that file — which this
     * factory already does, once, for the redaction needles. Nothing
     * that comes back carries a password: `MailProvider` has no property
     * for one, and only `TransportConfigurator` ever asks
     * `ProviderConnections` for it.
     */
    private static function outboundMailCollector(TaskContext $context): OutboundMailCollector
    {
        $pdo = $context->connection->getPdo();
        $secretManager = StatisticsServiceFactory::secretManager($context);

        $secrets = [];
        if ($secretManager->isInitialized()) {
            try {
                $secrets = $secretManager->readSecrets();
            } catch (\Throwable) {
                // Unreadable secrets mean hosts printed as « (vide) » —
                // the chains, the order and the counters are still worth
                // having, and a collector that threw would contribute
                // nothing at all.
                $secrets = [];
            }
        }

        $chains = new \Core\Mail\Transport\LaneChainRepository($pdo);
        $deferred = new \Core\Mail\Transport\DeferredMailRepository($pdo, $context->encryption);

        return new OutboundMailCollector(
            new \Core\Mail\Transport\MailProviderDirectory(
                new \Core\Mail\Transport\MailProviderRepository($pdo),
                new \Core\Mail\Transport\ProviderConnections($secrets),
                $context->settings
            ),
            $chains,
            new \Core\Mail\Transport\ProviderHealthRepository($pdo),
            new \Core\Mail\Transport\MailReserve(
                new \Core\Mail\Transport\SendCounterRepository($pdo),
                $chains
            ),
            $deferred,
            new \Core\Mail\Transport\DeferredMailQueue($deferred, $context->settings),
            $context->settings,
            // The round trip's own rows (roadmap IT-03) — the repository
            // and not the verifier, deliberately: a support package must
            // never be able to SEND a probe while it is being assembled,
            // and a collector that cannot reach `launch()` cannot be
            // talked into it by a later edit.
            new \Core\Mail\Feedback\ReturnProbeRepository($pdo, $context->encryption),
            // Read-only, and optional (§7.5): it answers « la vérification
            // était-elle seulement possible », which is what separates
            // « jamais vérifié » from « vérification impossible » in the
            // archive. Null when the module is off — and that is then the
            // truthful answer rather than a missing one.
            $context->getOptional(\Modules\InboundMail\Api\InboundMailInterface::class),
            // The manual probes (roadmap IT-04), and the repository for
            // the same reason as above: the archive reports what was
            // tested and what came of it, and must not be able to send
            // one. What travels is the road and the verdict — never the
            // destination, which is a person.
            new \Core\Mail\Probe\MailProbeRepository($pdo, $context->encryption),
            new \Core\Mail\Feedback\Bounce\BounceStateRepository($pdo, $context->encryption),
            // The DMARC reports (roadmap IT-06). Unconditional, like the
            // bounce state beside it: the tables are core and always
            // readable, and « aucun rapport reçu » is a true answer worth
            // having in an archive rather than a section that vanishes.
            new \Core\Mail\Feedback\Dmarc\DmarcReportRepository($pdo)
        );
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
