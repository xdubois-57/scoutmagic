<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Maintenance;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Config\SettingService;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Storage\Location\Config\LocalLocationConfig;
use Core\Storage\Location\StorageLocation;
use Core\Storage\Location\StorageLocationType;

/**
 * What the maintenance settings leave undone, before anything has failed
 * (issue #718 § C points 11, 12 and 14):
 *
 * - **automatic backup switched off** — `BackupAgeCheck` only speaks once
 *   the last backup has grown old, so a site that stopped backing up
 *   yesterday is silent for weeks;
 * - **no off-site backup destination** — the off-site alerts
 *   (`RemoteBackupAgeCheck` and its siblings) measure a destination only
 *   once one is chosen, by design: an operational alert every new site
 *   carried from day one would be an alert nobody reads. A point on this
 *   page is not a notification, which is why the maintainer asked for this
 *   one to show at once. A destination that is a directory of the site's
 *   own disk is not off-site either (see isOnThisServersDisk());
 * - **automatic updates switched off**, or **a published version the
 *   configured level will not install on its own** (a major release under
 *   a « minor » level, `GitHubWebhookService::isBumpAllowed()`).
 *
 * Every fact is a stored one: the settings, the chosen destination row,
 * `update_latest_version` as the last check or webhook wrote it, the
 * installed VERSION file. Nothing here asks GitHub or a storage backend
 * anything — the page is rendered on demand and must stay cheap.
 */
class MaintenanceAttentionProvider implements AttentionPointProvider
{
    public function __construct(
        private SettingService $settingService,
        private RemoteBackupDestination $remoteBackup,
        private string $basePath
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Maintenance';
    }

    public function collect(int $scoutYearId): array
    {
        return array_merge(
            $this->automaticBackupOff(),
            $this->noOffsiteBackup(),
            $this->updates()
        );
    }

    /** @return AttentionPoint[] */
    private function automaticBackupOff(): array
    {
        // Same fallback as AutoBackupHandler: an absent row means weekly.
        if ((string) ($this->settingService->get('backup_auto_frequency') ?: 'weekly') !== 'none') {
            return [];
        }

        return [new AttentionPoint(
            title: 'La sauvegarde automatique est désactivée',
            why: "Le site ne se sauvegarde plus de lui-même. Les alertes de sauvegarde ne se déclenchent qu'une "
                . 'fois la dernière sauvegarde devenue ancienne : rien d\'autre ne le signale avant.',
            actionLabel: 'Choisir une fréquence',
            actionUrl: '/config/maintenance/sauvegarde-automatique',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }

    /** @return AttentionPoint[] */
    private function noOffsiteBackup(): array
    {
        $location = $this->remoteBackup->location();
        if ($location !== null && !$this->isOnThisServersDisk($location)) {
            return [];
        }

        if ($location !== null) {
            return [new AttentionPoint(
                title: 'Les sauvegardes « hors site » restent sur le disque du site',
                why: "La destination choisie, « " . $location->label . " », est un dossier du disque où se trouve "
                    . "le site : si l'hébergement est perdu, les sauvegardes le sont avec lui. Choisissez un "
                    . 'emplacement externe — Google Drive, un partage WebDAV, un stockage S3 — ou un disque monté '
                    . "depuis une autre machine.",
                actionLabel: 'Changer de destination',
                actionUrl: '/config/maintenance/sauvegarde-automatique',
                severity: AttentionPoint::SEVERITY_ATTENTION
            )];
        }

        return [new AttentionPoint(
            title: "Aucune sauvegarde hors site n'est configurée",
            why: "Les sauvegardes restent sur le serveur du site : si l'hébergement est perdu, elles sont perdues "
                . 'avec lui. Choisissez un emplacement de stockage externe où les envoyer.',
            actionLabel: 'Configurer la sauvegarde hors site',
            actionUrl: '/config/maintenance/sauvegarde-automatique',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }

    /** @return AttentionPoint[] */
    private function updates(): array
    {
        $enabled = (bool) ((int) ($this->settingService->get('auto_update_enabled') ?: '0'));
        $level = (string) ($this->settingService->get('auto_update_level') ?: 'minor');
        $pending = $this->pendingVersion($level);

        if (!$enabled) {
            return [new AttentionPoint(
                title: 'Les mises à jour automatiques sont désactivées',
                why: 'Les corrections, y compris de sécurité, ne s\'installent que si quelqu\'un le fait à la main.'
                    . ($pending !== null ? ' La version ' . $pending . ' est disponible.' : ''),
                actionLabel: 'Voir les mises à jour',
                actionUrl: '/config/maintenance/mise-a-jour',
                severity: AttentionPoint::SEVERITY_ATTENTION
            )];
        }

        // The development channel installs every build on its own, and
        // DevelopmentModeCheck already says so on this page.
        if ($pending === null || $level === 'dev'
            || GitHubWebhookService::isBumpAllowed($this->installedVersion(), $pending, $level)) {
            return [];
        }

        return [new AttentionPoint(
            title: 'La version ' . $pending . ' attend une installation à la main',
            why: 'Elle change plus que ce que le niveau choisi pour les mises à jour automatiques accepte, '
                . "si bien qu'elle ne s'installera pas d'elle-même.",
            actionLabel: 'Installer la mise à jour',
            actionUrl: '/config/maintenance/mise-a-jour',
            severity: AttentionPoint::SEVERITY_ATTENTION
        )];
    }

    /**
     * The newer published version, when the last check saw one — the same
     * comparison as the Mise à jour page (MaintenanceController::pageContext()).
     */
    private function pendingVersion(string $level): ?string
    {
        $latest = (string) ($this->settingService->get('update_latest_version') ?: '');
        if ($latest === '' || !VersionFile::isNewerThan($latest, $this->installedVersion(), $level === 'dev')) {
            return null;
        }

        return $latest;
    }

    /**
     * Whether an off-site destination is in fact a directory of the site's
     * own disk — a « Disque du serveur » location may be chosen, since it
     * can resume an upload like any other.
     *
     * A path relative to `storage/` is on that disk by construction. An
     * absolute one may be a mount from another machine, which is a real
     * off-site copy; told apart by the filesystem device, the one fact a
     * page can read without touching the network: the same device as
     * `storage/` is the same disk. A path that cannot be read raises no
     * claim either way — the off-site alerts already measure what reaches it.
     */
    private function isOnThisServersDisk(StorageLocation $location): bool
    {
        if ($location->type !== StorageLocationType::Local || !$location->config instanceof LocalLocationConfig) {
            return false;
        }
        if (!$location->config->isAbsolute()) {
            return true;
        }

        $target = @stat($location->config->path);
        $storage = @stat($this->basePath . '/storage');

        return $target !== false && $storage !== false && $target['dev'] === $storage['dev'];
    }

    private function installedVersion(): string
    {
        return VersionFile::read($this->basePath);
    }
}
