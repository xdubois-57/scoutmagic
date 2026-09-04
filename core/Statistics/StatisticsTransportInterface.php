<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

/**
 * The one seam between Core\Statistics\StatisticsSender and the network.
 *
 * It exists so every guard, every redaction rule and every bookkeeping
 * branch in the sender can be tested without a socket — the alternative
 * (tests that reach a real host) is both flaky and, for a feature whose
 * whole point is what it does or doesn't transmit, untrustworthy.
 */
interface StatisticsTransportInterface
{
    /**
     * POST a JSON body, authenticated by a bearer token carried in the
     * `Authorization` header — never in the body.
     */
    public function post(
        string $url,
        string $jsonBody,
        string $bearerToken,
        string $userAgent
    ): StatisticsTransportResponse;
}
