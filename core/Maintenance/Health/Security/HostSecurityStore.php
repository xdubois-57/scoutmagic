<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

use Core\Config\SettingService;

/**
 * Where the host security check keeps what it found: two internal
 * settings, the way {@see \Core\System\CronExecutionFacts} keeps the
 * cron's facts — no table, because both are one compact document each,
 * read whole and written whole once a week (issue #1358 left the choice
 * open; a table would buy nothing a single row does not).
 *
 * - `host_security_snapshot`: the last evaluation, dated.
 * - `host_security_reference_cache`: the reference data it was computed
 *   from, each entry with its own fetch date, so a source that fails keeps
 *   its last usable answer, dated, rather than turning into « À jour ».
 *
 * Both must fit a `settings.setting_value` TEXT (64 KiB). The advisories
 * kept per CPE are capped ({@see HostSecurityService::KEPT_ADVISORIES},
 * {@see HostSecurityService::KEPT_HIGH_IDS}), the cache keeps only what the
 * current inventory needs, and a document that would still overflow is not
 * written: {@see saveCache()} says so, and the service retries with fewer
 * details per CPE rather than lose what the run fetched.
 */
final class HostSecurityStore
{
    public const SNAPSHOT_SETTING = 'host_security_snapshot';
    public const CACHE_SETTING = 'host_security_reference_cache';

    /** Below the 65 535 bytes of a MySQL/MariaDB TEXT, with room to spare. */
    private const MAX_DOCUMENT_BYTES = 60000;

    /**
     * @param int $maxBytes the size above which a document is not written — a parameter only so a
     *                      test can reach the limit without building sixty kilobytes of advisories
     */
    public function __construct(
        private readonly SettingService $settings,
        private readonly int $maxBytes = self::MAX_DOCUMENT_BYTES
    ) {
    }

    public function register(): void
    {
        $this->settings->register(
            self::SNAPSHOT_SETTING,
            '',
            'text',
            'Contrôle de sécurité de l\'hébergement',
            'Le dernier résultat du contrôle hebdomadaire des versions et des avis de sécurité de l\'hébergement '
                . '(PHP, base de données, OpenSSL, cURL), en JSON, daté. Écrit par la tâche planifiée, lu par la page '
                . 'Santé de l\'hébergement et par les alertes. Lecture seule.',
            null,
            null,
            null,
            false,
            999
        );
        $this->settings->register(
            self::CACHE_SETTING,
            '',
            'text',
            'Données de référence du contrôle de sécurité',
            'Les versions maintenues (php.net, endoflife.date) et les avis de sécurité (NVD) consultés par le '
                . 'contrôle hebdomadaire, en JSON, chacun avec sa date. Conservés pour qu\'une source indisponible ne '
                . 'fasse pas perdre la dernière réponse exploitable. Lecture seule.',
            null,
            null,
            null,
            false,
            999
        );
    }

    /** The last check, or null while none has run. */
    public function latest(): ?HostSecuritySnapshot
    {
        return HostSecuritySnapshot::fromArray($this->decode(self::SNAPSHOT_SETTING));
    }

    /** @return bool false when the snapshot is too large to be written — the caller trims and retries */
    public function save(HostSecuritySnapshot $snapshot): bool
    {
        return $this->write(self::SNAPSHOT_SETTING, $snapshot->toArray());
    }

    /** @return array<string, mixed> */
    public function cache(): array
    {
        $data = $this->decode(self::CACHE_SETTING);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, mixed> $cache
     * @return bool false when the document is too large to be written — the caller trims and retries
     */
    public function saveCache(array $cache): bool
    {
        return $this->write(self::CACHE_SETTING, $cache);
    }

    private function decode(string $key): mixed
    {
        return json_decode((string) ($this->settings->get($key) ?? ''), true);
    }

    /** @param array<string, mixed> $document */
    private function write(string $key, array $document): bool
    {
        $encoded = json_encode($document, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > $this->maxBytes) {
            return false;
        }

        $this->settings->setInternal($key, $encoded);

        return true;
    }
}
