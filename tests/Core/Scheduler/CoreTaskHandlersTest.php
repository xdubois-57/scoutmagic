<?php

declare(strict_types=1);

namespace Tests\Core\Scheduler;

use Core\Journal\JournalRepository;
use Core\Journal\JournalService;
use Core\Scheduler\CoreTaskHandlers;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerRunner;
use Core\Scheduler\TaskHandlerInterface;
use PHPUnit\Framework\TestCase;
use Tests\DatabaseTestHelper;

/**
 * CoreTaskHandlers is the single declaration both entry points register
 * from — the mechanism that ended the create_backup drift (§8.17). These
 * tests pin the declaration itself before the scheduler bootstrap is
 * reworked (chantier « dépendances entre modules », IT-03).
 */
class CoreTaskHandlersTest extends TestCase
{
    public function testEveryDeclaredHandlerClassExistsAndImplementsTheInterface(): void
    {
        $all = CoreTaskHandlers::all();

        $this->assertNotEmpty($all);

        foreach ($all as $taskKey => $handlerClass) {
            $this->assertIsString($taskKey);
            $this->assertNotSame('', $taskKey);
            $this->assertTrue(class_exists($handlerClass), "{$handlerClass} (task '{$taskKey}') does not exist");
            $this->assertTrue(
                is_subclass_of($handlerClass, TaskHandlerInterface::class),
                "{$handlerClass} (task '{$taskKey}') must implement TaskHandlerInterface"
            );
        }
    }

    public function testEveryDeclaredHandlerIsConstructibleWithoutArguments(): void
    {
        // registerAll() does `new $handlerClass()`: a core handler that
        // grows a required constructor parameter breaks BOTH entry points
        // at boot. This says so at unit-test speed instead.
        foreach (CoreTaskHandlers::all() as $taskKey => $handlerClass) {
            $constructor = (new \ReflectionClass($handlerClass))->getConstructor();
            $required = $constructor === null ? 0 : $constructor->getNumberOfRequiredParameters();
            $this->assertSame(
                0,
                $required,
                "{$handlerClass} (task '{$taskKey}') must stay constructible with no arguments"
            );
        }
    }

    public function testRegisterAllRegistersExactlyTheDeclaredSetUnderTheCoreModuleId(): void
    {
        $pdo = DatabaseTestHelper::createTestDatabase();
        $runner = new SchedulerRunner(new SchedulerRepository($pdo), new JournalService(new JournalRepository($pdo)));

        CoreTaskHandlers::registerAll($runner);

        // The runner keeps its handlers private; the registration's whole
        // observable contract is the key set, read through reflection so
        // this stays a test of registerAll() and not of processOverdue().
        $property = new \ReflectionProperty(SchedulerRunner::class, 'handlers');
        /** @var array<string, TaskHandlerInterface> $registered */
        $registered = $property->getValue($runner);

        $expectedKeys = array_map(
            static fn (string $taskKey): string => 'core::' . $taskKey,
            array_keys(CoreTaskHandlers::all())
        );

        $this->assertSame($expectedKeys, array_keys($registered));

        foreach (CoreTaskHandlers::all() as $taskKey => $handlerClass) {
            $this->assertInstanceOf($handlerClass, $registered['core::' . $taskKey]);
        }
    }

    /**
     * Every core handler CLASS reaches a registration, not just every
     * declared key.
     *
     * **The three tests above all read `all()` as their source of truth**,
     * so a handler written, routed to and scheduled — but never added to
     * that list — is invisible to all of them, and to PHPStan, and to its
     * own unit tests. What it is not invisible to is the scheduler, which
     * marks the task failed with « No handler registered for core::… » on
     * its next pass, long after a screen has told the administrator the
     * thing was launched. That is §8.17's failure exactly, and it happened
     * again during the « emplacements de stockage » chantier with
     * `repatriate_from_copy`.
     *
     * So this reads the DISK instead. A class under `core/**\/Task/` that
     * implements the interface is a scheduled task by construction, and
     * has to be reachable from one of the two registration paths: the
     * declaration for the ones a bare `new` can make, or an explicit
     * factory in the scheduler bootstrap for the one that needs a graph
     * (`GenerateRgpdContentHandler`).
     */
    public function testEveryCoreTaskHandlerOnDiskIsRegisteredSomewhere(): void
    {
        $root = dirname(__DIR__, 3);
        $bootstrap = (string) file_get_contents($root . '/public/scheduler-bootstrap.php');
        $declared = CoreTaskHandlers::all();

        $found = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root . '/core'));
        foreach ($found as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            if (!str_contains(str_replace('\\', '/', $file->getPathname()), '/Task/')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen($root . '/core/'));
            $class = 'Core\\' . str_replace('/', '\\', substr($relative, 0, -4));
            if (!class_exists($class) || !is_subclass_of($class, TaskHandlerInterface::class)) {
                continue;
            }

            $registered = in_array($class, $declared, true)
                || str_contains($bootstrap, $class . '::TASK_KEY');

            $this->assertTrue(
                $registered,
                "{$class} implements TaskHandlerInterface but nothing registers it: add it to "
                    . 'CoreTaskHandlers::all(), or to a registerHandlerFactory() call in '
                    . 'public/scheduler-bootstrap.php when it cannot be built with no arguments. '
                    . 'Otherwise the scheduler answers "No handler registered" and the task never runs.'
            );
        }
    }
}
