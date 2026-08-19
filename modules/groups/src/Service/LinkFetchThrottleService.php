<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Groups\Service;

use Modules\Groups\Repository\LinkFetchLogRepository;

/**
 * Per-member throttle on link-preview fetch attempts (module spec,
 * discussion_group_link_fetch_log's own schema.sql comment) — a member
 * hitting the limit is not refused the post itself, only the preview
 * fetch: Service\PostLinkService treats a throttled attempt exactly like
 * a failed one and still saves a plain link (module spec: "degrade to a
 * plain link on any failure"), which is also what keeps this from ever
 * needing its own user-facing error message.
 */
class LinkFetchThrottleService
{
    private const WINDOW_MINUTES = 10;
    private const LIMIT = 15;
    private const RETENTION_MINUTES = 60;

    public function __construct(private LinkFetchLogRepository $repository)
    {
    }

    /**
     * Records the attempt and returns whether it was under the limit —
     * always records first-come-first-served rather than only recording
     * on success, so a burst right at the edge of the window cannot let
     * more than LIMIT attempts each independently observe "still under
     * the limit" before any of them is recorded.
     */
    public function allowFetch(int $memberId): bool
    {
        $since = (new \DateTimeImmutable('-' . self::WINDOW_MINUTES . ' minutes'))->format('Y-m-d H:i:s');

        // Piggybacked on the one write this table gets, same as gallery's
        // gallery_link_preview_cache (see that table's own comment for
        // why a dedicated scheduled task is not worth it here either).
        $this->repository->purgeOlderThan((new \DateTimeImmutable('-' . self::RETENTION_MINUTES . ' minutes'))->format('Y-m-d H:i:s'));

        $withinLimit = $this->repository->countSince($memberId, $since) < self::LIMIT;
        $this->repository->record($memberId);

        return $withinLimit;
    }
}
