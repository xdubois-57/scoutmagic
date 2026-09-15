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
use Core\Config\SettingService;
use Core\Maintenance\Remote\RemoteBackupDestination;
use Core\Maintenance\Task\SendRemoteBackupHandler;
use Core\Service\DateInput;

/**
 * How long since a copy of this site last actually left it.
 *
 * **{@see BackupAgeCheck} and this one are not the same question.** That
 * one answers « could we get the site back if a table were dropped »;
 * this one answers « could we get it back if the hosting account were
 * gone ». A site backing up perfectly to its own disk, every night, for
 * two years, fails the second question completely — and looks entirely
 * healthy until the day it matters.
 *
 * **A site with no destination is not measured at all.** Off-site sending
 * is a thing a unit chooses to set up; a unit that has not is not failing
 * at anything, and an alert that every installation carries from its first
 * day is one nobody reads. The reading comes back re-armed rather than
 * inconclusive, so disconnecting a destination CLEARS a triggered alert
 * instead of freezing it: « I cannot tell » would leave the last reading
 * standing for ever.
 *
 * **"No destination" means NONE CHOSEN, and nothing else.** This used to
 * be a harder distinction than it now is: a site whose grant Google had
 * withdrawn had its refresh token dropped, so anything keyed on « can we
 * still reach the destination » fell silent on exactly the site this check
 * exists for — a destination configured, an operator who believes it is
 * working, and nothing having left the server since the day the
 * authorisation died. IT-05 removed the trap rather than working around
 * it: a destination is an assignment ({@see RemoteBackupDestination}), the
 * grant lives in the location row and is no longer dropped on refusal, and
 * the only way to stop being measured is to stop choosing a destination.
 *
 * **A connected destination that has never received anything is measured
 * from the day it was connected**, not declared unknown. Ten days is ten
 * days whether the sends failed or never started, and the second is the
 * more alarming of the two.
 */
final class RemoteBackupAgeCheck implements OperationalCheck
{
    public const KEY = 'remote_backup_age';

    public function __construct(
        private readonly RemoteBackupDestination $destination,
        private readonly SettingService $settings,
        private readonly ?\DateTimeImmutable $now = null
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Sauvegarde hors site';
    }

    public function read(): AlertReading
    {
        if (!$this->destination->isConfigured()) {
            return $this->notApplicable();
        }

        $lastSuccess = (string) ($this->settings->get(SendRemoteBackupHandler::LAST_SUCCESS_SETTING) ?: '');
        $since = $lastSuccess !== '' ? $lastSuccess : $this->destination->activeSince();
        $everArrived = $lastSuccess !== '';

        // Through DateInput, never PHP's raw parser: that one raises on a
        // value carrying a NUL byte rather than returning false, so the
        // usual `!== false` guard lets exactly that through as an uncaught
        // exception (Tests\Security\DateParsingConvergenceTest).
        $moment = $since !== '' ? DateInput::fromStorage($since) : null;
        if ($moment === null) {
            return AlertReading::inconclusive();
        }

        $days = (int) floor((($this->now ?? new \DateTimeImmutable())->getTimestamp()
            - $moment->getTimestamp()) / 86400);

        return new AlertReading(
            overTrigger: $days >= AlertThresholds::REMOTE_BACKUP_AGE_TRIGGER_DAYS,
            underRearm: $days < AlertThresholds::REMOTE_BACKUP_AGE_REARM_DAYS,
            value: $days . ' jours',
            title: $everArrived
                ? sprintf('Le dernier envoi hors site réussi remonte à %d jours.', $days)
                : sprintf('Aucune sauvegarde n\'est jamais partie hors site depuis %d jours.', $days),
            why: 'Une sauvegarde restée sur le serveur ne protège de rien si c\'est l\'hébergement lui-même '
                . 'qui disparaît — compte fermé, panne définitive, erreur de manipulation. Vérifiez que la '
                . 'destination est toujours raccordée et qu\'aucun envoi n\'échoue.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir les envois hors site'
        );
    }

    /**
     * Re-armed, not inconclusive: a unit that has never chosen a
     * destination is not failing at anything, and one that deliberately
     * stops sending off site must see the alert go out rather than stay
     * lit on a measurement nothing will ever take again.
     *
     * Reached only when no destination is chosen. A withdrawn grant never
     * lands here — see the class docblock for why that distinction is the
     * whole point of this check.
     */
    private function notApplicable(): AlertReading
    {
        return new AlertReading(
            overTrigger: false,
            underRearm: true,
            value: 'non configuré',
            title: 'Aucune destination hors site n\'est choisie.',
            why: 'Rien ne part de ce serveur, et rien ne le surveille.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir les sauvegardes'
        );
    }
}
