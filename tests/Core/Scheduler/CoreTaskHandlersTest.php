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
}
