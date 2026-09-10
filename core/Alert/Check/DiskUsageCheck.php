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
use Core\Storage\DiskBudget;

/**
 * Is the site running out of room? Reads the occupation
 * {@see DiskBudget} computes (IT-02), on whichever basis that service was
 * able to use.
 *
 * **An unknown occupation is inconclusive, never healthy.** A host that
 * declares no quota and reports no volume has not said the disk is empty,
 * and treating that as 0 % would quietly clear an alert on precisely the
 * installations that can least afford one. The screen already says which
 * measurement it could make; this check simply declines to guess.
 */
final class DiskUsageCheck implements OperationalCheck
{
    public const KEY = 'disk_usage';

    public function __construct(private readonly DiskBudget $diskBudget)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Espace disque';
    }

    public function read(): AlertReading
    {
        // measure(), not measureNow(): this runs in a background pass
        // where a reading up to a quarter of an hour old is exactly as
        // good, and walking every file under storage/ is the expensive
        // thing DiskBudget caches for (Core\Storage\DiskBudget, « Why the
        // measurement is cached »).
        $usage = $this->diskBudget->measure();
        $percent = $usage->usedPercent();

        if ($percent === null) {
            return AlertReading::inconclusive();
        }

        return new AlertReading(
            overTrigger: $percent >= AlertThresholds::DISK_TRIGGER_PERCENT,
            underRearm: $percent < AlertThresholds::DISK_REARM_PERCENT,
            value: $percent . ' %',
            title: sprintf('L\'espace disque du site est occupé à %d %%.', $percent),
            why: 'Passé ce point, une sauvegarde ou un envoi de photo peut être refusé, et une écriture '
                . 'interrompue en cours de route laisse un fichier incomplet. Supprimez des sauvegardes ou '
                . 'des photos, ou demandez plus d\'espace à votre hébergeur.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir l\'espace disque'
        );
    }
}
