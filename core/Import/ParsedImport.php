<?php

declare(strict_types=1);

namespace Core\Import;

class ParsedImport
{
    /**
     * @param ParsedMember[] $members
     */
    public function __construct(
        public readonly array $members,
        public readonly int $lineCount
    ) {
    }
}
