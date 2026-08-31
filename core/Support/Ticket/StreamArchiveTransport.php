<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Ticket;

use Core\Http\StreamResponseHeaders;
use Core\Statistics\StatisticsTransportResponse;

/**
 * The real archive upload: `file_get_contents()` +
 * `stream_context_create()`, the same approach as every other outbound
 * call in this codebase and no new Composer dependency for one POST.
 *
 * **The budget is the difference.** A statistics report is 2 KB with 20
 * seconds to spare; an archive is megabytes leaving a shared host on an
 * upstream link nobody chose, so the total is minutes rather than
 * seconds. The connect timeout stays short — a receiver that does not
 * answer the handshake will not answer the upload either, and failing
 * fast there is what keeps a wrong destination from holding the page for
 * three minutes.
 */
final class StreamArchiveTransport implements ArchiveTransportInterface
{
    private const CONNECT_TIMEOUT_SECONDS = 10;
    private const TOTAL_TIMEOUT_SECONDS = 180;

    public function postArchive(
        string $url,
        string $bytes,
        string $bearerToken,
        string $userAgent
    ): StatisticsTransportResponse {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/zip\r\n"
                    . 'Content-Length: ' . strlen($bytes) . "\r\n"
                    . 'Authorization: Bearer ' . $bearerToken . "\r\n"
                    . 'User-Agent: ' . $userAgent . "\r\n",
                'content' => $bytes,
                'timeout' => self::TOTAL_TIMEOUT_SECONDS,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'socket' => [
                'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
            ],
        ]);

        StreamResponseHeaders::clear();
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return StatisticsTransportResponse::transportError('unreachable');
        }

        $status = 0;
        foreach (StreamResponseHeaders::last() as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        if ($status === 0) {
            return StatisticsTransportResponse::transportError('no_status_line');
        }

        return StatisticsTransportResponse::response($status, $body);
    }
}
