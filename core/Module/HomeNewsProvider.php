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
     * The $limit most recent `public`-visibility articles (module usability
     * review: homepage news column) — never `chief`/`admin`/`direct_link`
     * ones, same filtering as the module's own public list.
     *
     * @return array<int, array{id: int, title: string, summary: ?string, image_url: ?string, created_at: string}>
     */
    public function getLatestPublicArticles(int $limit): array;
}
