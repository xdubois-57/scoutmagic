<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

use Core\Statistics\StatisticsTransportResponse;

/**
 * The seam between the archive upload and the network (roadmap IT-26).
 *
 * Separate from `Core\Statistics\StatisticsTransportInterface` although
 * the signature rhymes, and the reason is the payload: that one carries a
 * two-kilobyte JSON body with a 20-second budget, this one carries
 * megabytes from a shared host uphill. Sharing the interface would mean
 * sharing the timeouts, and one of the two numbers would be wrong.
 */
interface ArchiveTransportInterface
{
    /**
     * PUT-like POST of raw bytes, authenticated by a bearer token in the
     * header — never in the body, and never in the URL.
     */
    public function postArchive(
        string $url,
        string $bytes,
        string $bearerToken,
        string $userAgent
    ): StatisticsTransportResponse;
}
