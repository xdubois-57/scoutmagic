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

    /**
     * Where the disk is shown: the storage dashboard, volume by volume
     * (issue #649). Constants, because two surfaces name this destination
     * — the notification from {@see read()}, the attention page from
     * {@see \Core\Alert\AlertSurfaces::destinations()} — and they must
     * not drift apart.
     */
    public const STORAGE_PATH = '/config/stockage';

    public const ACTION_LABEL = 'Voir l\'espace disque';

    public const ATTENTION_WHY = 'Le site l\'a signalé aux administrateurs et le répète ici tant que c\'est '
        . 'vrai. L\'occupation de chaque volume se lit sur Configuration › Stockage, '
        . 'page du super-administrateur.';

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
            // The storage dashboard, which shows the use of every volume. It
            // used to be /config/maintenance, which stopped showing disk space
            // when its panel moved to Storage — the button then led to a page
            // without the thing it promised (issue #649).
            actionUrl: self::STORAGE_PATH,
            actionLabel: self::ACTION_LABEL
        );
    }
}
