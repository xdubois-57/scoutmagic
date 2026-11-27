<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

use Core\System\CronExecutionFacts;

/**
 * Turns what was measured — the web's facts, the cron's facts, the
 * database server's answer — into a dated, normalised list of components
 * per context (issue #1358, lot 1).
 *
 * **Each context speaks for itself.** The PHP of the site and the PHP of
 * the scheduled tasks are two subjects, measured by two processes, and the
 * cron's version never stands in for the web's: when the web's measurement
 * is missing or too old, PHP du site is « non vérifiable », whatever the
 * cron says.
 *
 * The first delivery covers PHP (both contexts), the database server,
 * OpenSSL and cURL; images, ZIP, FFmpeg, the PDF tools and the system come
 * later, as new subjects here.
 */
final class HostInventory
{
    /**
     * Every subject this check can report, in display order — also the
     * alert keys' suffixes, so never renamed once shipped
     * ({@see HostSecurityAlertCheck}).
     */
    public const SUBJECTS = [
        'php_web' => 'PHP du site',
        'php_cron' => 'PHP des tâches planifiées',
        'database' => 'Serveur de base de données',
        'openssl_web' => 'OpenSSL du site',
        'openssl_cron' => 'OpenSSL des tâches planifiées',
        'curl_web' => 'cURL du site',
        'curl_cron' => 'cURL des tâches planifiées',
        'curl_tls_web' => 'OpenSSL utilisé par cURL (site)',
        'curl_tls_cron' => 'OpenSSL utilisé par cURL (tâches planifiées)',
    ];

    /**
     * The web's facts are written by visits, at most every quarter of an
     * hour; a week without one says nothing current about the web PHP.
     */
    public const WEB_MAX_AGE_SECONDS = 7 * 86400;

    /**
     * The cron refreshes its facts every ten minutes
     * ({@see CronExecutionFacts::REFRESH_SECONDS}); two days without that
     * means the cron that measured them is not the one running now.
     */
    public const CRON_MAX_AGE_SECONDS = 2 * 86400;

    /**
     * @param string|null $databaseDriver  PDO::ATTR_DRIVER_NAME of the connection the check runs on
     * @param string|null $databaseVersion PDO::ATTR_SERVER_VERSION — the SERVER, not PHP's client library
     * @return list<ObservedComponent>
     */
    public static function collect(
        ?WebRuntimeFacts $web,
        ?CronExecutionFacts $cron,
        ?string $databaseDriver,
        ?string $databaseVersion,
        int $now
    ): array {
        $components = [];

        $webVersions = null;
        $webNote = 'Aucune visite n\'a encore mesuré le PHP du site.';
        if ($web !== null) {
            if ($now - $web->measuredAt <= self::WEB_MAX_AGE_SECONDS) {
                $webVersions = $web->versions;
            } else {
                $webNote = 'La dernière mesure du PHP du site date du ' . gmdate('d/m/Y', $web->measuredAt)
                    . ' : trop ancienne pour conclure.';
            }
        }

        $cronVersions = null;
        $cronNote = 'La tâche planifiée n\'a pas encore mesuré ses versions.';
        if ($cron !== null && $cron->runtime !== null) {
            if ($now - $cron->probedAt <= self::CRON_MAX_AGE_SECONDS) {
                $cronVersions = $cron->runtime;
            } else {
                $cronNote = 'La dernière mesure de la tâche planifiée date du ' . gmdate('d/m/Y', $cron->probedAt)
                    . ' : trop ancienne pour conclure.';
            }
        }

        foreach (['web' => [$webVersions, $web?->measuredAt, $webNote], 'cron' => [$cronVersions, $cron?->probedAt, $cronNote]] as $context => [$versions, $at, $note]) {
            if ($versions === null) {
                foreach (['php', 'openssl', 'curl'] as $base) {
                    $components[$base . '_' . $context] = new ObservedComponent(
                        $base . '_' . $context,
                        $base === 'php' ? ComponentFamily::PHP : ($base === 'openssl' ? ComponentFamily::OPENSSL : ComponentFamily::CURL),
                        ObservedComponent::UNKNOWN,
                        null,
                        null,
                        $note
                    );
                }
                continue;
            }

            $components['php_' . $context] = new ObservedComponent(
                'php_' . $context,
                ComponentFamily::PHP,
                ObservedComponent::PRESENT,
                $versions->php,
                $at
            );
            $components['openssl_' . $context] = self::openssl('openssl_' . $context, $versions, $at);
            $components['curl_' . $context] = $versions->curl === null
                ? new ObservedComponent('curl_' . $context, ComponentFamily::CURL, ObservedComponent::ABSENT, null, $at)
                : new ObservedComponent(
                    'curl_' . $context,
                    ComponentFamily::CURL,
                    ObservedComponent::PRESENT,
                    $versions->curl,
                    $at,
                    $versions->curlSsl !== null ? 'Moteur TLS de cURL : ' . $versions->curlSsl . '.' : ''
                );

            // cURL's TLS engine is a component of its own only when it is an
            // OpenSSL that differs from the extension's: the same library
            // twice is one subject, and GnuTLS or Schannel are not in this
            // first delivery — the cURL line names them instead.
            $tls = self::curlOpenssl($versions->curlSsl);
            if ($tls !== null && $tls !== self::opensslVersion($versions->openssl)) {
                $components['curl_tls_' . $context] = new ObservedComponent(
                    'curl_tls_' . $context,
                    ComponentFamily::OPENSSL,
                    ObservedComponent::PRESENT,
                    $tls,
                    $at
                );
            }
        }

        $components['database'] = self::database($databaseDriver, $databaseVersion, $now);

        // Display order is SUBJECTS' order, whatever order they were built in.
        $ordered = [];
        foreach (array_keys(self::SUBJECTS) as $subject) {
            if (isset($components[$subject])) {
                $ordered[] = $components[$subject];
            }
        }

        return $ordered;
    }

    private static function openssl(string $subject, RuntimeVersions $versions, ?int $at): ObservedComponent
    {
        if (!$versions->opensslLoaded) {
            return new ObservedComponent($subject, ComponentFamily::OPENSSL, ObservedComponent::ABSENT, null, $at);
        }
        $text = $versions->openssl;
        if ($text === null) {
            // Loaded, and the LIBRARY's version unreadable: unknown, never the
            // header's number, which a host's in-place update leaves behind.
            return new ObservedComponent(
                $subject,
                ComponentFamily::OPENSSL,
                ObservedComponent::UNKNOWN,
                null,
                $at,
                'La version de la bibliothèque OpenSSL chargée n\'a pas pu être lue.'
            );
        }

        $version = self::opensslVersion($text);
        if ($version === null) {
            // LibreSSL, BoringSSL: present, and not OpenSSL's releases.
            return new ObservedComponent(
                $subject,
                ComponentFamily::OPENSSL,
                ObservedComponent::UNKNOWN,
                $text,
                $at,
                'Bibliothèque « ' . $text . ' » : seules les versions d\'OpenSSL sont comparées pour l\'instant.'
            );
        }

        return new ObservedComponent($subject, ComponentFamily::OPENSSL, ObservedComponent::PRESENT, $version, $at);
    }

    /** « OpenSSL 3.0.13 30 Jan 2024 » → « 3.0.13 »; null when it is not OpenSSL. */
    public static function opensslVersion(?string $text): ?string
    {
        if ($text === null || preg_match('/^OpenSSL\s+(\d[^\s]*)/i', trim($text), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /** « OpenSSL/3.0.13 » → « 3.0.13 »; null for any other engine. */
    private static function curlOpenssl(?string $ssl): ?string
    {
        if ($ssl === null || preg_match('#^OpenSSL/(\d\S*)#i', trim($ssl), $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    /**
     * The engine is read from the SERVER's own version string: MariaDB says
     * so in it, MySQL does not. Anything that is not a MySQL-protocol
     * server (the tests' SQLite) is unknown rather than guessed.
     */
    private static function database(?string $driver, ?string $version, int $now): ObservedComponent
    {
        if ($driver !== 'mysql' || $version === null || trim($version) === '') {
            return new ObservedComponent(
                'database',
                ComponentFamily::MYSQL,
                ObservedComponent::UNKNOWN,
                $version,
                null,
                'Le moteur ou la version du serveur de base de données n\'a pas pu être lu.'
            );
        }

        $family = stripos($version, 'mariadb') !== false ? ComponentFamily::MARIADB : ComponentFamily::MYSQL;

        return new ObservedComponent('database', $family, ObservedComponent::PRESENT, $version, $now);
    }
}
