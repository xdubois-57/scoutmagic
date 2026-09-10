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

/**
 * Is a stored backup no longer readable?
 *
 * **This check computes nothing**, and that is the point: it counts rows
 * that `Core\Maintenance\Task\VerifyBackupIntegrityHandler` already
 * decided. Hashing an archive to answer a question the daily pass asks
 * would make the alert cost gigabytes of reading; the split between the
 * thing that measures and the thing that reports is the same one D2 draws
 * between a notification and an attention point.
 *
 * **One is already too many.** Every other threshold here has a level a
 * value drifts across — 85 %, ten days, five failures — because the thing
 * measured is continuous and a single reading proves nothing. A backup
 * that cannot be read is not a level: it is a copy of the unit's work that
 * no longer exists, and there is no number of them small enough to be
 * fine. So the pair is one and zero, and the re-arm is the only
 * interesting half — the alert goes quiet when the last unreadable backup
 * has been dealt with, which in practice means deleted, since nothing can
 * repair a truncated archive.
 *
 * `unknown` and `unverifiable` are not counted. Neither is a finding: one
 * has not been looked at yet, the other has nothing to be compared
 * against because it predates the digests. Counting either would fire this
 * alert on the first pass of every installation that upgrades into it —
 * an alert that cries the day it arrives is switched off before it ever
 * says anything true.
 */
final class BackupIntegrityCheck implements OperationalCheck
{
    public const KEY = 'backup_unreadable';

    public function __construct(private readonly BackupRepository $backups)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Intégrité des sauvegardes';
    }

    public function read(): AlertReading
    {
        $unreadable = $this->backups->countUnreadable();

        return new AlertReading(
            overTrigger: $unreadable >= AlertThresholds::BACKUP_UNREADABLE_TRIGGER_COUNT,
            underRearm: $unreadable <= AlertThresholds::BACKUP_UNREADABLE_REARM_COUNT,
            value: $unreadable === 1 ? '1 sauvegarde' : $unreadable . ' sauvegardes',
            title: $unreadable === 1
                ? 'Une sauvegarde stockée n\'est plus lisible.'
                : sprintf('%d sauvegardes stockées ne sont plus lisibles.', $unreadable),
            why: 'Le site a relu ces sauvegardes et leur contenu ne correspond plus à ce qui avait été écrit, '
                . 'ou le fichier a disparu. Elles ne se restaureront pas. Vérifiez l\'espace disque, supprimez '
                . 'les sauvegardes illisibles, et créez-en une nouvelle tout de suite.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir les sauvegardes'
        );
    }
}
