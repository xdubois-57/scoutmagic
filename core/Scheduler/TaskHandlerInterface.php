<?php

declare(strict_types=1);

namespace Core\Scheduler;

interface TaskHandlerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void;
}
