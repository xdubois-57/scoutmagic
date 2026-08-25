<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `opcache.json` — what the compiled-code cache is actually doing, as
 * opposed to what it was configured to do.
 *
 * `phpinfo.html` already carries the ini directives, and they are not the
 * answer: `opcache.revalidate_freq=60` says a stale window exists, while
 * only the runtime status says whether the cache is full, how many times
 * it has restarted, and how much of it is wasted memory. The distinction
 * decides real questions. A site "randomly" serving old code after an
 * update is the revalidate window; a site slow at unpredictable times is
 * usually a cache thrashing against `max_accelerated_files`, and the two
 * are indistinguishable from configuration alone.
 *
 * It earns its place because this cache has already taken the site down
 * once — an update replaced Core\Help\HelpFrontMatterParser and its help
 * topics together, the parser stayed cached for the next 54 seconds, and
 * every route returned 500 while it did (Core\Maintenance\OpcodeCache).
 * Diagnosing that from the archive meant inferring the window from an ini
 * value; `stale_window_seconds` below states it.
 *
 * **No `scripts` list.** `opcache_get_status(true)` returns one entry per
 * cached file — some fifteen thousand of them here — which would dwarf
 * the rest of the archive to say something `filesystem.txt` already says.
 * The summary is what is diagnostic; the inventory is not.
 */
class OpcacheCollector implements SupportCollectorInterface
{
    public function name(): string
    {
        return 'opcache';
    }

    public function collect(SupportCollectorContext $context): void
    {
        if (!function_exists('opcache_get_status')) {
            // Not an anomaly: plenty of shared hosts ship without it, and
            // `opcache.restrict_api` legitimately hides it from code
            // outside a permitted path.
            $context->markUnavailable('opcache_extension_absent');

            return;
        }

        $status = @opcache_get_status(false);
        if (!is_array($status)) {
            $context->markUnavailable('opcache_status_unavailable');

            return;
        }

        $configuration = function_exists('opcache_get_configuration')
            ? @opcache_get_configuration()
            : false;
        $directives = $configuration === false ? [] : $configuration['directives'];

        if (($status['opcache_enabled'] ?? false) !== true) {
            $context->addNote('OPcache présent mais désactivé pour ce SAPI');
        }

        $context->addFileFromContent('opcache.json', (string) json_encode(
            [
                'enabled' => $status['opcache_enabled'] ?? false,
                'cache_full' => $status['cache_full'] ?? null,
                'restart_pending' => $status['restart_pending'] ?? null,
                'restart_in_progress' => $status['restart_in_progress'] ?? null,
                'memory' => $status['memory_usage'] ?? null,
                'interned_strings' => $status['interned_strings_usage'] ?? null,
                'statistics' => $status['opcache_statistics'] ?? null,
                'jit' => $status['jit'] ?? null,
                'key_directives' => self::keyDirectives($directives),
                'stale_window_seconds' => self::staleWindowSeconds($directives),
                'note' => self::note($directives),
            ],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE
        ));
    }

    /**
     * The handful that decide whether code on disk is the code running.
     * The full list is in `phpinfo.html`; repeating all of it here would
     * bury the five that matter.
     *
     * @param array<string, mixed> $directives
     * @return array<string, mixed>
     */
    private static function keyDirectives(array $directives): array
    {
        $keys = [
            'opcache.enable',
            'opcache.enable_cli',
            'opcache.validate_timestamps',
            'opcache.revalidate_freq',
            'opcache.memory_consumption',
            'opcache.max_accelerated_files',
            'opcache.restrict_api',
        ];

        $selected = [];
        foreach ($keys as $key) {
            $selected[$key] = $directives[$key] ?? null;
        }

        return $selected;
    }

    /**
     * How long, at worst, this installation can keep executing code an
     * update has already replaced on disk.
     *
     * Zero when timestamps are validated every request. `null` when they
     * are not validated at all — which is not "no window" but "until
     * someone restarts PHP", a materially different answer that a number
     * would misrepresent.
     *
     * @param array<string, mixed> $directives
     */
    private static function staleWindowSeconds(array $directives): ?int
    {
        if (($directives['opcache.validate_timestamps'] ?? null) !== true) {
            return null;
        }

        return (int) ($directives['opcache.revalidate_freq'] ?? 0);
    }

    /**
     * @param array<string, mixed> $directives
     */
    private static function note(array $directives): string
    {
        $window = self::staleWindowSeconds($directives);

        if ($window === null) {
            return 'opcache.validate_timestamps est désactivé : le code modifié sur le disque n\'est '
                . 'JAMAIS rechargé tant que PHP n\'est pas redémarré. Une mise à jour installée ne '
                . "prend effet qu'au redémarrage du service.";
        }

        if ($window === 0) {
            return 'Chaque requête revérifie la date des fichiers : le code sur le disque est le code exécuté.';
        }

        return "Après une mise à jour, PHP peut continuer à exécuter l'ancien code pendant "
            . "jusqu'à {$window} secondes (opcache.revalidate_freq), pendant que les gabarits, "
            . 'sujets d\'aide et manifestes remplacés en même temps sont, eux, immédiatement à jour.';
    }
}
