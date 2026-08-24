<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * The response headers of the HTTP request the stream wrapper has just
 * made — `file_get_contents($url, false, $context)` and friends.
 *
 * Replaces `$http_response_header`, the magic local variable PHP used to
 * inject into whichever scope performed the request. That variable is
 * deprecated as of PHP 8.5 and emitted a deprecation notice from five
 * call sites in this repository on essentially every request, which is
 * how a genuine fatal came to be one line in 233 of a support archive's
 * error log.
 *
 * It is NOT a like-for-like swap, and that is the reason this class
 * exists rather than five direct calls to the replacement function.
 * `$http_response_header` was scoped to one function call: a request that
 * never got a response simply left it undefined. `http_get_last_response_
 * headers()` is process-wide and sticky — after a failed connection it
 * still returns the headers of whatever request succeeded before it, in
 * an entirely unrelated part of the application. Reading a 200 from a
 * call that never reached the network is a worse bug than the deprecation
 * notice this replaces, so the clear-then-read pairing is not optional,
 * and lives here once instead of being remembered five times.
 *
 * Usage is always the three lines in that order:
 *
 *     StreamResponseHeaders::clear();
 *     $body = @file_get_contents($url, false, $context);
 *     $headers = StreamResponseHeaders::last();
 */
final class StreamResponseHeaders
{
    /**
     * Forget any previous request's headers. Call immediately BEFORE the
     * request, so that last() can only ever answer about this one.
     */
    public static function clear(): void
    {
        http_clear_last_response_headers();
    }

    /**
     * The raw header lines of the request that has just been made: the
     * status line first, then one `Name: value` entry per header —
     * exactly the shape `$http_response_header` had, so callers that
     * already parse that format need no other change.
     *
     * An empty array means no response arrived (connection refused, DNS
     * failure, timeout, TLS handshake rejected), provided clear() was
     * called first. Callers that must tell "no response" from "a response
     * with no parseable status line" should test for `[]` here rather
     * than inferring it from the body, which can legitimately be empty.
     *
     * @return string[]
     */
    public static function last(): array
    {
        return http_get_last_response_headers() ?? [];
    }
}
