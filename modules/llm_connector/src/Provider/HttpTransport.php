<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\LlmConnector\Provider;

use Core\Http\StreamResponseHeaders;
use Modules\LlmConnector\Api\LlmException;

/**
 * The JSON-over-HTTP transport shared by every provider driver — the three
 * drivers only ever differed in their auth headers, and each carried its own
 * copy of this logic.
 *
 * It exists as one class because the two failure modes it guards against were
 * silently wrong in all three copies at once:
 *
 * 1. `ignore_errors` is required (a 4xx/5xx body carries the provider's own
 *    error description, which file_get_contents would otherwise discard), but
 *    it also means a failed call returns a body like any other. Every driver
 *    then only recognised errors shaped exactly as `{"error": {...}}`, so a
 *    401/429/502 shaped any other way — a bare `{"message": "..."}`, a
 *    gateway's error page — parsed as a successful response with no choices,
 *    i.e. an empty completion reported as success. The HTTP status is checked
 *    here first, before any caller gets to interpret the body.
 * 2. json_encode() returns false for a payload containing invalid UTF-8 (a
 *    bank statement imported from a Latin-1 export reaches the prompt as raw
 *    bytes — the label columns are encrypted BLOBs, so nothing validates
 *    their encoding on the way in). Passing that false to strlen() under
 *    strict_types raises a TypeError, which is not an LlmException and so
 *    escapes every consumer's catch block, aborting a whole categorization
 *    batch over one bad row.
 */
final class HttpTransport
{
    /**
     * Longest provider error body kept in an exception's `detail` — enough
     * to carry the provider's own explanation to the journal, short enough
     * not to dump a full HTML error page into it. It is never part of the
     * exception's own message (see Api\LlmException).
     */
    private const MAX_ERROR_BODY_CHARS = 500;

    /**
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    public function getJson(string $url, array $headers, int $timeout): array
    {
        return $this->request($url, [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]);
    }

    /**
     * @param array<int, string> $headers
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function postJson(string $url, array $headers, array $body, int $timeout): array
    {
        $jsonBody = json_encode($body, JSON_UNESCAPED_UNICODE);
        if ($jsonBody === false) {
            throw LlmException::invalidRequest(
                'json_encode() failed: ' . json_last_error_msg()
            );
        }

        $headers[] = 'Content-Length: ' . strlen($jsonBody);

        return $this->request($url, [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $jsonBody,
            'timeout' => $timeout,
            'ignore_errors' => true,
        ]);
    }

    /**
     * @param array<string, mixed> $httpOptions
     * @return array<string, mixed>
     */
    private function request(string $url, array $httpOptions): array
    {
        $context = stream_context_create(['http' => $httpOptions]);

        StreamResponseHeaders::clear();
        $body = @file_get_contents($url, false, $context);

        if ($body === false) {
            // No response at all: connection refused, DNS failure, or the
            // timeout elapsed. Checked first, because the headers below are
            // only populated once a response actually arrived.
            throw LlmException::timeout();
        }

        // Cleared immediately before the request above, so this can only
        // describe that request — never a stale one from another provider
        // call earlier in the same process.
        $status = $this->statusCode(StreamResponseHeaders::last());

        if ($status === 429) {
            throw LlmException::rateLimited($this->shorten($body));
        }

        // 0 means no status line could be read at all — the body came from
        // somewhere other than an HTTP response (a file:// URL, a stub in a
        // test). Nothing to reject on, so it is left to the JSON check below.
        if ($status !== 0 && ($status < 200 || $status >= 300)) {
            // The body is the provider's own English prose (often a whole
            // HTML error page) — it goes to the journal via
            // LlmException::$detail, never into the sentence a chief reads.
            throw LlmException::apiError($this->shorten($body), $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw LlmException::apiError('Invalid JSON response from provider: ' . $this->shorten($body));
        }

        return $decoded;
    }

    /**
     * The last status line in the header list — a redirect chain leaves the
     * earlier ones in place, and it is the final response that matters.
     *
     * @param array<int, string> $responseHeaders
     */
    private function statusCode(array $responseHeaders): int
    {
        $status = 0;
        foreach ($responseHeaders as $header) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $matches) === 1) {
                $status = (int) $matches[1];
            }
        }

        return $status;
    }

    private function shorten(string $body): string
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return '(réponse vide)';
        }

        return mb_strimwidth($trimmed, 0, self::MAX_ERROR_BODY_CHARS, '…');
    }
}
