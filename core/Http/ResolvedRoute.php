<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

class ResolvedRoute
{
    /**
     * @param array<string, string> $params
     * @param ?array{label: string, parents: array<string>} $breadcrumb
     */
    public function __construct(
        public readonly string $controllerClass,
        public readonly string $action,
        public readonly string $roleMin,
        public readonly array $params,
        public readonly ?array $breadcrumb = null
    ) {
    }
}
