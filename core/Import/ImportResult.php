<?php

declare(strict_types=1);

namespace Core\Import;

class ImportResult
{
    /**
     * @param string[] $warnings
     */
    public function __construct(
        public readonly int $memberCount,
        public readonly int $lineCount,
        public readonly int $newFunctionsCount,
        public readonly array $warnings
    ) {
    }
}
