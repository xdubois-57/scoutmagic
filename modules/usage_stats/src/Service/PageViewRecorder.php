<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\UsageStats\Service;

use Core\Security\Role;
use Modules\UsageStats\Audience;
use Modules\UsageStats\Repository\PageViewRepository;

/**
 * The counting itself — the whole of IT-01, in one method.
 *
 * **It runs after the response has been sent.** `public/index.php` calls
 * it past `$response->send()` and `session_write_close()`, which is where
 * the poor man's cron used to live and was removed from precisely so that
 * « a visitor no longer pays for background work ». Nothing here may
 * become a reason to reintroduce that cost: one policy check, one date
 * format, one prepared statement, and a `catch` that gives up in silence.
 *
 * **Silence on failure is deliberate.** The response is already on the
 * wire; there is no page left to show an error on, and a counter is never
 * worth an entry in the journal, let alone a fatal. A month of figures
 * missing because the table was mid-migration is a smaller problem than
 * anything this could do about it.
 */
class PageViewRecorder
{
    /** What a route the application itself declares is counted under. */
    public const CORE_MODULE_ID = 'core';

    public function __construct(private PageViewRepository $repository)
    {
    }

    /**
     * Count one page view, or decide not to.
     *
     * @param ?string $routePattern the DECLARED route path — `/members/{id}`,
     *                              never `/members/42`; null when the router
     *                              matched nothing
     * @param ?string $moduleId     the module that declared the route, or null
     *                              for a core route
     * @return bool whether a counter was actually incremented — for tests and
     *              for callers that want to know, never used to branch on the
     *              request path
     */
    public function record(
        string $method,
        ?string $routePattern,
        ?string $moduleId,
        int $statusCode,
        ?string $contentType,
        bool $servesAFile,
        string $userAgent,
        Role $role,
        ?\DateTimeImmutable $now = null
    ): bool {
        if (!PageViewPolicy::shouldCount($method, $routePattern, $statusCode, $contentType, $servesAFile, $userAgent)) {
            return false;
        }

        try {
            $this->repository->increment(
                ($now ?? new \DateTimeImmutable('now'))->format('Y-m'),
                (string) $routePattern,
                // 'core' rather than null or '': the column is the module
                // dimension of every screen's grouping, and a NULL that
                // means « the application itself » would have every query
                // spell that out in SQL.
                $moduleId ?? self::CORE_MODULE_ID,
                Audience::forRole($role)->value
            );
        } catch (\Throwable) {
            return false;
        }

        return true;
    }
}
