<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Scheduler;

class SchedulerService
{
    /**
     * Live (module, task, reference) triples and the statuses they are
     * in, loaded once — see $cachePendingRearms. Null until first needed,
     * or after an invalidating write (cancel/purge).
     *
     * The status is part of it because the two guards read it
     * differently: rearm() asks « is one QUEUED », seed() asks « is this
     * chain alive at all ».
     *
     * @var array<string, array<string, int>>|null key => rows per live status
     */
    private ?array $liveKeys = null;

    /**
     * $cachePendingRearms trades one query for the ~20 per-request
     * rearm() probes the composition root makes: the pending rows are
     * loaded once and the "already queued?" guard answers from memory.
     *
     * It is OPT-IN, and only the composition root may opt in. A task
     * handler re-arming itself from inside handle() relies on a FRESH
     * read seeing its own row as `processing` (see rearm()); every
     * handler builds its own service from the PDO, so the snapshot a
     * cached instance took before the task was claimed is never
     * consulted mid-run. Keep it that way: never hand a caching
     * instance to a task handler.
     */
    public function __construct(
        private SchedulerRepository $repository,
        private bool $cachePendingRearms = false,
    ) {
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
        if ($this->hasPendingRearm($moduleId, $taskKey, $reference)) {
            // One queued row is the chain; anything beyond it is a
            // duplicate run and nothing else. Collapsing here rather than
            // in a migration is what heals an installation that already
            // accumulated them — see SchedulerRepository::
            // collapsePending() and seed() below for how they got there.
            $this->collapseIfDuplicated($moduleId, $taskKey, $reference);

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
     * `rearm()` said as a delay — the guarded twin of `scheduleAfter()`.
     *
     * Every recurring chain in this codebase says « in N seconds », and
     * saying it through `rearm()` meant spelling that as a relative
     * string. Twenty-odd handlers therefore reached for the UNGUARDED
     * `scheduleAfter()` instead, and that is exactly how a production
     * installation came to run `sync_mailboxes` NINE times per pass: a
     * page view that lands while the chain's row is `processing` arms a
     * second chain (the seed's guard only sees `pending`), and an
     * unguarded re-arm then keeps both alive for ever. The journal was
     * 91 % « tâche planifiée terminée » as a result.
     *
     * With the guard, a duplicate is self-healing rather than permanent:
     * whichever copy runs first queues the successor, every other copy
     * finds it pending and stands down, and the chain is back to one row
     * after a single pass.
     *
     * @param array<string, mixed> $payload
     */
    public function rearmAfter(
        string $moduleId,
        string $taskKey,
        string $reference,
        int $delaySeconds,
        array $payload = []
    ): bool {
        return $this->rearm(
            $moduleId,
            $taskKey,
            $reference,
            (new \DateTimeImmutable())->modify('+' . max(0, $delaySeconds) . ' seconds'),
            $payload
        );
    }

    /**
     * Queue the FIRST run of a recurring chain, from a caller that is not
     * the task itself — a composition root's `ensureScheduled()` /
     * `bootstrap()`, which runs on every single request.
     *
     * **Its guard is not rearm()'s, and that difference is the bug this
     * exists to close.** rearm() deliberately only sees `pending` rows,
     * so a handler re-arming itself from inside handle() does not find
     * its own claimed row and does queue a successor. A seeder with that
     * same guard queues a duplicate of a task that is *running right
     * now*, because SchedulerRepository::claimOverdue() flips every
     * overdue row to `processing` at the start of a pass: for the whole
     * length of that pass the chain has no pending row, and every web
     * request that lands queues another copy — with `run_at` = now, so
     * already overdue.
     *
     * That is a positive feedback loop, not a one-off duplicate: each
     * extra copy makes the next pass longer, a longer pass is a wider
     * window, and a wider window catches more requests. One installation
     * reached **sixteen thousand** runs of a single hourly task in
     * forty-eight hours, and 99 % of its event journal was « tâche
     * planifiée terminée ».
     *
     * So a seeder asks the honest question — « is this chain alive at
     * all » — and a running task answers yes. Nothing is lost: every
     * chain re-arms itself in its own `finally`.
     *
     * @param array<string, mixed> $payload
     * @return bool whether it queued the first run
     */
    public function seed(
        string $moduleId,
        string $taskKey,
        string $reference,
        \DateTimeInterface|string $when,
        array $payload = []
    ): bool {
        if ($this->hasLiveChain($moduleId, $taskKey, $reference)) {
            $this->collapseIfDuplicated($moduleId, $taskKey, $reference);

            return false;
        }

        if (is_string($when) && trim($when) === '') {
            throw new \InvalidArgumentException('seed() needs a moment, not an empty string.');
        }

        $this->schedule(
            $moduleId,
            $taskKey,
            $when instanceof \DateTimeInterface ? $when : new \DateTimeImmutable($when),
            $payload,
            $reference
        );

        return true;
    }

    /**
     * seed() said as a delay — the shape every bootstrap() wants.
     *
     * @param array<string, mixed> $payload
     */
    public function seedAfter(
        string $moduleId,
        string $taskKey,
        string $reference,
        int $delaySeconds,
        array $payload = []
    ): bool {
        return $this->seed(
            $moduleId,
            $taskKey,
            $reference,
            (new \DateTimeImmutable())->modify('+' . max(0, $delaySeconds) . ' seconds'),
            $payload
        );
    }

    /**
     * collapse(), unless the snapshot already proves there is nothing to
     * collapse. Without the cache the answer is not known, so the repository
     * is asked as before (one SELECT, the cron path). With it, the snapshot
     * counts the pending rows per triple, and a chain with exactly one is
     * left alone: this guard runs from public/index.php for every recurring
     * task on every page load, and used to cost one SELECT per task — 25 of
     * the ~60 statements a page paid before its controller ran — to find,
     * every time, that there was nothing to delete.
     */
    private function collapseIfDuplicated(string $moduleId, string $taskKey, string $reference): void
    {
        if ($this->cachePendingRearms && ($this->statusesOf($moduleId, $taskKey, $reference)['pending'] ?? 0) < 2) {
            return;
        }

        $this->collapse($moduleId, $taskKey, $reference);
    }

    /**
     * Back to one queued row, keeping the one that runs first.
     *
     * Cheap in the ordinary case — the guard above already established
     * there is at least one, and collapsePending() returns without a
     * write unless there are two.
     */
    private function collapse(string $moduleId, string $taskKey, string $reference): void
    {
        if ($this->repository->collapsePending($moduleId, $taskKey, $reference) > 0) {
            $this->liveKeys = null;
        }
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
        $id = $this->repository->create(
            $moduleId,
            $taskKey,
            $runAt->format('Y-m-d H:i:s'),
            $payloadJson,
            $reference,
            $requestedByUserAccountId
        );

        if ($this->liveKeys !== null) {
            $key = $this->liveKey($moduleId, $taskKey, $reference);
            $this->liveKeys[$key]['pending'] = ($this->liveKeys[$key]['pending'] ?? 0) + 1;
        }

        return $id;
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
     * Whether a batched hand-over is still in flight — see
     * SchedulerRepository::hasLiveStartingWith() for why the reference is
     * matched on its start rather than in full.
     *
     * Deliberately NOT served from the cached live-key snapshot: that
     * snapshot exists so a composition root can ask about a hundred exact
     * references without a hundred queries, and a prefix has no key to
     * look up in it. A caller asking this asks about one hand-over.
     */
    public function hasLiveStartingWith(string $moduleId, string $taskKey, string $prefix): bool
    {
        return $this->repository->hasLiveStartingWith($moduleId, $taskKey, $prefix);
    }

    /**
     * Cancel a scheduled action.
     */
    public function cancel(int $actionId): void
    {
        $this->repository->cancel($actionId);
        // The row is addressed by id, so its (module, task, reference)
        // triple is unknown here — drop the whole snapshot rather than
        // guess.
        $this->liveKeys = null;
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
        $deleted = $this->repository->deleteOlderThan($moduleId, $taskKey, $cutoff->format('Y-m-d H:i:s'));
        if ($deleted > 0) {
            $this->liveKeys = null;
        }

        return $deleted;
    }

    /**
     * rearm()'s "already queued?" guard. Without the opt-in cache this is
     * the same fresh find() as always. With it, the pending triples are
     * loaded in one query and the answer comes from memory — schedule()
     * keeps the snapshot coherent for rows created through this instance,
     * and the invalidating writes (cancel, purge) drop it.
     */
    private function hasPendingRearm(string $moduleId, string $taskKey, string $reference): bool
    {
        if (!$this->cachePendingRearms) {
            return $this->find($moduleId, $taskKey, $reference) !== null;
        }

        return ($this->statusesOf($moduleId, $taskKey, $reference)['pending'] ?? 0) > 0;
    }

    private function hasLiveChain(string $moduleId, string $taskKey, string $reference): bool
    {
        if (!$this->cachePendingRearms) {
            return $this->repository->hasLive($moduleId, $taskKey, $reference);
        }

        return $this->statusesOf($moduleId, $taskKey, $reference) !== [];
    }

    /**
     * @return array<string, int> how many rows this triple has per live status
     */
    private function statusesOf(string $moduleId, string $taskKey, string $reference): array
    {
        if ($this->liveKeys === null) {
            $this->liveKeys = [];
            foreach ($this->repository->findLiveKeys() as $row) {
                $key = $this->liveKey(
                    (string) $row['module_id'],
                    (string) $row['task_key'],
                    $row['reference'] !== null ? (string) $row['reference'] : null
                );
                $status = (string) $row['status'];
                $this->liveKeys[$key][$status] = ($this->liveKeys[$key][$status] ?? 0) + 1;
            }
        }

        return $this->liveKeys[$this->liveKey($moduleId, $taskKey, $reference)] ?? [];
    }

    private function liveKey(string $moduleId, string $taskKey, ?string $reference): string
    {
        // NUL never appears in these values, so the triple cannot collide.
        return $moduleId . "\0" . $taskKey . "\0" . ($reference ?? "\0");
    }
}
