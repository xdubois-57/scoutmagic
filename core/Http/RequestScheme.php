<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Http;

/**
 * The single place that decides whether the current request reached us
 * over HTTPS.
 *
 * This used to be duplicated across six call sites — SessionManager's
 * `cookie_secure`, LastLoginMethodCookie, CookieConsentService,
 * SetupController's pre-filled base URL, the statistics intake's
 * cleartext refusal, and Response's HSTS emission — and one of them
 * diverged: HSTS looked at `$_SERVER['HTTPS']` alone while the five
 * others also accepted `SERVER_PORT === 443`. On a host where only the
 * port says HTTPS, session cookies were therefore `Secure` but
 * Strict-Transport-Security was never emitted. Everything now converges
 * here, so the two can no longer disagree.
 *
 * ## `X-Forwarded-Proto`, and why it is opt-in
 *
 * Behind a separate TLS terminator (load balancer, CDN, several shared
 * hosting setups) the PHP process sees a plain HTTP request on a
 * non-443 port, so every check above says "not HTTPS" on a site that is
 * genuinely served over HTTPS — and ScoutMagic then issues its session
 * cookie without the `Secure` flag. `X-Forwarded-Proto: https` is what
 * such a terminator sets, and it is the one header this class will
 * honour.
 *
 * It is honoured **only** when the deployment explicitly opts in, via
 * `trust_forwarded_proto` in `config/app.php`. Trusting it
 * unconditionally would be a vulnerability of its own: the header is
 * just a request header, so any client could send it on a cleartext
 * request and obtain `Secure` cookies the browser will never send back
 * (a session that silently cannot work) plus a spuriously emitted HSTS
 * header pinning a host that has no working TLS. The opt-in is a
 * statement by the administrator that a terminator they control is in
 * front of this installation and rewrites the header — it is never
 * inferred from the request.
 *
 * The opt-in lives in `config/app.php` rather than in `SettingService`
 * for two reasons: detection runs before the database is reachable (the
 * setup wizard and the session bootstrap both need it), and it is
 * per-deployment infrastructure configuration rather than a unit's
 * preference. `config/app.php` is excluded from the release artifact,
 * so the value survives an update.
 *
 * No other proxy header is trusted — not `X-Forwarded-For`, not
 * `Forwarded`, not `X-Forwarded-Ssl`. One header, one opt-in.
 */
final class RequestScheme
{
    /**
     * Process-wide, set once at boot from `config/app.php`. Defaults to
     * false so that any entry point which never configures it (CLI
     * tooling, tests, a partially-booted request) keeps exactly the
     * pre-existing detection semantics.
     */
    private static bool $trustForwardedProto = false;

    /**
     * Called once from the composition root, right after `AppConfig` is
     * loaded and before any response, cookie or session is produced.
     */
    public static function setTrustForwardedProto(bool $trust): void
    {
        self::$trustForwardedProto = $trust;
    }

    public static function trustsForwardedProto(): bool
    {
        return self::$trustForwardedProto;
    }

    /**
     * @param array<string, mixed> $server Normally `$_SERVER`, or a
     *                                     Request's own captured copy of it.
     */
    public static function isHttps(array $server): bool
    {
        // Additive on purpose: the header can only ever upgrade the
        // verdict to HTTPS, never downgrade a connection the SAPI itself
        // reports as encrypted.
        if (self::$trustForwardedProto && self::forwardedProtoIsHttps($server)) {
            return true;
        }

        $https = $server['HTTPS'] ?? null;
        // IIS sets the literal string "off" rather than leaving the
        // variable unset; the comparison is case-insensitive because
        // "Off" is also seen in the wild.
        if (!empty($https) && (!is_string($https) || strtolower($https) !== 'off')) {
            return true;
        }

        return (int) ($server['SERVER_PORT'] ?? 0) === 443;
    }

    /**
     * @param array<string, mixed> $server
     */
    private static function forwardedProtoIsHttps(array $server): bool
    {
        $header = $server['HTTP_X_FORWARDED_PROTO'] ?? null;
        if (!is_string($header) || $header === '') {
            return false;
        }

        // A chain of proxies appends: "https, http". The leftmost value
        // is the one the client actually spoke, which is the scheme the
        // browser will use for cookies and HSTS.
        $first = strtolower(trim(explode(',', $header)[0]));

        return $first === 'https';
    }
}
