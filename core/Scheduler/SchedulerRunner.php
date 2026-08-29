<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Debug\RequestTimeline;
use Core\Exception\UserFacingMessage;
use Core\Journal\JournalService;
use Core\Module\ModuleManager;

class SchedulerRunner
{
    /** @var array<string, TaskHandlerInterface> */
    private array $handlers = [];

    /** @var array<string, callable(TaskContext): TaskHandlerInterface> */
    private array $handlerFactories = [];

    private ?ModuleManager $moduleManager = null;
    private ?TaskContext $taskContext = null;

    public function __construct(
        private SchedulerRepository $repository,
        private JournalService $journal
    ) {
    }

    public function setModuleManager(ModuleManager $moduleManager): void
    {
        $this->moduleManager = $moduleManager;
    }

    public function setTaskContext(TaskContext $context): void
    {
        $this->taskContext = $context;
    }

    /**
     * Register a task handler for a given module/task key combination.
     */
    public function registerHandler(string $moduleId, string $taskKey, TaskHandlerInterface $handler): void
    {
        $this->handlers[$moduleId . '::' . $taskKey] = $handler;
    }

    /**
     * Register a handler LAZILY: the factory is invoked — once, with the
     * TaskContext — only when a due task actually needs it. This is what
     * lets the shared scheduler bootstrap declare a handler whose
     * dependency graph is expensive to assemble (inbound mail's consumer
     * registry spans three modules) without every web request paying that
     * construction for a task that fires a few times a day.
     *
     * Resolution order: a directly registered instance wins over a
     * factory, and a factory wins over manifest auto-resolution.
     *
     * @param callable(TaskContext): TaskHandlerInterface $factory
     */
    public function registerHandlerFactory(string $moduleId, string $taskKey, callable $factory): void
    {
        $this->handlerFactories[$moduleId . '::' . $taskKey] = $factory;
    }

    /**
     * Process all due tasks.
     */
    public function processOverdue(): int
    {
        RequestTimeline::mark('scheduler_claim_overdue_start');
        $tasks = $this->repository->claimOverdue();
        RequestTimeline::mark('scheduler_claim_overdue_done', ['task_count' => count($tasks)]);
        $processed = 0;

        foreach ($tasks as $task) {
            $handlerKey = $task['module_id'] . '::' . $task['task_key'];
            $handler = $this->handlers[$handlerKey] ?? null;
            $taskStart = microtime(true);
            RequestTimeline::mark('scheduler_task_start:' . $handlerKey, ['task_id' => $task['id']]);

            // A registered factory builds the handler on first use, with
            // the context, and the instance serves the rest of the pass.
            if ($handler === null && isset($this->handlerFactories[$handlerKey])) {
                $handler = ($this->handlerFactories[$handlerKey])($this->taskContext ?? $this->createFallbackContext());
                $this->handlers[$handlerKey] = $handler;
            }

            // Try to resolve via ModuleManager if no directly registered handler
            if ($handler === null && $this->moduleManager !== null) {
                $handlerClass = $this->moduleManager->getTaskHandler($task['module_id'], $task['task_key']);
                if ($handlerClass !== null && class_exists($handlerClass)) {
                    /** @var TaskHandlerInterface $handler */
                    $handler = new $handlerClass();
                }
            }

            if ($handler === null) {
                $this->repository->markFailed(
                    (int) $task['id'],
                    'No handler registered for ' . $handlerKey
                );
                $this->journal->log(
                    'core',
                    'scheduler_task_failed',
                    'info',
                    "Tâche planifiée « {$task['task_key']} » échouée : aucun gestionnaire enregistré",
                    ['task_id' => $task['id'], 'module_id' => $task['module_id']]
                );
                continue;
            }

            try {
                $payload = $task['payload'] !== null ? json_decode($task['payload'], true) : [];
                $payload = is_array($payload) ? $payload : [];
                // Reserved key: available to every handler regardless of
                // what it originally passed as payload, so any
                // TaskHandlerInterface can call
                // $context->notifications->notify(...) without every
                // caller of SchedulerService::schedule() having to thread
                // this through manually.
                $payload['requested_by_user_account_id'] = isset($task['requested_by_user_account_id']) && $task['requested_by_user_account_id'] !== null
                    ? (int) $task['requested_by_user_account_id']
                    : null;
                $context = $this->taskContext ?? $this->createFallbackContext();
                $handler->handle($payload, $context);
                $this->repository->markDone((int) $task['id']);
                $processed++;
                $durationMs = (int) round((microtime(true) - $taskStart) * 1000);
                RequestTimeline::mark('scheduler_task_done:' . $handlerKey, ['task_id' => $task['id'], 'duration_ms' => $durationMs]);

                $this->journal->log(
                    'core',
                    'scheduler_task_done',
                    'info',
                    "Tâche planifiée « {$task['task_key']} » terminée",
                    ['task_id' => $task['id'], 'module_id' => $task['module_id'], 'duration_ms' => $durationMs]
                );
            } catch (\Throwable $e) {
                // scheduled_actions.last_error is written here and rendered
                // much later, inside a <pre>, on Configuration > Actions
                // planifiées (config/scheduled.html.twig). This is a
                // background handler catching \Throwable, so without the
                // gate any library's own English — a PDO error naming a
                // column, an SMTP transcript — lands on that page. The
                // journal entry below still carries the real text.
                $this->repository->markFailed((int) $task['id'], UserFacingMessage::from(
                    $e,
                    "La tâche « {$task['task_key']} » a échoué. Le détail technique est dans le journal des "
                    . 'événements (Configuration > Journal).'
                ));
                $durationMs = (int) round((microtime(true) - $taskStart) * 1000);
                RequestTimeline::mark('scheduler_task_failed:' . $handlerKey, ['task_id' => $task['id'], 'duration_ms' => $durationMs]);
                $this->journal->log(
                    'core',
                    'scheduler_task_failed',
                    'info',
                    "Tâche planifiée « {$task['task_key']} » échouée : " . $e->getMessage(),
                    ['task_id' => $task['id'], 'module_id' => $task['module_id'], 'duration_ms' => $durationMs]
                );
            }
        }

        return $processed;
    }

    private function createFallbackContext(): TaskContext
    {
        // Should never be called in production — TaskContext must be set during boot
        throw new \RuntimeException('TaskContext not set on SchedulerRunner');
    }
}
