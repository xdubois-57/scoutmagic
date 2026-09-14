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
use Core\Storage\Location\Backend\QuotaReportingBackend;

/**
 * How full the destination account is.
 *
 * **The failure this exists to catch is silent by construction.** Nobody
 * looks at the free space of a Drive they set up two years ago; the send
 * that runs out of room does so at four in the morning, and the operator
 * discovers it on the night the server is gone. Fifteen gibibytes shared
 * with a mailbox fills faster than anyone expects.
 *
 * The thresholds sit higher than the local disk's (90/80 against 85/75),
 * and that is a judgement, not an oversight: a full server breaks the
 * site — an upload refused, a write truncated — while a full destination
 * costs the next send and nothing else. Archives already there stay
 * readable, and {@see \Core\Maintenance\Remote\RemoteRetention} is what
 * keeps the folder inside its bounds in the ordinary case.
 *
 * **The one check here that makes a network call.** It runs in the daily
 * background pass, never on a web request, and every failure it can meet
 * — a revoked grant, a timeout, a destination that will not say — comes
 * back inconclusive. A check is not the place to discover that a
 * connection is broken: {@see RemoteBackupAgeCheck} reports that in the
 * terms an operator can act on, by noticing that nothing has arrived.
 */
final class RemoteQuotaCheck implements OperationalCheck
{
    public const KEY = 'remote_quota';

    /**
     * @param QuotaReportingBackend|null $backend null on a site with no
     *        destination chosen — and also on one whose destination simply
     *        cannot say, which is the ordinary case for a bucket and for a
     *        folder on this server's own disk. Neither is an error, and
     *        neither is measured: the capability
     *        ({@see \Core\Storage\Location\StorageCapability::Quota}) is
     *        what decides, so a destination that gains or loses the
     *        aptitude changes one declaration and this follows.
     */
    public function __construct(
        private readonly ?QuotaReportingBackend $backend,
        /**
         * Whether the destination is declared and could not be built at
         * all — a secret that no longer decrypts, a location row pointing
         * at a type this build does not have, an emplacement that lost
         * the aptitude the backups need.
         *
         * Kept apart from a null backend because the two look identical
         * and mean opposite things. « Nothing to measure » is a site in
         * good order and re-arms the alert; « I could not measure » is a
         * site whose destination may be full and whose reading is simply
         * missing, so it must leave a standing alert exactly where it
         * was.
         */
        private readonly bool $unreadable = false
    ) {
    }

    /**
     * The check for a destination that is declared and cannot be built.
     *
     * A named constructor rather than a second boolean at the call site:
     * `new RemoteQuotaCheck(null, true)` reads as « no backend, true »
     * and says nothing about which of the two nulls this is.
     */
    public static function unreadable(): self
    {
        return new self(null, true);
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Espace hors site';
    }

    public function read(): AlertReading
    {
        if ($this->unreadable) {
            // Inconclusive, never re-armed: the destination is still
            // chosen, so « all clear » would be an answer about a site
            // nobody measured.
            return AlertReading::inconclusive();
        }

        if ($this->backend === null) {
            // Re-armed rather than inconclusive, for the reason
            // RemoteBackupAgeCheck gives: dropping a destination has to
            // clear the alert, not freeze the last reading taken of it.
            return new AlertReading(
                overTrigger: false,
                underRearm: true,
                value: 'non mesuré',
                title: 'Aucun espace distant n\'est mesurable.',
                why: 'Aucune destination hors site n\'est choisie, ou celle qui l\'est ne sait pas dire ce qu\'il '
                    . 'lui reste de place.',
                actionUrl: '/config/maintenance',
                actionLabel: 'Voir les sauvegardes'
            );
        }

        try {
            $quota = $this->backend->quota();
        } catch (\Throwable) {
            // Deliberately swallowed, and deliberately not journaled: the
            // pass runs every day, and a destination that is unreachable
            // writes one entry per day for ever. The reason a site cannot
            // reach its destination belongs to the send that fails, which
            // journals it once, with the operator's own words for it.
            return AlertReading::inconclusive();
        }

        $percent = $quota?->usedPercent();
        if ($percent === null) {
            // An account with no declared limit — or one that will not say.
            // Nothing to be a percentage of, so nothing to report.
            return AlertReading::inconclusive();
        }

        return new AlertReading(
            overTrigger: $percent >= AlertThresholds::REMOTE_QUOTA_TRIGGER_PERCENT,
            underRearm: $percent < AlertThresholds::REMOTE_QUOTA_REARM_PERCENT,
            value: $percent . ' %',
            title: sprintf('L\'espace de la destination hors site est occupé à %d %%.', $percent),
            why: 'Quand il sera plein, les envois seront refusés et plus aucune copie du site ne quittera le '
                . 'serveur — sans que rien d\'autre ne cesse de fonctionner. Faites de la place sur le compte '
                . 'distant, ou réduisez le nombre d\'archives conservées.',
            actionUrl: '/config/maintenance',
            actionLabel: 'Voir les envois hors site'
        );
    }
}
