<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Statistics;

/**
 * The real transport: `file_get_contents()` + `stream_context_create()`,
 * the same approach as Core\Maintenance\GitHubReleaseClient and
 * Modules\LlmConnector\Provider\AnthropicProvider — no new Composer
 * dependency for a 2 KB POST once a day.
 *
 * `ignore_errors` is on so a 4xx/5xx comes back as a readable body and a
 * status rather than as `false`: the sender needs the status to decide
 * whether to record a failure, and a bare `false` would flatten every
 * remote answer into "could not contact".
 */
final class StreamStatisticsTransport implements StatisticsTransportInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TOTAL_TIMEOUT_SECONDS = 20;

    public function post(string $url, string $jsonBody, string $bearerToken, string $userAgent): StatisticsTransportResponse
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/json\r\n"
                    . 'Authorization: Bearer ' . $bearerToken . "\r\n"
                    . 'User-Agent: ' . $userAgent . "\r\n",
                'content' => $jsonBody,
                'timeout' => self::TOTAL_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'socket' => [
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return StatisticsTransportResponse::transportError('unreachable');
        }

        $status = 0;
        foreach ($http_response_header as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return StatisticsTransportResponse::response($status, $body);
    }
}
