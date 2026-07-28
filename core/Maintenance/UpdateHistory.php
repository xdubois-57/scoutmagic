<?php

declare(strict_types=1);

namespace Core\Maintenance;

class UpdateHistory
{
    /** @var string[] */
    public const STATUSES = ['pending', 'backing_up', 'downloading', 'installing', 'migrating', 'completed', 'failed', 'rolled_back'];

    public function __construct(
        public readonly int $id,
        public readonly string $versionFrom,
        public readonly string $versionTo,
        public readonly string $status,
        public readonly bool $dependenciesChanged,
        public readonly ?string $errorMessage,
        public readonly ?int $backupId,
        public readonly ?int $requestedBy,
        public readonly string $startedAt,
        public readonly ?string $completedAt
    ) {
    }
}
