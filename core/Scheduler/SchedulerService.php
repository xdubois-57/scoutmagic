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
        ?string $reference = null
    ): int {
        $payloadJson = !empty($payload) ? json_encode($payload) : null;
        return $this->repository->create(
            $moduleId,
            $taskKey,
            $runAt->format('Y-m-d H:i:s'),
            $payloadJson,
            $reference
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
        ?string $reference = null
    ): int {
        $runAt = (new \DateTimeImmutable())->modify("+{$delaySeconds} seconds");
        return $this->schedule($moduleId, $taskKey, $runAt, $payload, $reference);
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
}
