<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\ExternalSource;

/**
 * GET one page, following redirects. Separated from the checker so that
 * its tests replay recorded pages and never touch the network.
 */
interface PageFetcherInterface
{
    public function fetch(string $url): FetchedPage;
}
