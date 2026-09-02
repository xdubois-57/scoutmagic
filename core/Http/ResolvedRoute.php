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
     * @param string $path the DECLARED route path — the pattern, with its
     *                     placeholders intact (`/members/{id}`), never the
     *                     URL the visitor asked for (`/members/42`). It is
     *                     what identifies a page independently of the row
     *                     it is showing, which is what
     *                     Core\Http\Router::getModuleForPath() is keyed on
     *                     and what Modules\UsageStats counts under
     *                     (ARCHITECTURE.md §8.93).
     * @param array<string, string> $params
     * @param ?array{label: string, parents: array<string>} $breadcrumb
     */
    public function __construct(
        public readonly string $controllerClass,
        public readonly string $action,
        public readonly string $roleMin,
        public readonly array $params,
        public readonly ?array $breadcrumb = null,
        public readonly string $path = ''
    ) {
    }
}
