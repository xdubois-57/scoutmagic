<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

/**
 * A TLS terminator in front of `php -S`, for scripts/dast.sh only.
 *
 * ## Why this exists
 *
 * The dynamic security scan has to observe the application over real
 * HTTPS or half of what it checks is unobservable: the `Secure` flag on
 * the session cookie, `Strict-Transport-Security`, and every rule that
 * only fires on a secure origin. PHP's built-in web server speaks no TLS
 * at all, and the locked design for this harness is "no nginx, no
 * php-fpm" — the same "one interpreter" reasoning that keeps
 * scripts/e2e.sh free of anything but `php` and `npm`. So the TLS half is
 * a PHP script, exactly like the maildrop and the coverage prepend
 * already are.
 *
 * It is a **test harness**, never a production component: nothing here
 * ships in a release artifact, and no deployment ever runs it.
 *
 * ## What it does to the request
 *
 * It sets `X-Forwarded-Proto: https`, which is what makes the throwaway
 * instance emit `Secure` cookies and HSTS — through
 * Core\Http\RequestScheme's opt-in, which scripts/e2e-support.php turns
 * on for this instance and for no other (SECURITY.md § 9). Any copy of
 * that header the client sent is REMOVED first: a terminator that
 * forwards a client-supplied `X-Forwarded-Proto` is exactly the
 * vulnerability the opt-in exists to avoid, and this one would otherwise
 * teach the scan that the header is trustworthy when it is not.
 *
 * Nothing else about the request is rewritten. In particular `Host` is
 * left alone, so the application keeps seeing `localhost:<tls port>` and
 * every absolute URL it builds stays reachable.
 *
 * ## Why the response is buffered and re-framed
 *
 * `php -S` sends NO `Content-Length` for anything PHP generates: it
 * answers `Connection: close` and lets EOF frame the body. That is legal
 * HTTP/1.1, but it has two consequences a scan cannot live with.
 *
 * A connection that can only carry one response means a TLS handshake per
 * asset, which is slow enough to be observable as behaviour: a page's
 * buttons became clickable before `/assets/js/confirm.js` had finished
 * loading, so a click that should have opened a confirmation dialog
 * submitted the form directly. And Chromium treats a **download** framed
 * only by connection close as *cancelled* — which is exactly how the
 * gallery download scenario failed under the scan while passing under
 * `npm run e2e`.
 *
 * So each response is read whole, measured, and re-sent with a real
 * `Content-Length` and a reusable connection. The bytes the scanner sees
 * are still the bytes the application produced; only the framing around
 * them is this script's.
 *
 * Usage:
 *   php scripts/dast-tls-proxy.php --listen=127.0.0.1:8443 \
 *       --backend=127.0.0.1:8080 --cert=/path/to/server.pem [--log=/path]
 */

const DAST_TLS_READ_CHUNK = 65536;
const DAST_TLS_HEAD_LIMIT = 262144;
const DAST_TLS_BACKEND_TIMEOUT = 120;
// A ceiling, not a target: a client that keeps one connection for ever
// would pin a forked child for ever with it.
const DAST_TLS_MAX_REQUESTS = 200;

/**
 * @param list<string> $argv
 * @return array<string, string>
 */
function dast_tls_parse_arguments(array $argv): array
{
    $options = [];
    foreach (array_slice($argv, 1) as $argument) {
        if (!preg_match('/^--([a-z-]+)=(.*)$/', $argument, $matches)) {
            fwrite(STDERR, "dast-tls-proxy: unrecognised argument '{$argument}'.\n");
            exit(1);
        }
        $options[$matches[1]] = $matches[2];
    }

    foreach (['listen', 'backend', 'cert'] as $required) {
        if (($options[$required] ?? '') === '') {
            fwrite(STDERR, "dast-tls-proxy: --{$required} is required.\n");
            exit(1);
        }
    }

    return $options;
}

/**
 * A tiny buffered reader over a stream: an HTTP connection is a sequence
 * of framed messages, and reading "until the headers end" always
 * overshoots into the next one. Keeping the overshoot in a buffer the
 * caller carries is what makes keep-alive possible at all — without it,
 * the first byte of a request body (or of the next request) is read and
 * dropped.
 *
 * @param string $buffer carried by reference between calls
 */
function dast_tls_fill(&$buffer, $stream, int $minimum): bool
{
    while (strlen($buffer) < $minimum) {
        $chunk = fread($stream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            return false;
        }
        $buffer .= $chunk;
    }

    return true;
}

/**
 * Read up to the end of an HTTP header block, returning it (terminator
 * included) and leaving whatever followed in the buffer.
 *
 * Returns null when the peer closed before sending a complete head,
 * which is ordinary: a browser opening a connection it never uses, a
 * keep-alive connection reaching its idle end, a scanner probing the
 * port.
 */
function dast_tls_read_head(&$buffer, $stream): ?string
{
    while (($position = strpos($buffer, "\r\n\r\n")) === false) {
        if (strlen($buffer) > DAST_TLS_HEAD_LIMIT) {
            return null;
        }
        $chunk = fread($stream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $buffer .= $chunk;
    }

    $head = substr($buffer, 0, $position + 4);
    $buffer = substr($buffer, $position + 4);

    return $head;
}

/**
 * Case-insensitive header lookup over a raw header block.
 */
function dast_tls_header(string $head, string $name): ?string
{
    foreach (explode("\r\n", $head) as $line) {
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        if (strcasecmp(trim(substr($line, 0, $colon)), $name) === 0) {
            return trim(substr($line, $colon + 1));
        }
    }

    return null;
}

/**
 * Drop every occurrence of a set of headers from a raw header block.
 *
 * @param list<string> $names
 */
function dast_tls_strip_headers(string $head, array $names): string
{
    $kept = [];
    foreach (explode("\r\n", rtrim($head, "\r\n")) as $line) {
        $colon = strpos($line, ':');
        if ($colon !== false) {
            $header = trim(substr($line, 0, $colon));
            foreach ($names as $name) {
                if (strcasecmp($header, $name) === 0) {
                    continue 2;
                }
            }
        }
        $kept[] = $line;
    }

    return implode("\r\n", $kept);
}

/**
 * Read a request body off the client, by whichever framing the request
 * declared, and return it verbatim.
 *
 * Chromium uses Content-Length for ordinary form posts, but a streamed
 * upload arrives chunked — and this application has chunked uploads
 * (Core\File\ChunkedUploadStore). A terminator that mishandled one would
 * truncate a request the scan would then report as a server error.
 */
function dast_tls_read_body(&$buffer, $stream, string $head): ?string
{
    $transferEncoding = dast_tls_header($head, 'Transfer-Encoding');
    if ($transferEncoding !== null && stripos($transferEncoding, 'chunked') !== false) {
        $body = '';
        while (true) {
            while (($lineEnd = strpos($buffer, "\r\n")) === false) {
                if (!dast_tls_fill($buffer, $stream, strlen($buffer) + 1)) {
                    return null;
                }
            }
            $size = (int) hexdec(trim(explode(';', substr($buffer, 0, $lineEnd))[0]));
            $needed = $lineEnd + 2 + $size + 2;
            if (!dast_tls_fill($buffer, $stream, $needed)) {
                return null;
            }
            $body .= substr($buffer, 0, $needed);
            $buffer = substr($buffer, $needed);
            if ($size === 0) {
                return $body;
            }
        }
    }

    $contentLength = (int) (dast_tls_header($head, 'Content-Length') ?? '0');
    if ($contentLength <= 0) {
        return '';
    }
    if (!dast_tls_fill($buffer, $stream, $contentLength)) {
        return null;
    }
    $body = substr($buffer, 0, $contentLength);
    $buffer = substr($buffer, $contentLength);

    return $body;
}

/**
 * Send one request to `php -S` and read the whole answer back.
 *
 * A new backend connection per request, because the built-in server
 * answers `Connection: close` and has no keep-alive of its own — so the
 * response is simply everything it writes before EOF.
 *
 * @return array{0: string, 1: string}|null head and body, or null on failure
 */
function dast_tls_exchange(string $backend, string $head, string $body): ?array
{
    $upstream = @stream_socket_client('tcp://' . $backend, $errorNumber, $errorString, DAST_TLS_BACKEND_TIMEOUT);
    if ($upstream === false) {
        return null;
    }
    stream_set_timeout($upstream, DAST_TLS_BACKEND_TIMEOUT);

    if (@fwrite($upstream, $head . $body) === false) {
        fclose($upstream);
        return null;
    }

    $raw = '';
    while (!feof($upstream)) {
        $chunk = fread($upstream, DAST_TLS_READ_CHUNK);
        if ($chunk === false || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    fclose($upstream);

    $position = strpos($raw, "\r\n\r\n");
    if ($position === false) {
        return null;
    }

    return [substr($raw, 0, $position + 4), substr($raw, $position + 4)];
}

/**
 * Rebuild the response head so the client gets a properly framed,
 * reusable connection.
 *
 * This is the whole reason the response is buffered rather than piped.
 * `php -S` sends NO `Content-Length` for anything PHP generates — it
 * closes the connection and lets EOF frame the body. That is legal
 * HTTP/1.1 and browsers accept it for a page, but it costs a full TLS
 * handshake per asset (which showed up as a race: a page's buttons were
 * clickable before /assets/js/confirm.js had finished loading), and
 * Chromium treats a DOWNLOAD framed only by connection close as
 * **cancelled** — which is exactly how the gallery download scenario
 * failed under the scan while passing under `npm run e2e`.
 *
 * Measuring the body and declaring it fixes both: the connection can be
 * reused, and a download has a length to complete against.
 */
function dast_tls_reframe(string $head, int $length, bool $keepAlive, bool $omitBody): string
{
    $head = dast_tls_strip_headers($head, ['Content-Length', 'Transfer-Encoding', 'Connection', 'Keep-Alive']);
    $head .= "\r\nConnection: " . ($keepAlive ? 'keep-alive' : 'close');
    if (!$omitBody) {
        $head .= "\r\nContent-Length: {$length}";
    }

    return $head . "\r\n\r\n";
}

/**
 * Relay requests on one client connection until either side is done.
 *
 * Runs in a forked child, so a failure here costs one connection and
 * never the listener.
 */
function dast_tls_handle_connection($client, string $backend): void
{
    stream_set_timeout($client, DAST_TLS_BACKEND_TIMEOUT);
    $buffer = '';

    for ($served = 0; $served < DAST_TLS_MAX_REQUESTS; $served++) {
        $head = dast_tls_read_head($buffer, $client);
        if ($head === null) {
            return;
        }

        $requestLine = strtok($head, "\r\n");
        $method = strtoupper((string) strtok($requestLine === false ? '' : $requestLine, ' '));

        $body = dast_tls_read_body($buffer, $client, $head);
        if ($body === null) {
            return;
        }

        // Any client-supplied copy is dropped before ours is set: a
        // terminator that forwards a client's own X-Forwarded-Proto is
        // exactly the vulnerability the application's opt-in exists to
        // avoid, and this one would otherwise teach the scan that the
        // header is trustworthy when it is not.
        $forwarded = dast_tls_strip_headers($head, ['X-Forwarded-Proto'])
            . "\r\nX-Forwarded-Proto: https\r\n\r\n";

        $response = dast_tls_exchange($backend, $forwarded, $body);
        if ($response === null) {
            // The application server is gone (teardown, a crash). Answer
            // something well-formed rather than a bare connection reset,
            // so the scanner records a 502 instead of an unexplained
            // failure.
            @fwrite($client, "HTTP/1.1 502 Bad Gateway\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
            return;
        }
        [$responseHead, $responseBody] = $response;

        // A HEAD answer, a 204 and a 304 carry no body by definition, and
        // declaring a length for them would be a framing error of our own
        // making.
        $status = 0;
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $responseHead, $matches) === 1) {
            $status = (int) $matches[1];
        }
        $omitBody = $method === 'HEAD' || $status === 204 || $status === 304;

        $clientConnection = (string) (dast_tls_header($head, 'Connection') ?? '');
        $keepAlive = stripos($clientConnection, 'close') === false
            && str_contains($requestLine === false ? '' : $requestLine, 'HTTP/1.1')
            // The LAST response this child will serve says so, rather than
            // closing silently after it. A client that reused a connection
            // it had no reason to believe was finished loses whatever it
            // had already written into it — and a browser retries a lost
            // GET but not necessarily a lost POST, which surfaces as one
            // request that simply never gets an answer.
            && $served < DAST_TLS_MAX_REQUESTS - 1;

        $out = dast_tls_reframe($responseHead, strlen($responseBody), $keepAlive, $omitBody);
        if (@fwrite($client, $out) === false) {
            return;
        }
        if (!$omitBody && $responseBody !== '' && @fwrite($client, $responseBody) === false) {
            return;
        }

        if (!$keepAlive) {
            return;
        }
    }
}

$options = dast_tls_parse_arguments($argv);

foreach (['pcntl', 'openssl'] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "dast-tls-proxy: the '{$extension}' PHP extension is required.\n");
        exit(1);
    }
}

$context = stream_context_create([
    'ssl' => [
        'local_cert' => $options['cert'],
        'allow_self_signed' => true,
        'verify_peer' => false,
        'verify_peer_name' => false,
        // No ALPN advertised, so a client that would otherwise negotiate
        // HTTP/2 falls back to HTTP/1.1 — which is what the relay above
        // speaks, and what ZAP records most faithfully.
        'alpn_protocols' => '',
        'disable_compression' => true,
    ],
]);

// Bound as plain TCP with the TLS options attached, and the handshake
// deferred to the child (below), for two reasons. A `tls://` server does
// the handshake inside stream_socket_accept(), which blocks the accept
// loop on whichever client is slowest; and PHP's fclose() on an
// already-negotiated TLS stream performs an SSL shutdown, so the
// parent's routine close of its own copy after forking tore down the
// connection the child was still serving — every response died as
// "SSL: Broken pipe" before a byte of it reached the browser. Closing a
// plain TCP socket in the parent is just a refcount decrement, which is
// what the fork pattern assumes.
$server = @stream_socket_server(
    'tcp://' . $options['listen'],
    $errorNumber,
    $errorString,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context
);

if ($server === false) {
    fwrite(STDERR, "dast-tls-proxy: cannot listen on {$options['listen']}: {$errorString}\n");
    exit(1);
}

// SIG_IGN on SIGCHLD makes the kernel reap children itself — a scan is
// tens of thousands of connections, and a zombie per connection would
// exhaust the process table long before the run ends.
pcntl_signal(SIGCHLD, SIG_IGN);
pcntl_signal(SIGTERM, static function (): void {
    exit(0);
});
pcntl_signal(SIGINT, static function (): void {
    exit(0);
});

fwrite(STDERR, "dast-tls-proxy: listening on {$options['listen']}, forwarding to {$options['backend']}.\n");

while (true) {
    pcntl_signal_dispatch();

    // A failed TLS handshake (a scanner probing the port with plain HTTP,
    // a client that gave up) returns false here. It is an ordinary event
    // during a security scan, not an error worth stopping for.
    $client = @stream_socket_accept($server, 5);
    if ($client === false) {
        continue;
    }

    $pid = pcntl_fork();
    if ($pid === -1) {
        fwrite(STDERR, "dast-tls-proxy: fork failed, handling this connection inline.\n");
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true) {
            dast_tls_handle_connection($client, $options['backend']);
        }
        fclose($client);
        continue;
    }

    if ($pid === 0) {
        fclose($server);
        // A failed handshake is ordinary during a security scan (a plain
        // HTTP probe against the TLS port, a client that changed its
        // mind); it costs this one connection and nothing else.
        if (@stream_socket_enable_crypto($client, true, STREAM_CRYPTO_METHOD_TLS_SERVER) === true) {
            dast_tls_handle_connection($client, $options['backend']);
        }
        fclose($client);
        exit(0);
    }

    fclose($client);
}
