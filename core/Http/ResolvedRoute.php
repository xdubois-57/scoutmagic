<?php

declare(strict_types=1);

namespace Core\Http;

class ResolvedRoute
{
    /**
     * @param array<string, string> $params
     */
    public function __construct(
        public readonly string $controllerClass,
        public readonly string $action,
        public readonly string $roleMin,
        public readonly array $params
    ) {
    }
}
