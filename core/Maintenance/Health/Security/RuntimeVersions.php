<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

/**
 * The exact versions one PHP process runs with: PHP itself, the OpenSSL
 * the `openssl` extension is linked against, and libcurl with the TLS
 * engine libcurl itself was built on (issue #1358).
 *
 * **Measured by the process it describes, and only by it.** The web PHP
 * and the cron PHP of a shared host are routinely two different builds —
 * different patch levels, sometimes different distributions — so a
 * version read in one is never a statement about the other.
 * {@see WebRuntimeFacts} carries the web's, {@see \Core\System\
 * CronExecutionFacts} the cron's, and the weekly check reads both without
 * ever measuring its own process (it may be running in either).
 *
 * `null` for OpenSSL or cURL means the extension is **not loaded**, which
 * is a measured absence, not an unknown: {@see HostInventory} leaves such a
 * component out of the box rather than calling it « non vérifiable ».
 */
final class RuntimeVersions
{
    public function __construct(
        /** PHP_VERSION, build suffix included (« 8.3.6-1ubuntu1.2 »). */
        public readonly string $php,
        /** OPENSSL_VERSION_TEXT (« OpenSSL 3.0.13 30 Jan 2024 »), null when the extension is not loaded. */
        public readonly ?string $openssl,
        /** curl_version()['version'], null when the extension is not loaded. */
        public readonly ?string $curl,
        /** curl_version()['ssl_version'] (« OpenSSL/3.0.13 », « GnuTLS/3.8.3 »), null when absent. */
        public readonly ?string $curlSsl,
    ) {
    }

    /** This process, now. Cheap: constants and one in-memory call, no I/O. */
    public static function measure(): self
    {
        $curl = null;
        $curlSsl = null;
        if (function_exists('curl_version')) {
            $info = curl_version();
            if (is_array($info)) {
                $curl = is_string($info['version'] ?? null) ? $info['version'] : null;
                $curlSsl = is_string($info['ssl_version'] ?? null) && $info['ssl_version'] !== ''
                    ? $info['ssl_version']
                    : null;
            }
        }

        return new self(
            PHP_VERSION,
            extension_loaded('openssl') && defined('OPENSSL_VERSION_TEXT') ? (string) OPENSSL_VERSION_TEXT : null,
            $curl,
            $curlSsl
        );
    }

    /** @return array{php: string, openssl: ?string, curl: ?string, curl_ssl: ?string} */
    public function toArray(): array
    {
        return [
            'php' => $this->php,
            'openssl' => $this->openssl,
            'curl' => $this->curl,
            'curl_ssl' => $this->curlSsl,
        ];
    }

    /**
     * A malformed or absent value reads as null — « never measured » —
     * rather than as a runtime with no PHP version.
     */
    public static function fromArray(mixed $data): ?self
    {
        if (!is_array($data) || !is_string($data['php'] ?? null) || $data['php'] === '') {
            return null;
        }

        $string = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return new self(
            $data['php'],
            $string($data['openssl'] ?? null),
            $string($data['curl'] ?? null),
            $string($data['curl_ssl'] ?? null)
        );
    }
}
