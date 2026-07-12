<?php

declare(strict_types=1);

namespace Core\Member;

class MemberFunctionInfo
{
    public function __construct(
        public readonly string $functionLabel,
        public readonly string $functionRole,
        public readonly ?string $branchName,
        public readonly ?string $sectionName,
        public readonly ?string $sectionCode,
        public readonly bool $isMainFunction,
        public readonly ?string $startDate,
        public readonly ?string $endDate
    ) {
    }
}
