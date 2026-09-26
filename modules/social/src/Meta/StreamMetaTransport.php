<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\Social\Meta;

use Core\Http\StreamResponseHeaders;

/**
 * PHP's own HTTP stream, as the AI connector's transport and the release
 * check use it — no new dependency for three hosts and a handful of calls.
 *
 * `ignore_errors` keeps the body of a 4xx: Meta explains its refusals
 * there, and {@see MetaClient} reads the explanation. The URL, which can
 * carry a token in its query, is never part of anything this class
 * returns or raises — a failure is reported as « no answer », full stop.
 */
final class StreamMetaTransport implements MetaTransport
{
    private const TIMEOUT_SECONDS = 20;

    public function get(string $url): ?array
    {
        return $this->request($url, ['method' => 'GET']);
    }

    public function postForm(string $url, array $fields): ?array
    {
        $body = http_build_query($fields);

        return $this->request($url, [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\nContent-Length: " . strlen($body),
            'content' => $body,
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{status: int, body: string}|null
     */
    private function request(string $url, array $options): ?array
    {
        $context = stream_context_create(['http' => $options + [
            'timeout' => self::TIMEOUT_SECONDS,
            'ignore_errors' => true,
            'user_agent' => 'ScoutMagic',
        ]]);

        StreamResponseHeaders::clear();
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            return null;
        }

        $status = 0;
        foreach (StreamResponseHeaders::last() as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return ['status' => $status, 'body' => $body];
    }
}
