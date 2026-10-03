<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\System;

use Core\Config\SettingRepository;
use Core\Config\SettingService;

/**
 * What the CRON's PHP can execute, measured by public/cron.php and read by
 * the web (#700).
 *
 * **The web PHP and the cron PHP are not the same machine, in practice.**
 * On a shared host the web SAPI is often sandboxed without a shell while
 * the CLI that runs the crontab can execute anything: a support archive
 * of such a site found `gs`, `git` and `curl` from the cron while the
 * health page, probing from the web request, said « rien ne s'exécute ».
 * Video transcoding runs in a task, so whether it can run is the cron's
 * answer, never the web's. The cron probes and stores what it found with
 * the time; the web reads it. Never probed yet means **unknown**, not
 * absent.
 *
 * Refreshed at most every {@see REFRESH_SECONDS}: a few process spawns,
 * on a pass that runs every minute, would otherwise be a settings write a
 * minute for an answer that changes when a host installs a package.
 */
final class CronExecutionFacts
{
    public const SETTING = 'cron_execution_facts';
    public const REFRESH_SECONDS = 600;

    public function __construct(
        public readonly int $probedAt,
        public readonly string $sapi,
        public readonly bool $shellDeclared,
        public readonly bool $shellWorks,
        /** The execution function {@see ShellExecutor} picked, null when none is callable. */
        public readonly ?string $shellFunction,
        /** The probe's own words: the exact error to hand a host. */
        public readonly string $shellDetail,
        public readonly ?string $ffmpegPath,
        public readonly ?string $ffprobePath,
    ) {
    }

    /** Measures this process — meant to run in public/cron.php. */
    public static function probe(int $now): self
    {
        $shell = ShellExecutor::probe();

        return new self(
            $now,
            PHP_SAPI,
            $shell['declared'],
            $shell['works'],
            $shell['function'],
            $shell['detail'],
            $shell['works'] ? ExecutableLocator::find('ffmpeg') : null,
            $shell['works'] ? ExecutableLocator::find('ffprobe') : null,
        );
    }

    /** Video can be transcoded: the cron runs commands and finds both programs. */
    public function videoReady(): bool
    {
        return $this->shellWorks && $this->ffmpegPath !== null && $this->ffprobePath !== null;
    }

    public static function register(SettingService $settings): void
    {
        $settings->register(
            self::SETTING,
            '',
            'text',
            'Capacités d\'exécution du cron',
            'Ce que le PHP du cron sait exécuter (fonction, erreur exacte, ffmpeg, ffprobe), en JSON, avec '
                . 'l\'heure de la mesure. Écrit par public/cron.php, lu par la page Santé de l\'hébergement et '
                . 'par la galerie. Lecture seule.',
            null,
            null,
            null,
            false,
            999
        );
    }

    /**
     * Probes and stores, unless the stored facts are younger than
     * {@see REFRESH_SECONDS}. Through the repository, like
     * {@see \Core\Scheduler\CronRunHistory::record()}, and best effort for
     * the same reason: a diagnostic must never be why a cron pass fails.
     *
     * @param (callable(int): self)|null $probe {@see probe()} unless a test says otherwise
     */
    public static function recordIfDue(SettingRepository $repository, int $now, ?callable $probe = null): void
    {
        try {
            $stored = $repository->findByModuleAndKey(null, self::SETTING);
            $previous = self::parse(is_array($stored) ? (string) ($stored['setting_value'] ?? '') : '');
            if ($previous !== null && $now - $previous->probedAt < self::REFRESH_SECONDS) {
                return;
            }

            $facts = $probe !== null ? $probe($now) : self::probe($now);
            $repository->updateValue(null, self::SETTING, (string) json_encode($facts->toArray()));
        } catch (\Throwable) {
            // Never fatal: see the doc comment.
        }
    }

    /** The stored facts, or null while the cron has never measured. */
    public static function read(SettingService $settings): ?self
    {
        return self::parse((string) ($settings->get(self::SETTING) ?? ''));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'probed_at' => $this->probedAt,
            'sapi' => $this->sapi,
            'shell_declared' => $this->shellDeclared,
            'shell_works' => $this->shellWorks,
            'shell_function' => $this->shellFunction,
            'shell_detail' => $this->shellDetail,
            'ffmpeg' => $this->ffmpegPath,
            'ffprobe' => $this->ffprobePath,
        ];
    }

    /** A malformed row reads as « never measured » rather than throwing. */
    public static function parse(string $json): ?self
    {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_int($data['probed_at'] ?? null) || $data['probed_at'] <= 0) {
            return null;
        }

        $string = static fn(mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        return new self(
            $data['probed_at'],
            $string($data['sapi'] ?? null) ?? 'cli',
            ($data['shell_declared'] ?? false) === true,
            ($data['shell_works'] ?? false) === true,
            $string($data['shell_function'] ?? null),
            (string) ($data['shell_detail'] ?? ''),
            $string($data['ffmpeg'] ?? null),
            $string($data['ffprobe'] ?? null),
        );
    }
}
