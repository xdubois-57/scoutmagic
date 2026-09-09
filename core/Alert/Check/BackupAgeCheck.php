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
 * How long since a backup actually succeeded.
 *
 * This is the check the recovery objective is made of
 * (`docs/exigences-non-fonctionnelles.md` §3): an RPO of seven days is a
 * promise about the age of the newest restorable copy, and nothing else on
 * this installation was watching it. A site whose scheduled backup has
 * been failing silently for a month looks exactly like a healthy one until
 * somebody needs it.
 *
 * **A site that has never backed up at all is triggered, not
 * inconclusive.** That is not an absence of information — it is the worst
 * reading this check can take, and the one most worth saying out loud on a
 * site that has been running for a while. A brand-new installation trips
 * it too, which is correct: it also has no backup.
 */
final class BackupAgeCheck implements OperationalCheck
{
    public const KEY = 'backup_age';

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
        return 'Sauvegardes';
    }

    public function read(): AlertReading
    {
        $completedAt = $this->backups->lastSuccessfulCompletedAt();
        $now = $this->now ?? new \DateTimeImmutable();

        if ($completedAt === null) {
            return new AlertReading(
                overTrigger: true,
                underRearm: false,
                value: 'aucune',
                title: 'Aucune sauvegarde du site n\'a jamais abouti.',
                why: 'En cas de problème chez votre hébergeur, il n\'existe rien à restaurer. Lancez une '
                    . 'sauvegarde depuis Configuration > Maintenance, puis vérifiez que la sauvegarde '
                    . 'automatique est bien active.',
                actionUrl: '/config/maintenance',
                actionLabel: 'Sauvegarder maintenant'
            );
        }

        // Through DateInput, never PHP's raw format parser: that one
        // raises a ValueError rather than returning false on a value
        // carrying a NUL byte, so the usual `!== false` guard lets exactly
        // that input through as an uncaught exception (Tests\Security\
        // DateParsingConvergenceTest, which is why every date in this
        // codebase converges here). fromStorage() is the reading meant for
        // a column: it also refuses MySQL's zero date, which PHP otherwise
        // parses into the 30th of November, year -1.
        $completed = DateInput::fromStorage($completedAt);
        if ($completed === null) {
            return AlertReading::inconclusive();
        }

        $days = (int) floor(($now->getTimestamp() - $completed->getTimestamp()) / 86400);

        return new AlertReading(
            overTrigger: $days >= AlertThresholds::BACKUP_AGE_TRIGGER_DAYS,
            underRearm: $days < AlertThresholds::BACKUP_AGE_REARM_DAYS,
            value: $days . ' jours',
            title: sprintf('La dernière sauvegarde réussie remonte à %d jours.', $days),
            why: 'L\'objectif du site est de ne jamais perdre plus d\'une semaine de travail. Au-delà, une '
                . 'restauration vous ramènerait plus loin en arrière que prévu. Vérifiez la fréquence des '
                . 'sauvegardes automatiques et qu\'aucune n\'échoue.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir les sauvegardes'
        );
    }
}
