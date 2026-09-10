<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\AlertThresholds;
use Core\Alert\OperationalCheck;
use Core\Maintenance\BackupRepository;
use Core\Service\DateInput;

/**
 * Is a portable archive still sitting on the server?
 *
 * **The one check whose subject is a success.** Every other alert on this
 * installation reports something that failed or is drifting — a disk
 * filling, a backup ageing, a job gone quiet. This one fires on a backup
 * that worked perfectly and was then left where it was written.
 *
 * That is the point of the portable archive and its whole risk in one
 * sentence: it contains `storage/keys/master.key`, so it can be restored
 * on a new host by somebody who has nothing else — and a copy of it left
 * in `storage/` is the site's encryption-at-rest sitting in a file next to
 * the database it protects, on the very server the backup exists to
 * outlive. Downloaded and deleted, it is the best protection this site
 * has. Left there, it is the worst thing on the disk.
 *
 * **It reads the OLDEST one, not the newest.** Retention keeps a single
 * portable archive ({@see \Core\Maintenance\BackupFamily::Portable}), so
 * in practice there is one row; asking for the oldest is what makes the
 * answer right anyway on an installation that briefly holds two — during
 * an operation that protects one of them from the purge, say. Taking a
 * fresh backup must not silence an alert about the one that has been
 * lying there for a month.
 */
final class PortableBackupLingerCheck implements OperationalCheck
{
    public const KEY = 'portable_backup_lingering';

    public function __construct(
        private readonly BackupRepository $backups,
        private readonly ?\DateTimeImmutable $now = null
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Sauvegarde portable';
    }

    public function read(): AlertReading
    {
        $completedAt = $this->backups->oldestPortableCompletedAt();

        // No portable archive on the server is not "nothing to say": it is
        // the state this check wants, and it has to be reported as such or
        // the alert would never re-arm once it fired. `inconclusive()` would
        // leave the previous reading standing for ever.
        if ($completedAt === null) {
            return new AlertReading(
                overTrigger: false,
                underRearm: true,
                value: 'aucune',
                title: 'Aucune sauvegarde portable ne traîne sur le serveur.',
                why: 'C\'est l\'état voulu : une sauvegarde portable se télécharge, se range ailleurs, puis se '
                    . 'supprime d\'ici.',
                actionUrl: '/config/maintenance',
                actionLabel: 'Voir les sauvegardes'
            );
        }

        // Through DateInput for the reason every date in this codebase
        // converges there: PHP's own parser raises on a value carrying a
        // NUL byte rather than returning false, so the usual guard lets
        // exactly that through as an uncaught exception.
        $completed = DateInput::fromStorage($completedAt);
        if ($completed === null) {
            return AlertReading::inconclusive();
        }

        $days = (int) floor((($this->now ?? new \DateTimeImmutable())->getTimestamp()
            - $completed->getTimestamp()) / 86400);

        return new AlertReading(
            overTrigger: $days >= AlertThresholds::PORTABLE_LINGER_TRIGGER_DAYS,
            underRearm: $days <= AlertThresholds::PORTABLE_LINGER_REARM_DAYS,
            value: $days . ' jours',
            title: sprintf('Une sauvegarde portable est sur le serveur depuis %d jours.', $days),
            why: 'Cette archive contient les clés de chiffrement du site : celui qui l\'obtient peut lire toutes '
                . 'les données de l\'unité, sur n\'importe quelle machine. Elle est faite pour être téléchargée '
                . 'puis supprimée du serveur — laissée ici, elle annule la protection qu\'elle transporte. '
                . 'Téléchargez-la, rangez-la ailleurs, puis supprimez-la de la liste des sauvegardes.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir les sauvegardes'
        );
    }
}
