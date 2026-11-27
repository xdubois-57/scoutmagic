<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance\Health\Security;

use Core\Config\SettingService;

/**
 * The web PHP's own versions, stamped with the moment they were read
 * (issue #1358) — the counterpart of what {@see \Core\System\
 * CronExecutionFacts} stores for the cron.
 *
 * Recorded by `public/index.php` inside the request-bound block, past
 * `send()`, which {@see \Core\Alert\RequestBoundChecks} already throttles
 * to once a quarter of an hour: the measurement itself is constants and
 * one in-memory call, so its whole cost is that one settings write per
 * quarter of an hour on a site somebody is browsing.
 *
 * The weekly check reads it and never measures the web itself — it runs in
 * the cron, where `PHP_VERSION` is the cron's. A measurement older than
 * {@see HostInventory::WEB_MAX_AGE_SECONDS} is reported as too old to
 * conclude from, never silently used.
 */
final class WebRuntimeFacts
{
    public const SETTING = 'web_runtime_facts';

    public function __construct(
        public readonly int $measuredAt,
        public readonly string $sapi,
        public readonly RuntimeVersions $versions,
    ) {
    }

    public static function register(SettingService $settings): void
    {
        $settings->register(
            self::SETTING,
            '',
            'text',
            'Versions du PHP du site',
            'Les versions exactes de PHP, d\'OpenSSL et de cURL du PHP qui répond aux visiteurs, en JSON, avec '
                . 'l\'heure de la mesure. Écrit au plus tous les quarts d\'heure par une visite, lu par le contrôle '
                . 'hebdomadaire de sécurité de l\'hébergement. Lecture seule.',
            null,
            null,
            null,
            false,
            999
        );
    }

    /**
     * Declares the setting, measures this (web) process and stores it. Best
     * effort, the declaration included — a registration whose insert fails
     * rethrows — because a diagnostic must never be why a page fails, and
     * this runs after the page was sent. A failure leaves the measurement
     * unavailable until a later request retries.
     */
    public static function record(SettingService $settings, int $now): void
    {
        try {
            self::register($settings);
            $encoded = json_encode(
                (new self($now, PHP_SAPI, RuntimeVersions::measure()))->toArray(),
                JSON_INVALID_UTF8_SUBSTITUTE
            );
            if ($encoded !== false) {
                $settings->setInternal(self::SETTING, $encoded);
            }
        } catch (\Throwable) {
            // Never fatal: see the doc comment.
        }
    }

    /** The stored facts, or null while no visit has measured them yet. */
    public static function read(SettingService $settings): ?self
    {
        return self::parse((string) ($settings->get(self::SETTING) ?? ''));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'measured_at' => $this->measuredAt,
            'sapi' => $this->sapi,
            'versions' => $this->versions->toArray(),
        ];
    }

    public static function parse(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_int($data['measured_at'] ?? null) || $data['measured_at'] <= 0) {
            return null;
        }
        $versions = RuntimeVersions::fromArray($data['versions'] ?? null);
        if ($versions === null) {
            return null;
        }

        return new self(
            $data['measured_at'],
            is_string($data['sapi'] ?? null) && $data['sapi'] !== '' ? $data['sapi'] : 'web',
            $versions
        );
    }
}
