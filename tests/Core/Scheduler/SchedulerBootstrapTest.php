<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Config\SettingRepository;
use Core\Config\SettingService;
use Core\Cookie\CookieConsentService;
use Core\Database\Connection;
use Core\Database\MigrationRunner;
use Core\Http\Router;
use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Mail\MailService;
use Core\Module\ModuleManager;
use Core\Module\ModuleRegistryRepository;
use Core\Scheduler\CoreTaskHandlers;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Security\EncryptionService;
use Core\Security\Role;
use Core\Security\UserAccountRepository;
use Core\View\MenuBuilder;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * The shared scheduler bootstrap (public/scheduler-bootstrap.php), pinned
 * two ways.
 *
 * PARITY, at the source level: both entry points delegate the scheduler's
 * whole wiring to the one shared function and register nothing of their
 * own — the guarantee that ended the entry-point drift family (§8.17:
 * create_backup missing under cron, rental reminders without Finance
 * under cron, an empty consumer registry under cron).
 *
 * BEHAVIOUR, by actually calling the function: the capabilities resolve
 * against real module enablement, every core handler is registered, and
 * the sync factory builds a handler whose registry holds the consumers in
 * the order the mailbox rules require.
 */
class SchedulerBootstrapTest extends TestCase
{
    private static function publicSource(string $file): string
    {
        $contents = file_get_contents(dirname(__DIR__, 3) . '/public/' . $file);
        self::assertNotFalse($contents);

        return $contents;
    }

    // ── Parity, at the source level ─────────────────────────────────────

    /**
     * @return array<string, array{string}>
     */
    public static function entryPoints(): array
    {
        return ['web' => ['index.php'], 'cron' => ['cron.php']];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testBothEntryPointsDelegateToTheSharedBootstrap(string $file): void
    {
        $source = self::publicSource($file);

        $this->assertStringContainsString("require_once __DIR__ . '/scheduler-bootstrap.php';", $source);
        $this->assertStringContainsString('scoutmagic_bootstrap_scheduler(', $source);
        $this->assertStringContainsString("define('SCOUTMAGIC_ENTRYPOINT', true);", $source);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('entryPoints')]
    public function testNoEntryPointWiresTheSchedulerItselfAnyMore(string $file): void
    {
        // Any of these re-grown in an entry point is the drift coming
        // back: a handler, a context or a core registration that exists on
        // one trigger and not the other.
        $source = self::publicSource($file);

        // `->` included so a docblock MENTIONING a method ("no manual
        // registerHandler() call needed") never trips this.
        $this->assertStringNotContainsString('->registerHandler(', $source, $file);
        $this->assertStringNotContainsString('->registerHandlerFactory(', $source, $file);
        $this->assertStringNotContainsString('->setTaskContext(', $source, $file);
        $this->assertStringNotContainsString('->setModuleManager(', $source, $file);
        $this->assertStringNotContainsString('CoreTaskHandlers::registerAll(', $source, $file);
    }

    public function testTheBootstrapFileRefusesADirectWebHit(): void
    {
        // The guard must be the first executable statement, before the
        // function definition — and the .htaccess belt-and-braces block
        // must name the file, like cron.php's.
        $bootstrap = self::publicSource('scheduler-bootstrap.php');
        $guard = strpos($bootstrap, "if (!defined('SCOUTMAGIC_ENTRYPOINT'))");
        $function = strpos($bootstrap, 'function scoutmagic_bootstrap_scheduler(');

        $this->assertIsInt($guard);
        $this->assertIsInt($function);
        $this->assertLessThan($function, $guard);

        $this->assertStringContainsString(
            '<Files "scheduler-bootstrap.php">',
            self::publicSource('.htaccess')
        );
    }

    // ── Behaviour, by calling the function ──────────────────────────────

    /**
     * @param string[] $enabledModuleIds
     * @return array{runner: SchedulerRunner, context: TaskContext}
     */
    private function bootstrap(array $enabledModuleIds): array
    {
        if (!defined('SCOUTMAGIC_ENTRYPOINT')) {
            define('SCOUTMAGIC_ENTRYPOINT', true);
        }
        require_once dirname(__DIR__, 3) . '/public/scheduler-bootstrap.php';

        $pdo = DatabaseTestHelper::createTestDatabase();
        $encryption = new EncryptionService(str_repeat('a', 32), str_repeat('b', 32));
        $settingService = new SettingService(new SettingRepository($pdo));
        $journalService = new JournalService(new JournalRepository($pdo));
        $registryRepo = new ModuleRegistryRepository($pdo);

        // loadEnabledModules() auto-activates enabled_by_default modules
        // that have no registry row yet, and activation runs their schema
        // migration — answered here by a mock reporting "complete, nothing
        // done", since this SQLite database has no module tables to write.
        $migrationRunner = $this->createMock(MigrationRunner::class);
        $migrationRunner->method('migrate')->willReturn(new \Core\Database\MigrationResult([], []));

        $moduleManager = new ModuleManager(
            dirname(__DIR__, 3) . '/modules',
            $settingService,
            new CookieConsentService(),
            new MenuBuilder(Role::SUPERADMIN),
            $registryRepo,
            $journalService,
            new Router()
        );
        foreach ($enabledModuleIds as $moduleId) {
            // installed_version far ahead, so loadEnabledModules() never
            // tries to migrate a module schema into this SQLite database.
            $pdo->exec("INSERT INTO module_registry (module_id, enabled, installed_version) VALUES ('{$moduleId}', 1, '999.0.0')");
        }
        $moduleManager->loadEnabledModules();

        $schedulerRepo = new SchedulerRepository($pdo);
        $runner = new SchedulerRunner($schedulerRepo, $journalService);

        $context = scoutmagic_bootstrap_scheduler(
            $runner,
            new SchedulerService($schedulerRepo),
            $moduleManager,
            Connection::withPdo($pdo),
            $encryption,
            $this->createMock(MailService::class),
            $journalService,
            $settingService,
            new UserAccountRepository($pdo, $encryption),
            sys_get_temp_dir(),
            null
        );

        return ['runner' => $runner, 'context' => $context];
    }

    public function testEveryCoreHandlerIsRegistered(): void
    {
        ['runner' => $runner] = $this->bootstrap([]);

        $property = new \ReflectionProperty(SchedulerRunner::class, 'handlers');
        $registered = array_keys($property->getValue($runner));

        foreach (array_keys(CoreTaskHandlers::all()) as $taskKey) {
            $this->assertContains('core::' . $taskKey, $registered);
        }
    }

    public function testTheLlmCapabilityResolvesExactlyWhenTheModuleIsEnabled(): void
    {
        ['context' => $with] = $this->bootstrap(['llm_connector']);
        ['context' => $without] = $this->bootstrap([]);

        $this->assertInstanceOf(
            \Modules\LlmConnector\Api\LlmConnectorInterface::class,
            $with->getOptional(\Modules\LlmConnector\Api\LlmConnectorInterface::class)
        );
        $this->assertNull($without->getOptional(\Modules\LlmConnector\Api\LlmConnectorInterface::class));
    }

    public function testTheFinanceAndInboundMailCapabilitiesResolveWhenEnabled(): void
    {
        ['context' => $context] = $this->bootstrap(['finance', 'inbound_mail']);

        $this->assertInstanceOf(
            \Modules\Finance\Api\ExpectedReceivableInterface::class,
            $context->getOptional(\Modules\Finance\Api\ExpectedReceivableInterface::class)
        );
        $this->assertInstanceOf(
            \Modules\Finance\Api\SepaQrCodeInterface::class,
            $context->getOptional(\Modules\Finance\Api\SepaQrCodeInterface::class)
        );
        $this->assertInstanceOf(
            \Modules\InboundMail\Api\InboundMailInterface::class,
            $context->getOptional(\Modules\InboundMail\Api\InboundMailInterface::class)
        );
    }

    public function testTheRetroBoardCreationCapabilityResolvesExactlyWhenTheModuleIsEnabled(): void
    {
        ['context' => $with] = $this->bootstrap(['retro']);
        ['context' => $without] = $this->bootstrap([]);

        $this->assertInstanceOf(
            \Modules\Retro\Api\RetroBoardCreationInterface::class,
            $with->getOptional(\Modules\Retro\Api\RetroBoardCreationInterface::class)
        );
        $this->assertNull($without->getOptional(\Modules\Retro\Api\RetroBoardCreationInterface::class));
    }

    public function testIsModuleEnabledAnswersFromTheLiveRegistry(): void
    {
        ['context' => $context] = $this->bootstrap(['retro']);

        $this->assertTrue($context->isModuleEnabled('retro'));
        $this->assertFalse($context->isModuleEnabled('finance'));
    }

    public function testTheSyncFactoryBuildsTheRegistryWithRentalFirstAndCampsLast(): void
    {
        ['runner' => $runner, 'context' => $context] = $this->bootstrap(['inbound_mail', 'rental', 'camps', 'llm_connector']);

        $factories = (new \ReflectionProperty(SchedulerRunner::class, 'handlerFactories'))->getValue($runner);
        $key = 'inbound_mail::' . \Modules\InboundMail\Task\SyncMailboxesHandler::TASK_KEY;
        $this->assertArrayHasKey($key, $factories);

        $handler = ($factories[$key])($context);
        $this->assertInstanceOf(\Modules\InboundMail\Task\SyncMailboxesHandler::class, $handler);

        $registry = (new \ReflectionProperty(\Modules\InboundMail\Task\SyncMailboxesHandler::class, 'consumerRegistry'))
            ->getValue($handler);
        $this->assertNotNull($registry);
        $consumers = (new \ReflectionProperty(\Modules\InboundMail\Service\MessageConsumerRegistry::class, 'consumers'))
            ->getValue($registry);

        $this->assertCount(2, $consumers);
        $this->assertInstanceOf(\Modules\Rental\Mail\RentalMessageConsumer::class, $consumers[0]);
        // Last, and load-bearing: first-claim-wins, and a dedicated camps
        // mailbox claims everything it is offered.
        $this->assertInstanceOf(\Modules\Camps\Mail\CampsMessageConsumer::class, $consumers[1]);
    }

    public function testTheSyncFactoryIsNotRegisteredWhenInboundMailIsDisabled(): void
    {
        ['runner' => $runner] = $this->bootstrap(['rental', 'camps']);

        $factories = (new \ReflectionProperty(SchedulerRunner::class, 'handlerFactories'))->getValue($runner);

        // The RGPD generator is core's, and core is always here: it is a
        // factory for the same reason inbound mail's sync is (an
        // expensive graph no web request should pay for), not because it
        // depends on a module.
        $this->assertSame(
            ['core::' . \Core\View\Task\GenerateRgpdContentHandler::TASK_KEY],
            array_keys($factories)
        );
    }

    /**
     * The RGPD document is generated by a scheduled task, so it must be
     * runnable from BOTH triggers — the §8.17 failure this whole file
     * exists to prevent was a core handler registered on one and not the
     * other.
     */
    public function testTheRgpdGeneratorIsRegisteredWhateverModulesAreEnabled(): void
    {
        foreach ([[], ['llm_connector'], ['llm_connector', 'gallery']] as $enabled) {
            ['runner' => $runner] = $this->bootstrap($enabled);

            $factories = (new \ReflectionProperty(SchedulerRunner::class, 'handlerFactories'))->getValue($runner);

            $this->assertArrayHasKey(
                'core::' . \Core\View\Task\GenerateRgpdContentHandler::TASK_KEY,
                $factories,
                'modules actifs : ' . (implode(', ', $enabled) ?: 'aucun')
            );
        }
    }
}
