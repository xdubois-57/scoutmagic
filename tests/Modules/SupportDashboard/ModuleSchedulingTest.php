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
 *
 * **Two kinds of task live in that manifest, and they fail in opposite
 * ways.** A RECURRING one (`INTERVAL_SECONDS` on its handler) must be
 * seeded once in the composition root and must reschedule itself, or it
 * dies silently. An ON-DEMAND one — `snapshot_ticket_dns`, queued per
 * ticket since issue #198 — must do neither: seeding it would queue a
 * zone read on every page load of the receiver, for a ticket that does
 * not exist. Which kind a handler is, is read off the handler rather than
 * listed here, so a new task cannot be filed under the wrong one.
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

    public function testTheManifestStillDeclaresTheTasksThisModuleNeeds(): void
    {
        $keys = array_map(static fn(array $task): string => $task['key'], $this->declaredTasks);
        sort($keys);

        $this->assertSame(
            [
                'finalize_monthly_aggregate',
                'purge_installations',
                'purge_rate_limits',
                'purge_tickets',
                'snapshot_ticket_dns',
            ],
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

        foreach ($this->tasksOfKind(recurring: true) as $task) {
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
        foreach ($this->tasksOfKind(recurring: true) as $task) {
            /** @var class-string $handler */
            $handler = $task['handler'];
            $this->assertTrue(class_exists($handler), $handler . ' does not exist');

            $this->assertSame(86400, $handler::INTERVAL_SECONDS, $handler . ' is not daily');
            $this->assertSame('daily', $handler::REFERENCE, $handler . ' does not use the daily reference');

            $source = (string) file_get_contents((string) (new \ReflectionClass($handler))->getFileName());
            // rearmAfter(), not scheduleAfter(): the guarded form is now
            // the only acceptable one for a recurring chain — see
            // Tests\Architecture\RecurringTasksRearmTest for what the
            // unguarded one did to a production journal.
            $this->assertStringContainsString(
                'rearmAfter(',
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
     * The opposite invariant, for the other kind of task.
     *
     * An on-demand task is queued by the thing that needs it — one row per
     * ticket — so seeding it in the composition root would queue a zone
     * read on every page load of the receiver, for a ticket that does not
     * exist. And something has to queue it, or it is as dead as an
     * unseeded recurring one.
     */
    public function testAnOnDemandTaskIsQueuedByTheModuleAndNeverSeeded(): void
    {
        $block = $this->supportDashboardBlock();
        $moduleSource = $this->moduleSource();

        $onDemand = $this->tasksOfKind(recurring: false);
        $this->assertNotSame([], $onDemand, 'this module has an on-demand task; the invariant must have one to check');

        foreach ($onDemand as $task) {
            $shortName = substr((string) strrchr($task['handler'], '\\'), 1);

            $this->assertStringNotContainsString(
                $shortName . '::TASK_KEY',
                $block,
                sprintf(
                    'public/index.php seeds %s/%s, which is queued per event: seeding it would queue one on '
                    . 'every page load of the receiver.',
                    self::MODULE_ID,
                    $task['key']
                )
            );

            $this->assertStringContainsString(
                $shortName . '::TASK_KEY',
                $moduleSource,
                sprintf(
                    'Nothing in %s ever queues %s, so the task can never run.',
                    self::MODULE_ID,
                    $task['key']
                )
            );
        }
    }

    /**
     * Declared tasks of one kind, decided by the handler itself: a
     * recurring one carries the interval it reschedules itself at.
     *
     * @return array<int, array{key: string, handler: string}>
     */
    private function tasksOfKind(bool $recurring): array
    {
        return array_values(array_filter(
            $this->declaredTasks,
            function (array $task) use ($recurring): bool {
                /** @var class-string $handler */
                $handler = $task['handler'];
                $this->assertTrue(class_exists($handler), $handler . ' does not exist');

                return defined($handler . '::INTERVAL_SECONDS') === $recurring;
            }
        ));
    }

    /** Every PHP file of the module, concatenated. */
    private function moduleSource(): string
    {
        $source = '';

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/modules/' . self::MODULE_ID . '/src')
        );

        foreach ($files as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'php') {
                $source .= (string) file_get_contents((string) $file->getRealPath());
            }
        }

        return $source;
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
