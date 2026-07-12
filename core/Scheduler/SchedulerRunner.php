<?php

declare(strict_types=1);

namespace Core\Scheduler;

use Core\Journal\JournalService;

class SchedulerRunner
{
    /** @var array<string, TaskHandlerInterface> */
    private array $handlers = [];

    public function __construct(
        private SchedulerRepository $repository,
        private JournalService $journal
    ) {
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
                $handler->handle(is_array($payload) ? $payload : []);
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
}
