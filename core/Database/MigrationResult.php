<?php

declare(strict_types=1);

namespace Core\Database;

class MigrationResult
{
    /**
     * @param array<string> $executedStatements
     * @param array<string> $warnings
     */
    public function __construct(
        public readonly array $executedStatements,
        public readonly array $warnings,
        public readonly bool $backupCreated
    ) {
    }

    public function hasChanges(): bool
    {
        return count($this->executedStatements) > 0;
    }
}
