<?php

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Journal\JournalService;
use Core\Module\ModuleManager;

class SchedulerRunner
{
    /** @var array<string, TaskHandlerInterface> */
    private array $handlers = [];

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
     * Process all due tasks.
     */
    public function processOverdue(): int
    {
        $tasks = $this->repository->claimOverdue();
        $processed = 0;

        foreach ($tasks as $task) {
            $handlerKey = $task['module_id'] . '::' . $task['task_key'];
            $handler = $this->handlers[$handlerKey] ?? null;

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
                    "Scheduled task '{$task['task_key']}' failed: no handler registered",
                    ['task_id' => $task['id'], 'module_id' => $task['module_id']]
                );
                continue;
            }

            try {
                $payload = $task['payload'] !== null ? json_decode($task['payload'], true) : [];
                $context = $this->taskContext ?? $this->createFallbackContext();
                $handler->handle(is_array($payload) ? $payload : [], $context);
                $this->repository->markDone((int) $task['id']);
                $processed++;

                $this->journal->log(
                    'core',
                    'scheduler_task_done',
                    'info',
                    "Scheduled task '{$task['task_key']}' completed",
                    ['task_id' => $task['id'], 'module_id' => $task['module_id']]
                );
            } catch (\Throwable $e) {
                $this->repository->markFailed((int) $task['id'], $e->getMessage());
                $this->journal->log(
                    'core',
                    'scheduler_task_failed',
                    'info',
                    "Scheduled task '{$task['task_key']}' failed: " . $e->getMessage(),
                    ['task_id' => $task['id'], 'module_id' => $task['module_id']]
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
