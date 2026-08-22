<?php

declare(strict_types=1);

namespace Tests\Modules\TestTools;

use PHPUnit\Framework\TestCase;

/**
 * Declaring a scheduled task in `module.json` does **not** queue it.
 *
 * `ModuleManager::registerModule()` reads `scheduled_tasks` only to teach
 * SchedulerRunner which class handles which key; nothing there ever writes
 * a row into `scheduled_actions`. Every recurring task in this codebase
 * reschedules itself at the end of its own run, so a task whose *first*
 * occurrence was never seeded in `public/index.php` reschedules itself
 * never and simply does not exist.
 *
 * That exact mistake left two of `support_dashboard`'s three tasks never
 * running once on any receiver (ARCHITECTURE.md §8.49). This test is the
 * same guard for `test_tools`: it fails the moment the seeded list drifts
 * from the manifest, in either direction.
 *
 * Asserted at the source level, like
 * Tests\Modules\SupportDashboard\ModuleSchedulingTest: booting index.php
 * in-process would pull in the full service graph and a live database, and
 * the property worth pinning is textual anyway.
 */
class ModuleSchedulingTest extends TestCase
{
    private const MODULE_ID = 'test_tools';

    private string $index;

    /** @var array<int, array{key: string, handler: string}> */
    private array $declaredTasks;

    protected function setUp(): void
    {
        TestToolsTestHelper::ensureAutoloadable();

        $root = dirname(__DIR__, 3);

        $this->index = (string) file_get_contents($root . '/public/index.php');

        $manifest = json_decode(
            (string) file_get_contents($root . '/modules/' . self::MODULE_ID . '/module.json'),
            true
        );
        $this->assertIsArray($manifest);
        $this->assertIsArray($manifest['scheduled_tasks'] ?? null);

        /** @var array<int, array{key: string, handler: string}> $tasks */
        $tasks = $manifest['scheduled_tasks'];
        $this->declaredTasks = $tasks;
    }

    public function testTheManifestDeclaresTheRetentionTask(): void
    {
        $keys = array_map(static fn(array $task): string => $task['key'], $this->declaredTasks);

        $this->assertContains('purge_captured_emails', $keys);
    }

    /**
     * The drift guard, and the whole reason this file exists.
     */
    public function testEveryDeclaredTaskIsSeededInTheCompositionRoot(): void
    {
        foreach ($this->declaredTasks as $task) {
            $handler = $task['handler'];
            $shortName = substr((string) strrchr($handler, '\\'), 1);

            $this->assertStringContainsString(
                $shortName . '::TASK_KEY',
                $this->index,
                sprintf(
                    'Task "%s" is declared in module.json but its first occurrence is never seeded in '
                    . 'public/index.php, so it will never run even once.',
                    $task['key']
                )
            );
        }
    }

    public function testNothingIsSeededThatTheManifestDoesNotDeclare(): void
    {
        $declaredHandlers = array_map(
            static fn(array $task): string => substr((string) strrchr($task['handler'], '\\'), 1),
            $this->declaredTasks
        );

        preg_match_all(
            '/Modules\\\\TestTools\\\\Task\\\\(\w+)::TASK_KEY/',
            $this->index,
            $matches
        );

        foreach (array_unique($matches[1]) as $seededHandler) {
            $this->assertContains(
                $seededHandler,
                $declaredHandlers,
                sprintf(
                    'public/index.php seeds "%s" but module.json declares no handler for it, so '
                    . 'SchedulerRunner has nothing to run the queued task with.',
                    $seededHandler
                )
            );
        }
    }

    public function testEveryDeclaredHandlerClassExists(): void
    {
        foreach ($this->declaredTasks as $task) {
            $this->assertTrue(
                class_exists($task['handler']),
                'Missing handler class: ' . $task['handler']
            );
        }
    }
}
