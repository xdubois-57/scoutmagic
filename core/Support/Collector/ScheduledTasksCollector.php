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
use Core\Export\TabularSpreadsheet;

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
 * no full execution history — the last run, the next slot and the counts
 * answer the question; a thousand rows of past runs do not.
 *
 * The counts are the newest columns and the least obvious. A scheduler
 * whose tasks re-arm themselves can run one of them far too often without
 * anything else on this sheet looking wrong — see volume().
 */
class ScheduledTasksCollector implements SupportCollectorInterface
{
    private const ERROR_MAX_LENGTH = 300;

    /** Same window as the event journal, so the two can be read together. */
    private const VOLUME_WINDOW_HOURS = 48;

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
            TabularSpreadsheet::build(
                [
                    'Source', 'Clé de tâche', 'Classe du handler',
                    'Dernière exécution — prévue le', 'Dernière exécution — exécutée le',
                    'Dernière exécution — statut', 'Dernière exécution — tentatives',
                    'Dernière exécution — erreur',
                    'Prochaine instance — prévue le', 'Prochaine instance — référence',
                    'Exécutions sur 48 h', 'Échecs sur 48 h', 'En attente', 'Instances enregistrées',
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
        $volume = $this->volume($context, $source, $taskKey);

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
            (string) $volume['recent'],
            (string) $volume['failed'],
            (string) $volume['pending'],
            (string) $volume['total'],
        ];
    }

    /**
     * How often this task actually runs, next to what it is supposed to do.
     *
     * "Last run" answers whether a task works. It cannot answer whether one
     * is running far too often, and that is a real failure mode of a
     * scheduler whose recurring tasks re-arm themselves: a task seeded as
     * if it were periodic re-queues itself for ever, does nothing each
     * time, and looks perfectly healthy in every other column. One did —
     * 277 no-op runs in ten hours, a third of the event journal — and this
     * sheet showed a single tidy "done" for it.
     *
     * A count over the same 48 hours as the journal makes it obvious: a
     * daily task reads 1 or 2, an hourly one around 48, and anything in
     * the hundreds is re-arming itself in a loop. `pending` catches the
     * opposite fault — a queue nothing is draining, on an installation
     * whose scheduler never fires.
     *
     * @return array{recent: int, failed: int, pending: int, total: int}
     */
    private function volume(SupportCollectorContext $context, string $moduleId, string $taskKey): array
    {
        $cutoff = (new \DateTimeImmutable('-' . self::VOLUME_WINDOW_HOURS . ' hours'))->format('Y-m-d H:i:s');

        $stmt = $context->pdo()->prepare(
            "SELECT
                 SUM(CASE WHEN COALESCE(executed_at, run_at) >= ? THEN 1 ELSE 0 END) AS recent,
                 SUM(CASE WHEN status = 'failed' AND COALESCE(executed_at, run_at) >= ? THEN 1 ELSE 0 END) AS failed,
                 SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                 COUNT(*) AS total
             FROM scheduled_actions
             WHERE module_id = ? AND task_key = ?"
        );
        $stmt->execute([$cutoff, $cutoff, $moduleId, $taskKey]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'recent' => (int) (is_array($row) ? ($row['recent'] ?? 0) : 0),
            'failed' => (int) (is_array($row) ? ($row['failed'] ?? 0) : 0),
            'pending' => (int) (is_array($row) ? ($row['pending'] ?? 0) : 0),
            'total' => (int) (is_array($row) ? ($row['total'] ?? 0) : 0),
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
