<?php

/*
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Tests\Modules\OfficialDocuments;

use Modules\OfficialDocuments\Task\PurgeHealthSheetsHandler;
use PHPUnit\Framework\TestCase;

/**
 * Declaring a scheduled task in `module.json` does **not** queue it.
 *
 * `ModuleManager::registerModule()` reads `scheduled_tasks` only to teach
 * SchedulerRunner which class handles which key; nothing there ever writes
 * a row into `scheduled_actions`. Every recurring task in this codebase
 * reschedules itself at the end of its own run, so one whose *first*
 * occurrence was never seeded in `public/index.php` reschedules itself
 * never and simply does not exist (ARCHITECTURE.md §8.49 — the mistake
 * support_dashboard records, where two features looked implemented and
 * tested because both *were*, and nothing ever called them).
 *
 * **What that would cost here is the whole of the retention promise.** The
 * privacy notice tells families their child's health data is erased after
 * eighteen months without use. A purge that never runs makes that sentence
 * false on every installation, indefinitely, with no symptom anybody could
 * notice: nothing appears, nothing breaks, the data simply stays.
 *
 * Asserted at the source level, like `Tests\Core\CronEntryPointTest`:
 * booting index.php in-process would pull in the full service graph and a
 * live database, and the property worth pinning is textual anyway.
 */
final class ModuleSchedulingTest extends TestCase
{
    private const MODULE_ID = 'official_documents';

    private string $index;

    /** @var array<int, array{key: string, handler: string}> */
    private array $declaredTasks;

    protected function setUp(): void
    {
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

    public function testTheManifestStillDeclaresTheTaskThisModuleNeeds(): void
    {
        $keys = array_map(static fn(array $task): string => $task['key'], $this->declaredTasks);
        sort($keys);

        $this->assertSame([PurgeHealthSheetsHandler::TASK_KEY], $keys);
    }

    /**
     * The regression this file exists for: every declared handler has its
     * first occurrence seeded, or the task never runs at all.
     */
    public function testEveryDeclaredTaskHasItsFirstOccurrenceSeededInTheCompositionRoot(): void
    {
        $block = $this->moduleBlockOfTheCompositionRoot();

        foreach ($this->declaredTasks as $task) {
            /** @var class-string $handler */
            $handler = $task['handler'];
            $this->assertTrue(class_exists($handler), $handler . ' does not exist');

            $shortName = substr((string) strrchr($handler, '\\'), 1);

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
     * Seeded only when there is no occurrence yet — otherwise every page
     * load of the site would queue another copy. `SchedulerService::rearm()`
     * is that guard.
     */
    public function testSeedingIsConditionalOnThereBeingNoOccurrenceYet(): void
    {
        $this->assertStringContainsString(
            '$schedulerService->rearm(',
            $this->moduleBlockOfTheCompositionRoot()
        );
    }

    /**
     * Daily, and rescheduling itself in a `finally`.
     *
     * A handler that forgot to reschedule would run exactly once, which is
     * the same silent death as never being seeded; one that rescheduled
     * only on success would stop for good on the first database hiccup.
     */
    public function testTheHandlerReschedulesItselfDailyWhateverHappens(): void
    {
        foreach ($this->declaredTasks as $task) {
            /** @var class-string $handler */
            $handler = $task['handler'];

            $this->assertSame(86400, $handler::INTERVAL_SECONDS, $handler . ' is not daily');
            $this->assertSame('daily', $handler::REFERENCE, $handler . ' does not use the daily reference');

            $source = (string) file_get_contents((string) (new \ReflectionClass($handler))->getFileName());

            // rearmAfter(), not scheduleAfter(): the guarded form is the
            // only acceptable one for a recurring chain — see
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
     * The seeding block, isolated so a `TASK_KEY` mentioned somewhere else
     * entirely in an 11 000-line composition root cannot satisfy the
     * assertions above.
     */
    private function moduleBlockOfTheCompositionRoot(): string
    {
        $start = strpos($this->index, "\$isEnabled('" . self::MODULE_ID . "')");
        $this->assertIsInt($start, 'public/index.php no longer wires ' . self::MODULE_ID . ' at all.');

        $end = strpos($this->index, "\$isEnabled('presences')", $start);
        $this->assertIsInt($end, 'The ' . self::MODULE_ID . ' block is no longer followed by the presences block.');

        return substr($this->index, $start, $end - $start);
    }
}
