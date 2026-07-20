<?php

declare(strict_types=1);

namespace Core\Scheduler;

class SchedulerRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(string $moduleId, string $taskKey, string $runAt, ?string $payload, ?string $reference): int
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, run_at, payload, reference, created_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$moduleId, $taskKey, $runAt, $payload, $reference, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM scheduled_actions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByModuleAndKey(string $moduleId, string $taskKey, ?string $reference): ?array
    {
        if ($reference !== null) {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM scheduled_actions WHERE module_id = ? AND task_key = ? AND reference = ? AND status = ? ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute([$moduleId, $taskKey, $reference, 'pending']);
        } else {
            $stmt = $this->pdo->prepare(
                'SELECT * FROM scheduled_actions WHERE module_id = ? AND task_key = ? AND reference IS NULL AND status = ? ORDER BY created_at DESC LIMIT 1'
            );
            $stmt->execute([$moduleId, $taskKey, 'pending']);
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * All scheduled actions for a given module/task key, any status,
     * newest run_at first — for a module's own "planned actions" page (e.g.
     * the SOS module's list of upcoming/past redirect changes), unlike
     * findByModuleAndKey() which only returns a single pending row by
     * reference.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByModuleAndTaskKey(string $moduleId, string $taskKey, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scheduled_actions WHERE module_id = ? AND task_key = ? ORDER BY run_at DESC LIMIT ?'
        );
        $stmt->bindValue(1, $moduleId);
        $stmt->bindValue(2, $taskKey);
        $stmt->bindValue(3, $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Atomically claim overdue tasks for processing.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimOverdue(): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        // Update pending tasks that are due
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_actions SET status = 'processing' WHERE status = 'pending' AND run_at <= ?"
        );
        $stmt->execute([$now]);

        // Fetch the claimed tasks
        $stmt = $this->pdo->prepare(
            "SELECT * FROM scheduled_actions WHERE status = 'processing'"
        );
        $stmt->execute();
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function markDone(int $id): void
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_actions SET status = 'done', executed_at = ? WHERE id = ?"
        );
        $stmt->execute([$now, $id]);
    }

    public function markFailed(int $id, string $error): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_actions SET status = 'failed', last_error = ?, attempts = attempts + 1 WHERE id = ?"
        );
        $stmt->execute([$error, $id]);
    }

    public function cancel(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_actions SET status = 'canceled' WHERE id = ?"
        );
        $stmt->execute([$id]);
    }

    /**
     * Purge old scheduled actions (any status) for a module/task key, run_at
     * before the cutoff — used by modules whose own retention cleanup needs
     * to also drop the scheduled_actions rows it created (e.g. the SOS
     * module's >1 year purge, module spec §6). Mirrors
     * JournalService::cleanup()'s retention-purge pattern.
     */
    public function deleteOlderThan(string $moduleId, string $taskKey, string $cutoffRunAt): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM scheduled_actions WHERE module_id = ? AND task_key = ? AND run_at < ?'
        );
        $stmt->execute([$moduleId, $taskKey, $cutoffRunAt]);
        return $stmt->rowCount();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findAll(int $limit = 100, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM scheduled_actions ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $stmt->execute([$limit, $offset]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    public function countAll(): int
    {
        $stmt = $this->pdo->query('SELECT COUNT(*) FROM scheduled_actions');
        return (int) $stmt->fetchColumn();
    }
}
