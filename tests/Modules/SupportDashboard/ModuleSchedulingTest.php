<?php

declare(strict_types=1);

namespace Tests\Modules\SupportDashboard;

use PHPUnit\Framework\TestCase;

/**
 * Declaring a scheduled task in `module.json` does **not** queue it.
 *
 * `ModuleManager::registerModule()` reads `scheduled_tasks` only to teach
 * SchedulerRunner which class handles which key; nothing there ever writes
 * a row into `scheduled_actions`. Every recurring task in this codebase
 * reschedules itself at the end of its own run, which means a task whose
 * *first* occurrence was never seeded in `public/index.php` reschedules
 * itself never and simply does not exist.
 *
 * That is exactly what had happened here: of this module's three declared
 * tasks only `purge_rate_limits` was seeded, so `purge_installations` (the
 * retention rule of ARCHITECTURE.md §8.50 — installation id, URL, payload
 * and credential hash deleted after `support_retention_months`) and
 * `finalize_monthly_aggregate` (the whole of §8.51) had never run once on
 * any receiver. Both features looked implemented and tested, because both
 * *were* implemented and tested — nothing simply ever called them.
 *
 * Asserted at the source level, like Tests\Core\CronEntryPointTest: booting
 * index.php in-process would pull in the full service graph and a live
 * database, and the property worth pinning is textual anyway.
 */
class ModuleSchedulingTest extends TestCase
{
    private const MODULE_ID = 'support_dashboard';

    private string $index;

    /** @var array<int, array{key: string, handler: string}> */
    private array $declaredTasks;

    protected function setUp(): void
    {
        SupportDashboardTestHelper::ensureAutoloadable();

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

    public function testTheManifestStillDeclaresTheThreeTasksThisModuleNeeds(): void
    {
        $keys = array_map(static fn(array $task): string => $task['key'], $this->declaredTasks);
        sort($keys);

        $this->assertSame(
            ['finalize_monthly_aggregate', 'purge_installations', 'purge_rate_limits'],
            $keys
        );
    }

    /**
     * The regression this file exists for. Every declared handler must have
     * its first occurrence seeded, or the task never runs at all.
     */
    public function testEveryDeclaredTaskHasItsFirstOccurrenceSeededInTheCompositionRoot(): void
    {
        $block = $this->supportDashboardBlock();

        foreach ($this->declaredTasks as $task) {
            $shortName = substr((string) strrchr($task['handler'], '\\'), 1);

            $this->assertStringContainsString(
                $shortName . '::TASK_KEY',
                $block,
                sprintf(
                    'public/index.php never seeds a first occurrence of %s/%s, so the task can never run: '
                    . 'declaring a handler in module.json only registers the class, it queues nothing.',
                    self::MODULE_ID,
                    $task['key']
                )
            );

            $this->assertStringContainsString(
                $shortName . '::REFERENCE',
                $block,
                sprintf('The seeding of %s must use the handler\'s own REFERENCE constant.', $task['key'])
            );
        }
    }

    /**
     * A seeded occurrence is only ever added when there is none — otherwise
     * every page load of the receiver would queue another copy of all three
     * tasks.
     *
     * `SchedulerService::rearm()` is that guard, and holding it in one
     * place is why this now checks for the call rather than for a
     * hand-written `find() === null` around a `schedule()`.
     */
    public function testSeedingIsConditionalOnThereBeingNoOccurrenceYet(): void
    {
        $this->assertStringContainsString('$schedulerService->rearm(', $this->supportDashboardBlock());
    }

    /**
     * Each handler declares the interval it reschedules itself at, and all
     * three are daily. A handler that forgot to reschedule itself would run
     * exactly once, which is the same silent death as never being seeded.
     */
    public function testEveryHandlerReschedulesItselfDaily(): void
    {
        foreach ($this->declaredTasks as $task) {
            /** @var class-string $handler */
            $handler = $task['handler'];
            $this->assertTrue(class_exists($handler), $handler . ' does not exist');

            $this->assertSame(86400, $handler::INTERVAL_SECONDS, $handler . ' is not daily');
            $this->assertSame('daily', $handler::REFERENCE, $handler . ' does not use the daily reference');

            $source = (string) file_get_contents((string) (new \ReflectionClass($handler))->getFileName());
            $this->assertStringContainsString(
                'scheduleAfter(',
                $source,
                $handler . ' never reschedules itself, so it would run exactly once.'
            );
            $this->assertStringContainsString(
                'finally',
                $source,
                $handler . ' must reschedule in a finally: a run that threw must not be a task that stops.'
            );
        }
    }

    /**
     * The seeding block, isolated so a `TASK_KEY` mentioned somewhere else
     * entirely in a 3000-line composition root cannot satisfy the assertion
     * above.
     */
    private function supportDashboardBlock(): string
    {
        $start = strpos($this->index, "\$isEnabled('" . self::MODULE_ID . "')");
        $this->assertIsInt($start, 'public/index.php no longer wires ' . self::MODULE_ID . ' at all.');

        $end = strpos($this->index, "\$isEnabled('retro')", $start);
        $this->assertIsInt($end, 'The ' . self::MODULE_ID . ' block is no longer followed by the retro block.');

        return substr($this->index, $start, $end - $start);
    }
}
