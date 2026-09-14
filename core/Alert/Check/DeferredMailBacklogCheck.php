<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Alert\Check;

use Core\Alert\AlertReading;
use Core\Alert\OperationalCheck;
use Core\Mail\Transport\DeferredMailRepository;

/**
 * A deferral queue that is not draining (D9, ARCHITECTURE.md §8.106).
 *
 * **« Un report n'est pas un silence. »** The whole bargain of the queue
 * is that a message nobody could send is kept rather than lost — and
 * that bargain is only honest while the queue empties. A queue that
 * keeps growing is the failure the deferral was hiding: every sender
 * was told their message was on its way, and none of them has left.
 *
 * The threshold is a COUNT rather than an age, because the cheapest
 * signal that a queue has stopped draining is that it is deep. A few
 * messages waiting five minutes for the next pass is the mechanism
 * working; several dozen is the relay still down an hour later.
 */
final class DeferredMailBacklogCheck implements OperationalCheck
{
    public const KEY = 'mail_deferred_backlog';

    /** Deep enough that nothing but a stuck lane produces it. */
    public const TRIGGER_COUNT = 50;

    /**
     * Strictly lower, so an alert does not flicker on and off while the
     * queue drains past its own threshold — the armed/triggered machine
     * of `Core\Alert` needs the two numbers to be apart.
     */
    public const REARM_COUNT = 10;

    public function __construct(private readonly DeferredMailRepository $repository)
    {
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'File des messages différés';
    }

    public function read(): AlertReading
    {
        try {
            $waiting = array_sum($this->repository->pendingCountByLane());
        } catch (\Throwable) {
            // « I could not tell » must never clear an alert somebody
            // still needs to see — AlertReading::inconclusive() is what
            // leaves a triggered alert triggered.
            return AlertReading::inconclusive();
        }

        return new AlertReading(
            overTrigger: $waiting >= self::TRIGGER_COUNT,
            underRearm: $waiting <= self::REARM_COUNT,
            value: $waiting . ' en attente',
            title: sprintf('%d messages attendent de pouvoir partir.', $waiting),
            why: 'Ils ont été acceptés puis mis de côté faute de fournisseur disponible, et personne '
                . 'ne l\'a su : l\'expéditeur a vu un envoi réussi. Tant que la file ne se vide pas, '
                . 'ces messages ne sont pas partis.',
            actionUrl: '/config/courrier-sortant',
            actionLabel: 'Voir le courrier sortant'
        );
    }
}
