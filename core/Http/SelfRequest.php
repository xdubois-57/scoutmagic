<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

use Core\Config\SettingService;

/**
 * One fire-and-forget HTTP request from this installation to itself.
 *
 * The transport behind every self-driving loop here: the scheduler's
 * continuation chain (`Scheduler\SchedulerContinuation`) and the migration
 * chain (`Database\MigrationChain`). It exists as one class because the
 * rule below is a security property, and a security property duplicated in
 * two places is a security property that will drift in one of them.
 *
 * **The destination is never derived from `HTTP_HOST`.** The Host header is
 * attacker-supplied on every request; on the reference host `SERVER_ADDR`
 * is `127.0.0.1` and the site sits behind a proxy, so trusting either would
 * turn this mechanism into a remotely-triggerable SSRF — an attacker
 * choosing where the server sends a request that may carry a secret.
 * `base_url` is set once at installation
 * (`SetupController::resolveDefaultBaseUrl()` derives it from the request
 * exactly once, then freezes it) and is authoritative from then on.
 *
 * **Why HTTP and not a process.** On the reference host (LWS shared,
 * CloudLinux/CageFS) a self-directed HTTP request works on every target
 * tried. Detached CLI spawning does not: `system`, `passthru`, `proc_open`
 * and `popen` are all in `disable_functions`, and anything that survived
 * would still be in `kill_orphaned_php`'s sights.
 */
final class SelfRequest
{
    /**
     * A request without a User-Agent is a documented cause of WAF refusal
     * on several hosts.
     */
    public const USER_AGENT = 'ScoutMagic-Scheduler/1.0 (+self-continuation)';

    public const CONNECT_TIMEOUT = 2.0;

    /**
     * The configured base, with $path appended to whatever path prefix
     * `base_url` carries. Null when no usable `base_url` is configured,
     * which every caller must treat as "this installation cannot drive
     * itself" rather than as an error.
     *
     * @return array{scheme: string, host: string, port: int, path: string}|null
     */
    public static function resolveBase(SettingService $settings, string $path): ?array
    {
        $configured = trim((string) ($settings->get('base_url') ?? ''));
        if ($configured === '') {
            return null;
        }

        $parts = parse_url($configured);
        if (!is_array($parts) || !isset($parts['host'])) {
            return null;
        }

        $scheme = ($parts['scheme'] ?? 'https') === 'http' ? 'http' : 'https';
        $prefix = rtrim($parts['path'] ?? '', '/');

        return [
            'scheme' => $scheme,
            'host' => $parts['host'],
            'port' => isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80),
            'path' => $prefix . $path,
        ];
    }

    /**
     * Loopback first, public name second.
     *
     * `127.0.0.1` with a correct `Host` header short-circuits DNS and any
     * external proxy — on the reference host `SERVER_ADDR` is `127.0.0.1`
     * and the site sits behind one. The public name is the fallback for a
     * host where loopback is firewalled off from PHP.
     *
     * @param array{scheme: string, host: string, port: int, path: string} $base
     * @return array<int, string>
     */
    public static function targets(array $base): array
    {
        $transport = $base['scheme'] === 'https' ? 'tls' : 'tcp';

        return [
            "{$transport}://127.0.0.1:{$base['port']}",
            "{$transport}://{$base['host']}:{$base['port']}",
        ];
    }

    /**
     * A bare POST with no body, plus whatever headers the caller needs.
     *
     * @param array{scheme: string, host: string, port: int, path: string} $base
     * @param array<string, string> $headers
     */
    public static function buildPost(array $base, array $headers = []): string
    {
        $request = "POST {$base['path']} HTTP/1.1\r\n"
            . "Host: {$base['host']}\r\n"
            . 'User-Agent: ' . self::USER_AGENT . "\r\n";

        foreach ($headers as $name => $value) {
            $request .= "{$name}: {$value}\r\n";
        }

        return $request . "Content-Length: 0\r\n" . "Connection: close\r\n\r\n";
    }

    /**
     * Write and hang up without reading a byte of the response.
     *
     * TLS to 127.0.0.1 verifies the certificate against the site's own
     * name, not against the IP literal — `peer_name` is what the handshake
     * presents (SNI) and checks, so the host's real certificate validates
     * normally. Verification is never disabled: a request may carry a
     * shared secret.
     */
    public static function writeAndForget(string $target, string $host, string $request): bool
    {
        $context = stream_context_create([
            'ssl' => [
                'peer_name' => $host,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            self::CONNECT_TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($socket === false) {
            return false;
        }

        $written = @fwrite($socket, $request);
        @fclose($socket);

        return $written !== false && $written > 0;
    }

    /**
     * Resolve, build and send in one call — loopback first, then the public
     * name. False means nothing was written, which callers treat as
     * "carry on without self-continuation", never as an error.
     *
     * @param array<string, string> $headers
     */
    public static function post(SettingService $settings, string $path, array $headers = []): bool
    {
        $base = self::resolveBase($settings, $path);
        if ($base === null) {
            return false;
        }

        $request = self::buildPost($base, $headers);
        foreach (self::targets($base) as $target) {
            if (self::writeAndForget($target, $base['host'], $request)) {
                return true;
            }
        }

        return false;
    }
}
