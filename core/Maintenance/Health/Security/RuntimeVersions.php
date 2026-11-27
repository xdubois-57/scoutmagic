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
 * `null` for cURL means the extension is **not loaded**, a measured
 * absence: {@see HostInventory} leaves such a component out of the box
 * rather than calling it « non vérifiable ». OpenSSL has three answers, so
 * it carries `$opensslLoaded` beside its version: not loaded (absent),
 * loaded with the library version read, and loaded with that version
 * unreadable (unknown).
 *
 * **The OpenSSL version is the library this process LOADED, not the one
 * PHP was compiled against.** `OPENSSL_VERSION_TEXT` is the header's
 * macro, frozen when PHP was built: a host that updates libssl in place
 * without rebuilding PHP keeps reporting the old number, and comparing it
 * would raise alerts about a library no longer running (review of #1415).
 * The extension's own module information prints both — « OpenSSL Library
 * Version », read at run time through `OpenSSL_version()`, and « OpenSSL
 * Header Version » —, so that is what is read; the header is kept beside it
 * for the record.
 */
final class RuntimeVersions
{
    public function __construct(
        /** PHP_VERSION, build suffix included (« 8.3.6-1ubuntu1.2 »). */
        public readonly string $php,
        /**
         * The loaded library (« OpenSSL 3.0.13 30 Jan 2024 »), null when the
         * extension is not loaded or the version could not be read.
         */
        public readonly ?string $openssl,
        /** curl_version()['version'], null when the extension is not loaded. */
        public readonly ?string $curl,
        /** curl_version()['ssl_version'] (« OpenSSL/3.0.13 », « GnuTLS/3.8.3 »), null when absent. */
        public readonly ?string $curlSsl,
        /** Whether the extension is loaded; null means « as $openssl says » (loaded when it has a version). */
        ?bool $opensslLoaded = null,
        /** OPENSSL_VERSION_TEXT, the headers PHP was built against — recorded, never compared. */
        public readonly ?string $opensslHeader = null,
    ) {
        $this->opensslLoaded = $opensslLoaded ?? $openssl !== null;
    }

    public readonly bool $opensslLoaded;

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

        $loaded = extension_loaded('openssl');

        return new self(
            PHP_VERSION,
            $loaded ? self::loadedOpensslLibrary() : null,
            $curl,
            $curlSsl,
            $loaded,
            $loaded && defined('OPENSSL_VERSION_TEXT') ? (string) OPENSSL_VERSION_TEXT : null
        );
    }

    /**
     * « OpenSSL Library Version » from the extension's module information —
     * a few hundred bytes of text, or of HTML under a web SAPI, captured and
     * discarded. Null when it cannot be read.
     */
    private static function loadedOpensslLibrary(): ?string
    {
        try {
            $extension = new \ReflectionExtension('openssl');
            ob_start();
            try {
                $extension->info();
            } finally {
                $info = (string) ob_get_clean();
            }

            return self::opensslLibraryFrom($info);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The loaded library's line of the openssl module information, in
     * either of its two renderings (CLI « => », or a web SAPI's table).
     */
    public static function opensslLibraryFrom(string $info): ?string
    {
        $text = html_entity_decode(strip_tags(str_replace('</td>', ' </td>', $info)), ENT_QUOTES | ENT_HTML5);
        if (preg_match('/OpenSSL Library Version\s*(?:=>\s*)?(\S[^\r\n]*?)\s*$/mi', $text, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'php' => $this->php,
            'openssl' => $this->openssl,
            'openssl_loaded' => $this->opensslLoaded,
            'openssl_header' => $this->opensslHeader,
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

        // Stored before the loaded library was read apart from the header:
        // its « openssl » WAS the header, so it is kept as such and the
        // library reads as unknown until the next measurement.
        if (!is_bool($data['openssl_loaded'] ?? null)) {
            $header = $string($data['openssl'] ?? null);

            return new self(
                $data['php'],
                null,
                $string($data['curl'] ?? null),
                $string($data['curl_ssl'] ?? null),
                $header !== null,
                $header
            );
        }

        return new self(
            $data['php'],
            $string($data['openssl'] ?? null),
            $string($data['curl'] ?? null),
            $string($data['curl_ssl'] ?? null),
            $data['openssl_loaded'],
            $string($data['openssl_header'] ?? null)
        );
    }
}
