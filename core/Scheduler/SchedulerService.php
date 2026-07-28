<?php

declare(strict_types=1);

namespace Core\Scheduler;

class SchedulerService
{
    public function __construct(private SchedulerRepository $repository)
    {
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
