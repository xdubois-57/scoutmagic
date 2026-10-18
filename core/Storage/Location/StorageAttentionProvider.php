<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Storage\Location;

use Core\Attention\AttentionPoint;
use Core\Attention\AttentionPointProvider;
use Core\Config\AppClock;
use Core\Service\DateInput;
use Core\Storage\Location\Config\GoogleDriveLocationConfig;
use Core\Storage\Location\Protection\StorageProtectionRepository;

/**
 * The storage locations, one by one (issue #718 § C point 10): a location
 * whose last check failed, and a Google Drive grant that ends or whose end
 * nobody knows.
 *
 * The one place these rules live, so they are not scattered over the
 * pages that happen to show a location. Read from what is stored — each
 * location's last check, the grant's lifetime as announced when it was
 * obtained (DriveGrantExpiry) — and never by testing a location: the page
 * is rendered on demand and must stay cheap, and must not fail because a
 * storage backend does.
 *
 * Deliberately not here, because they already exist elsewhere:
 * - the local disk filling up (`DiskUsageCheck`);
 * - the off-site destination's free space (`RemoteQuotaCheck`, which the
 *   daily pass measures and OperationalAttentionProvider already shows);
 * - no off-site backup configured — the one feature that requires an
 *   external location (MaintenanceAttentionProvider).
 */
final class StorageAttentionProvider implements AttentionPointProvider
{
    private const LOCATIONS_URL = '/config/stockage/emplacements';

    public function __construct(
        private StorageLocationRepository $locations,
        /** Tests only: the moment to count days from. */
        private ?\DateTimeImmutable $now = null,
        /** Null where safety copies are not wired: no point about them. */
        private ?StorageProtectionRepository $protections = null
    ) {
    }

    public function sourceLabel(): string
    {
        return 'Stockage';
    }

    public function collect(int $scoutYearId): array
    {
        $now = $this->now ?? AppClock::now();
        $points = [];

        $locations = $this->locations->findAll();
        $byId = [];
        foreach ($locations as $location) {
            $byId[$location->id] = $location;
        }

        foreach ($locations as $location) {
            // Declared but never connected: a setup to finish, not a fault.
            // Its check fails by construction (« aucun compte raccordé »),
            // and an urgent « en erreur » would describe the setup rather
            // than anything broken.
            if ($location->config instanceof GoogleDriveLocationConfig && !$location->config->isConnected()) {
                $points[] = $this->notConnected($location);
                continue;
            }
            $expiry = DriveGrantExpiry::of($location);
            if ($expiry->state === DriveGrantExpiry::KNOWN) {
                $points[] = $this->grantEnds($location, $expiry, $now);
            } elseif ($expiry->state === DriveGrantExpiry::UNKNOWN) {
                $points[] = $this->grantEndUnknown($location);
            }
            // Said once: a check that failed after the grant's end is the
            // grant having ended, and grantEnds() already carries its error.
            if ($location->lastCheckOk === false && !$expiry->failedSince) {
                $points[] = $this->failing($location);
            }
        }

        return array_merge($points, $this->copiesOnTheSameServer($byId));
    }

    /**
     * A safety copy from the site's server to the site's server, declared
     * before such a pair was refused (#718 § C point 11): it protects
     * nothing against losing the server, and nothing else says so.
     *
     * @param array<int, StorageLocation> $byId
     * @return list<AttentionPoint>
     */
    private function copiesOnTheSameServer(array $byId): array
    {
        if ($this->protections === null) {
            return [];
        }

        $points = [];
        foreach ($this->protections->findAll() as $protection) {
            $source = $byId[$protection->sourceLocationId] ?? null;
            $destination = $byId[$protection->destinationLocationId] ?? null;
            if ($source === null || $destination === null || !$protection->enabled
                || $source->type !== StorageLocationType::Local || $destination->type !== StorageLocationType::Local
            ) {
                continue;
            }
            $points[] = new AttentionPoint(
                title: 'La copie de secours de « ' . $source->label . ' » reste sur le serveur du site',
                why: 'Elle part vers « ' . $destination->label . ' », un autre dossier du même serveur : si le '
                    . 'serveur est perdu, la copie est perdue avec ce qu\'elle protège. Choisissez une destination '
                    . 'externe, comme Google Drive, un partage WebDAV ou un stockage S3.',
                actionLabel: 'Changer de destination',
                actionUrl: self::LOCATIONS_URL . '/' . $source->id . '/modification',
                severity: AttentionPoint::SEVERITY_ATTENTION
            );
        }

        return $points;
    }

    private function grantEnds(
        StorageLocation $location,
        DriveGrantExpiry $expiry,
        \DateTimeImmutable $now
    ): AttentionPoint {
        $date = $expiry->expiresAt?->setTimezone(AppClock::zone())->format('d/m/Y') ?? '';
        $passed = $expiry->hasPassed($now);

        $why = $passed
            ? "Google avait annoncé cette fin au raccordement. Depuis, aucune vérification réussie n'a montré que "
                . "l'autorisation tient toujours : les fichiers de cet emplacement ne sont peut-être plus lisibles."
            : "Google l'a annoncé au raccordement. Passé cette date, le site ne pourra plus lire ni écrire dans ce "
                . "dossier tant que quelqu'un ne refait pas le raccordement.";
        if ($expiry->failedSince && $location->lastCheckError !== null && $location->lastCheckError !== '') {
            $why .= ' Dernière vérification : ' . $location->lastCheckError;
        }
        $why .= " Si le projet Google a changé de statut entre-temps, une vérification réussie après l'échéance "
            . 'le prouvera et ce point disparaîtra.';

        return new AttentionPoint(
            title: sprintf(
                $passed
                    ? "L'autorisation Google Drive de « %s » a pris fin le %s"
                    : "L'autorisation Google Drive de « %s » prend fin le %s",
                $location->label,
                $date
            ),
            why: $why,
            actionLabel: 'Refaire le raccordement',
            actionUrl: self::LOCATIONS_URL,
            dueDate: $expiry->expiresAt,
            severity: $passed ? AttentionPoint::SEVERITY_URGENT : AttentionPoint::SEVERITY_ATTENTION
        );
    }

    private function notConnected(StorageLocation $location): AttentionPoint
    {
        return new AttentionPoint(
            title: "L'emplacement « " . $location->label . " » n'est raccordé à aucun compte Google",
            why: "Il est déclaré, mais aucun compte Google Drive n'a encore donné son accord : rien ne peut y être "
                . 'rangé tant que le raccordement n\'est pas fait.',
            actionLabel: 'Raccorder le compte',
            actionUrl: self::LOCATIONS_URL,
            severity: AttentionPoint::SEVERITY_ATTENTION
        );
    }

    private function grantEndUnknown(StorageLocation $location): AttentionPoint
    {
        return new AttentionPoint(
            title: "On ne sait pas quand l'autorisation Google Drive de « " . $location->label . ' » prend fin',
            why: 'Cet emplacement a été raccordé avant que le site ne note la durée annoncée par Google. Refaire le '
                . "raccordement permet de savoir si l'autorisation a une échéance, et laquelle.",
            actionLabel: 'Refaire le raccordement',
            actionUrl: self::LOCATIONS_URL,
            severity: AttentionPoint::SEVERITY_ATTENTION
        );
    }

    private function failing(StorageLocation $location): AttentionPoint
    {
        $checkedAt = DateInput::fromStorage($location->lastCheckedAt, AppClock::zone());
        $error = $location->lastCheckError !== null && $location->lastCheckError !== ''
            ? $location->lastCheckError
            : "L'emplacement n'a pas répondu comme attendu.";

        return new AttentionPoint(
            title: "L'emplacement de stockage « " . $location->label . ' » est en erreur',
            why: ($checkedAt !== null ? 'Vérification du ' . $checkedAt->format('d/m/Y à H:i') . ' : ' : '') . $error
                . ' Ce qui y est rangé ne peut être ni lu ni enregistré tant que ce n\'est pas réglé.',
            actionLabel: 'Voir les emplacements',
            actionUrl: self::LOCATIONS_URL,
            severity: AttentionPoint::SEVERITY_URGENT
        );
    }
}
