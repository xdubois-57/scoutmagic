<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Module\ModuleManager;
use Core\Scheduler\CoreTaskHandlers;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;
use Core\Support\SupportSpreadsheet;

/**
 * `scheduled-tasks.xlsx` — one row per **declared handler**, core and
 * module alike, with the last instance that ran and the next one pending.
 *
 * The unit of interest is the handler, not the row: `scheduled_actions` is
 * a queue of instances, so a task that has never run has no row at all —
 * and "this task has never run once" is exactly the kind of answer this
 * sheet exists to give. Core handlers come from Core\Scheduler\
 * CoreTaskHandlers, module handlers from each manifest's `scheduled_tasks`.
 *
 * There is deliberately **no cadence and no enabled/disabled column**:
 * neither concept exists in this scheduler (recurring tasks reschedule
 * themselves at the end of each run), and inventing columns for them would
 * describe a model the code does not have. Equally deliberately, there is
 * no full execution history — the last run and the next slot answer the
 * question; a thousand rows of past runs do not.
 */
class ScheduledTasksCollector implements SupportCollectorInterface
{
    private const ERROR_MAX_LENGTH = 300;

    public function __construct(private ?ModuleManager $moduleManager = null)
    {
    }

    public function name(): string
    {
        return 'scheduled_tasks';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $rows = [];

        foreach (CoreTaskHandlers::all() as $taskKey => $handlerClass) {
            $rows[] = $this->row($context, 'core', $taskKey, $handlerClass);
        }

        foreach ($this->moduleTasks() as [$moduleId, $taskKey, $handlerClass]) {
            $rows[] = $this->row($context, $moduleId, $taskKey, $handlerClass);
        }

        $context->addFileFromContent(
            'scheduled-tasks.xlsx',
            SupportSpreadsheet::build(
                [
                    'Source', 'Clé de tâche', 'Classe du handler',
                    'Dernière exécution — prévue le', 'Dernière exécution — exécutée le',
                    'Dernière exécution — statut', 'Dernière exécution — tentatives',
                    'Dernière exécution — erreur',
                    'Prochaine instance — prévue le', 'Prochaine instance — référence',
                ],
                $rows,
                'Tâches planifiées'
            )
        );
    }

    /**
     * @return array<int, array{0: string, 1: string, 2: string}>
     */
    private function moduleTasks(): array
    {
        if ($this->moduleManager === null) {
            return [];
        }

        $tasks = [];
        foreach ($this->moduleManager->discoverModules() as $module) {
            foreach ($module->manifest->scheduledTasks as $task) {
                $tasks[] = [$module->manifest->id, (string) $task['key'], (string) $task['handler']];
            }
        }

        return $tasks;
    }

    /**
     * @return array<int, string>
     */
    private function row(SupportCollectorContext $context, string $source, string $taskKey, string $handlerClass): array
    {
        $last = $this->lastExecuted($context, $source, $taskKey);
        $next = $this->nextPending($context, $source, $taskKey);

        return [
            $source,
            $taskKey,
            $handlerClass,
            self::asString($last['run_at'] ?? null),
            self::asString($last['executed_at'] ?? null),
            self::asString($last['status'] ?? null),
            self::asString($last['attempts'] ?? null),
            $context->redact(self::asString($last['last_error'] ?? null), self::ERROR_MAX_LENGTH),
            self::asString($next['run_at'] ?? null),
            self::asString($next['reference'] ?? null),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastExecuted(SupportCollectorContext $context, string $moduleId, string $taskKey): array
    {
        $stmt = $context->pdo()->prepare(
            "SELECT run_at, executed_at, status, attempts, last_error
             FROM scheduled_actions
             WHERE module_id = ? AND task_key = ? AND status NOT IN ('pending')
             ORDER BY COALESCE(executed_at, run_at) DESC, id DESC
             LIMIT 1"
        );
        $stmt->execute([$moduleId, $taskKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function nextPending(SupportCollectorContext $context, string $moduleId, string $taskKey): array
    {
        $stmt = $context->pdo()->prepare(
            "SELECT run_at, reference
             FROM scheduled_actions
             WHERE module_id = ? AND task_key = ? AND status = 'pending'
             ORDER BY run_at ASC, id ASC
             LIMIT 1"
        );
        $stmt->execute([$moduleId, $taskKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return is_array($row) ? $row : [];
    }

    private static function asString(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
