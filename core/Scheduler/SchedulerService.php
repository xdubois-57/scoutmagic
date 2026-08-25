<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

class SchedulerService
{
    public function __construct(private SchedulerRepository $repository)
    {
    }

    /**
     * For a task handler, which is handed a PDO and nothing else.
     *
     * A handler that re-arms itself needs a scheduler, and every one of
     * them was writing the same two `new`s to get one.
     */
    public static function forPdo(\PDO $pdo): self
    {
        return new self(new SchedulerRepository($pdo));
    }

    /**
     * Arms a recurring task for its next run — unless one is already
     * queued under the same reference. Returns whether it scheduled.
     *
     * **This is the whole of a self-perpetuating task's bookkeeping**, and
     * it was written out by hand in eight handlers, each with its own
     * ordering of the same three steps and its own chance of getting one
     * of them wrong. Two failure modes it removes:
     *
     * - **Scheduling blindly** queues another copy on every request. The
     *   guard is not an optimisation; without it a module that seeds its
     *   tasks from the composition root grows one row per page view.
     * - **Guarding on the wrong thing** ends the chain instead. The guard
     *   has to be `find()`, which only sees `pending` rows — the task
     *   currently executing is `processing` by then (`claimOverdue()`
     *   claims before calling the handler), so a handler re-arming itself
     *   from inside `handle()` does not find itself and does schedule its
     *   successor.
     *
     * `$when` takes a `strtotime`-style string ('tomorrow 05:00') as well
     * as a date, because that is what a daily task actually wants to say.
     *
     * @param array<string, mixed> $payload
     */
    public function rearm(
        string $moduleId,
        string $taskKey,
        string $reference,
        \DateTimeInterface|string $when,
        array $payload = []
    ): bool {
        if ($this->find($moduleId, $taskKey, $reference) !== null) {
            return false;
        }

        // Deliberately NOT DateInput::fromStorage(): a relative expression
        // is exactly what this parameter is for, and fromStorage() refuses
        // those on purpose (SECURITY.md § 35). $when comes from a task
        // handler in this repository, never from a request. The one edge
        // worth closing by hand is the empty string — `new
        // DateTimeImmutable('')` is *now*, so a caller that passed a blank
        // would silently run the task immediately instead of failing.
        if (is_string($when) && trim($when) === '') {
            throw new \InvalidArgumentException('rearm() needs a moment, not an empty string.');
        }

        $runAt = $when instanceof \DateTimeInterface ? $when : new \DateTimeImmutable($when);
        $this->schedule($moduleId, $taskKey, $runAt, $payload, $reference);

        return true;
    }

    /**
     * Schedule an action at a specific time.
     *
     * @param array<string, mixed> $payload
     */
    public function schedule(
        string $moduleId,
        string $taskKey,
        \DateTimeInterface $runAt,
        array $payload = [],
        ?string $reference = null,
        ?int $requestedByUserAccountId = null
    ): int {
        $payloadJson = !empty($payload) ? json_encode($payload) : null;
        return $this->repository->create(
            $moduleId,
            $taskKey,
            $runAt->format('Y-m-d H:i:s'),
            $payloadJson,
            $reference,
            $requestedByUserAccountId
        );
    }

    /**
     * Schedule an action after a delay in seconds.
     *
     * @param array<string, mixed> $payload
     */
    public function scheduleAfter(
        string $moduleId,
        string $taskKey,
        int $delaySeconds,
        array $payload = [],
        ?string $reference = null,
        ?int $requestedByUserAccountId = null
    ): int {
        $runAt = (new \DateTimeImmutable())->modify("+{$delaySeconds} seconds");
        return $this->schedule($moduleId, $taskKey, $runAt, $payload, $reference, $requestedByUserAccountId);
    }

    /**
     * Find a specific scheduled action by module, task key, and optional reference.
     *
     * @return array<string, mixed>|null
     */
    public function find(string $moduleId, string $taskKey, ?string $reference = null): ?array
    {
        return $this->repository->findByModuleAndKey($moduleId, $taskKey, $reference);
    }

    /**
     * Cancel a scheduled action.
     */
    public function cancel(int $actionId): void
    {
        $this->repository->cancel($actionId);
    }

    /**
     * Cancel the pending scheduled action for a module/task/reference, if
     * one exists — a no-op otherwise (e.g. it already ran, or was never
     * scheduled because the corresponding option was "none"). Convenience
     * wrapper around find() + cancel() for callers that only have the
     * reference, not the row id (e.g. the retro module rescheduling a
     * board's auto-close after an edit).
     */
    public function cancelPending(string $moduleId, string $taskKey, string $reference): void
    {
        $existing = $this->find($moduleId, $taskKey, $reference);
        if ($existing !== null) {
            $this->cancel((int) $existing['id']);
        }
    }

    /**
     * A single scheduled action by its own id — used by
     * Core\Http\Controller\MaintenanceController::resetStatus() to poll a
     * reset/restore operation's progress. Unlike find(), which looks up a
     * pending row by module/task/reference, this returns any status
     * (pending/processing/done/failed/canceled).
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->repository->findById($id);
    }

    /**
     * All scheduled actions for a module/task key, any status, newest
     * run_at first — for a module's own "planned actions" list (see
     * SchedulerRepository::findByModuleAndTaskKey()).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllForTask(string $moduleId, string $taskKey, int $limit = 100): array
    {
        return $this->repository->findByModuleAndTaskKey($moduleId, $taskKey, $limit);
    }

    /**
     * Purge old scheduled actions (any status) for a module/task, run_at
     * before $cutoff — see SchedulerRepository::deleteOlderThan().
     */
    public function deleteOlderThan(string $moduleId, string $taskKey, \DateTimeInterface $cutoff): int
    {
        return $this->repository->deleteOlderThan($moduleId, $taskKey, $cutoff->format('Y-m-d H:i:s'));
    }
}
