<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Module;

/**
 * Optional hook a module can implement to inject recent articles onto the
 * homepage (Core\Http\Controller\PageController::home()) — without core
 * depending on the module directly. Same precedent as
 * Core\Module\HomeBannerProvider (ARCHITECTURE.md §7.4).
 */
interface HomeNewsProvider
{
    /**
     * The $limit most recent articles a reader at $role may be shown in a
     * list (module usability review: homepage news column), same filtering
     * as the module's own public list.
     *
     * $role is the caller's role name (Core\Security\Role's backing
     * value), passed rather than read from the session because a provider
     * is a Service and never touches $_SESSION — same shape as
     * Core\Module\HomeBannerProvider::getRandomBannerHtml(). It exists
     * because a "members only" article has to be reachable from the
     * homepage of the members who may read it; `chief`/`admin`
     * visibilities still never appear here (they have their own manager
     * view) and neither does `direct_link`, which means "in no list at
     * all" whoever is asking.
     *
     * @return array<int, array{id: int, title: string, summary: ?string, image_url: ?string, created_at: string}>
     */
    public function getLatestVisibleArticles(int $limit, string $role): array;
}
