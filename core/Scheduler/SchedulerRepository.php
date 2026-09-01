<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

class SchedulerRepository
{
    public function __construct(private \PDO $pdo)
    {
    }

    public function create(
        string $moduleId,
        string $taskKey,
        string $runAt,
        ?string $payload,
        ?string $reference,
        ?int $requestedByUserAccountId = null
    ): int {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO scheduled_actions (module_id, task_key, run_at, payload, reference, requested_by_user_account_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$moduleId, $taskKey, $runAt, $payload, $reference, $requestedByUserAccountId, $now]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Every LIVE row's (module_id, task_key, reference) triple **and its
     * status**, for SchedulerService's opt-in guard cache — the live set
     * is small (one row per recurring task plus whatever one-shots are
     * queued).
     *
     * `processing` is in it, and that is the whole point: the two guards
     * built on this cache need different answers. A handler re-arming
     * itself must not see its own claimed row (or the chain ends after
     * one run); a caller SEEDING a chain must, or it queues a duplicate
     * of a task that is running right now. See SchedulerService::rearm()
     * and ::seed().
     *
     * @return array<int, array{module_id: string, task_key: string, reference: string|null, status: string}>
     */
    public function findLiveKeys(): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT module_id, task_key, reference, status FROM scheduled_actions
             WHERE status IN ('pending', 'processing')"
        );
        $stmt->execute();
        /** @var array<int, array{module_id: string, task_key: string, reference: string|null, status: string}> */
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Whether this chain is alive: a row of it is queued or running.
     *
     * The twin of findByModuleAndKey() for a caller that seeds rather
     * than re-arms — see SchedulerService::seed().
     */
    public function hasLive(string $moduleId, string $taskKey, string $reference): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM scheduled_actions
             WHERE module_id = ? AND task_key = ? AND reference = ?
               AND status IN ('pending', 'processing') LIMIT 1"
        );
        $stmt->execute([$moduleId, $taskKey, $reference]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Collapse a chain back to a single queued row, keeping the one that
     * runs first.
     *
     * Rows sharing a (module, task, reference) triple ARE the same chain
     * — that is what a reference means — so a second queued copy is a
     * duplicate run and nothing else. Deleting rather than letting them
     * drain: an installation that accumulated sixteen thousand of them
     * would otherwise spend days running no-ops and writing a journal
     * line for each.
     *
     * @return int how many duplicates were removed
     */
    public function collapsePending(string $moduleId, string $taskKey, string $reference): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT id FROM scheduled_actions
             WHERE module_id = ? AND task_key = ? AND reference = ? AND status = 'pending'
             ORDER BY run_at ASC, id ASC"
        );
        $stmt->execute([$moduleId, $taskKey, $reference]);
        $ids = $stmt->fetchAll(\PDO::FETCH_COLUMN);
        if (count($ids) < 2) {
            return 0;
        }

        // The earliest keeps the chain's own cadence: dropping it and
        // keeping a later copy would silently postpone the task.
        array_shift($ids);

        $deleted = 0;
        // Deleted one at a time, and only while still `pending`: a pass
        // that claims a row between the SELECT above and the DELETE below
        // must not have it removed from under it.
        $delete = $this->pdo->prepare("DELETE FROM scheduled_actions WHERE id = ? AND status = 'pending'");
        foreach ($ids as $id) {
            $delete->execute([(int) $id]);
            $deleted += $delete->rowCount();
        }

        return $deleted;
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
     * Atomically claim overdue tasks for processing — safe under concurrent
     * callers (two crontab passes overlapping, or a pass racing anything
     * else that turns the queue). The previous implementation ran a
     * blanket "SET status = processing WHERE status = pending" UPDATE and
     * then re-SELECTed *every* row currently in 'processing' state — which
     * hands back rows a concurrent call just claimed too, since the SELECT
     * has no way to tell "processing because I just claimed it" from
     * "processing because another caller did a moment ago". Two callers
     * could then both run Task\InstallUpdateHandler for the very same
     * install, each copying the extracted archive over the live install
     * directory at the same time — exactly the kind of interleaved partial
     * write that once left a production site with a route registered in
     * module.json but the controller method it pointed to missing, because
     * one copy's file tree and the other's got interleaved mid-copy.
     *
     * Fixed by claiming one candidate row at a time via its own
     * `WHERE id = ? AND status = 'pending'` UPDATE and checking
     * `rowCount()` — only a row THIS call's UPDATE actually flipped from
     * pending to processing is counted as claimed by it; a row a
     * concurrent caller already flipped a moment earlier simply reports
     * zero affected rows here and is left for that other caller.
     *
     * @return array<int, array<string, mixed>>
     */
    public function claimOverdue(): array
    {
        $now = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $candidateStmt = $this->pdo->prepare(
            "SELECT id FROM scheduled_actions WHERE status = 'pending' AND run_at <= ?"
        );
        $candidateStmt->execute([$now]);
        $candidateIds = $candidateStmt->fetchAll(\PDO::FETCH_COLUMN);

        if ($candidateIds === []) {
            return [];
        }

        $claimStmt = $this->pdo->prepare(
            "UPDATE scheduled_actions SET status = 'processing' WHERE id = ? AND status = 'pending'"
        );
        $claimedIds = [];
        foreach ($candidateIds as $id) {
            $claimStmt->execute([(int) $id]);
            if ($claimStmt->rowCount() === 1) {
                $claimedIds[] = (int) $id;
            }
        }

        if ($claimedIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($claimedIds), '?'));
        $stmt = $this->pdo->prepare("SELECT * FROM scheduled_actions WHERE id IN ({$placeholders})");
        $stmt->execute($claimedIds);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Hand a claimed row back to the pending pool, untouched — the counter
     * part of claimOverdue()'s claim.
     *
     * claimOverdue() flips every overdue row to 'processing' in one go,
     * and nothing ever re-claims a 'processing' row (the claim filters on
     * 'pending'). So a caller that stops early — SchedulerRunner running
     * out of its time budget with tasks still queued — MUST release what
     * it claimed and did not run, or those tasks are stranded in
     * 'processing' forever, invisible to every later pass. Releasing is
     * not a failure: attempts is not incremented and last_error is not
     * written, because nothing was attempted.
     */
    public function release(int $id): void
    {
        $stmt = $this->pdo->prepare(
            "UPDATE scheduled_actions SET status = 'pending' WHERE id = ? AND status = 'processing'"
        );
        $stmt->execute([$id]);
    }

    /**
     * How many tasks are due right now — the cheap "is there still work?"
     * question, without claiming anything. Counts only 'pending' rows, so a
     * task another process is currently running is not work this one can
     * do. Its production caller was the self-continuation chain, which is
     * gone; what is left is the queue-state assertion the scheduler's own
     * tests make (Tests\Core\Scheduler\SchedulerPassTest).
     */
    public function countOverdue(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM scheduled_actions WHERE status = 'pending' AND run_at <= ?"
        );
        $stmt->execute([(new \DateTimeImmutable())->format('Y-m-d H:i:s')]);

        return (int) $stmt->fetchColumn();
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
     * Remove every scheduled action of one module/task key, whatever its
     * status or date — what a module does when a task **stops existing**.
     *
     * Different from `deleteOlderThan()`, which is a retention purge on a
     * task that is still alive. A pending occurrence of a handler class
     * that no longer exists resolves to nothing on every tick, forever:
     * not fatal, and not visible either, which is the worse half.
     */
    public function deleteByTaskKey(string $moduleId, string $taskKey): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM scheduled_actions WHERE module_id = ? AND task_key = ?'
        );
        $stmt->execute([$moduleId, $taskKey]);

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
