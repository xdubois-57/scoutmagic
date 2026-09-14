<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\OperationalCheck;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\SendCounterRepository;

/**
 * Whether anything is still able to carry a sign-in link (D9,
 * ARCHITECTURE.md §8.106).
 *
 * This is the alert the entire chantier exists for. The authentication
 * lane does not defer and cannot: a magic link delivered tomorrow is not
 * a magic link. So when its last usable entry goes, nothing queues, no
 * retry is coming, and **nobody can sign in — including the super-admin
 * who would have come to repair it**. That person has to be told through
 * some channel other than the one that is down, which is what an
 * operational alert is.
 *
 * It reads the lane the way the chain does rather than waiting for a
 * failure to be observed: by the time a magic link has failed, somebody
 * has already been unable to get in.
 */
final class AuthenticationLaneCheck implements OperationalCheck
{
    public const KEY = 'mail_authentication_lane';

    public function __construct(
        private readonly LaneChainRepository $chains,
        private readonly MailProviderDirectory $directory,
        private readonly SendCounterRepository $counters
    ) {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Voie d\'authentification';
    }

    public function read(): AlertReading
    {
        try {
            $usable = $this->usableEntries();
        } catch (\Throwable) {
            return AlertReading::inconclusive();
        }

        return new AlertReading(
            // No threshold to tune: one usable entry is the whole
            // difference between a site people can sign into and one they
            // cannot. There is nothing between 0 and 1 to be gradual
            // about, so trigger and re-arm sit on either side of it.
            overTrigger: $usable === 0,
            underRearm: $usable >= 1,
            // French agreement: zero and one both take the singular.
            value: $usable . ' fournisseur' . ($usable > 1 ? 's' : ''),
            title: $usable === 0
                ? 'Plus aucun fournisseur ne peut envoyer les liens de connexion.'
                : sprintf(
                    '%d fournisseur%s peu%s envoyer les liens de connexion.',
                    $usable,
                    $usable > 1 ? 's' : '',
                    $usable > 1 ? 'vent' : 't'
                ),
            why: 'La voie d\'authentification ne diffère jamais un message : un lien livré demain '
                . 'n\'est pas un lien de connexion. Tant qu\'elle est vide, plus personne ne peut se '
                . 'connecter au site — y compris vous.',
            actionUrl: '/config/courrier-sortant/acheminement',
            actionLabel: 'Voir l\'acheminement'
        );
    }

    /**
     * The entries the chain would actually be able to try.
     *
     * The circuit breaker is deliberately NOT consulted — which is also
     * why this class does not take it. The chain tries the last entry
     * even when its circuit is open (D15), so treating an open circuit as
     * a missing provider would raise the alarm about a lane that still
     * works, and would do it during exactly the outage the breaker is
     * riding out.
     *
     * **A spent quota is the opposite case, and it IS counted out.** The
     * chain has no last-resort rule for it: `candidates()` removes a
     * provider over its daily ceiling outright, so a lane whose every
     * entry has spent its quota throws rather than trying anything. A
     * check reading only « is it configured » would call that lane
     * healthy and re-arm the alert on the evening when a mailing has just
     * eaten the day's allowance — which is the exact night nobody can log
     * in. The reserve (D14) is what usually prevents it; this is what
     * says so when it has not.
     *
     * The quota is read against the whole day's sends, not against the
     * lane's: a quota belongs to the provider and every lane spends the
     * same one.
     */
    private function usableEntries(): int
    {
        $providers = $this->directory->all();
        $usedToday = $this->counters->totalsForDay();
        $usable = 0;

        foreach ($this->chains->forLane(MailLane::Authentication) as $entry) {
            if (!$entry->enabled) {
                continue;
            }

            $provider = $providers[$entry->providerId] ?? null;
            if ($provider === null || !$provider->isUsable()) {
                continue;
            }

            if ($provider->dailyQuota !== null
                && ($usedToday[$provider->id] ?? 0) >= $provider->dailyQuota
            ) {
                continue;
            }

            $usable++;
        }

        return $usable;
    }
}
