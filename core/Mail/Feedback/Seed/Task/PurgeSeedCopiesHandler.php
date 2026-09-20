<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed\Task;

use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Gives up on copies nobody ever saw, and drops the old ones
 * (roadmap IT-07).
 *
 * **Two jobs, and the first is the one that carries meaning.** A copy
 * sent and not yet found is `pending`, which is the ordinary state of
 * every mailing still going out; only elapsed time turns it into « jamais
 * arrivé ». Deciding that here, once, is what stops a screen from
 * recomputing the verdict on every load and changing its mind while
 * somebody watches.
 *
 * **Two days before giving up.** A mailing drains over a cadence that can
 * span hours, a provider may hold a message in a queue, and a mailbox is
 * polled on a schedule of its own — so an hour would manufacture
 * « jamais arrivé » for copies that turn up fine, which is the one error
 * this screen must not make. Two days is long enough that anything still
 * missing really is missing.
 *
 * Self-reschedules like its two siblings in this namespace.
 */
class PurgeSeedCopiesHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'purge_mail_seed_copies';
    public const REFERENCE = 'daily';

    /** How long a copy may stay `pending` before it counts as never arrived. */
    public const GIVE_UP_AFTER_DAYS = 2;

    /**
     * How long the results are kept.
     *
     * Ninety days, like the DMARC reports: the screen shows thirty, and
     * the rest answer « depuis quand ? » about a provider that has started
     * filing the unit's mail as spam.
     */
    public const RETENTION_DAYS = 90;

    private const INTERVAL_SECONDS = 86400;

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $copies = new SeedCopyRepository($pdo, $context->encryption);

        $given = $copies->markMissingBefore(
            new \DateTimeImmutable('-' . self::GIVE_UP_AFTER_DAYS . ' days')
        );
        $dropped = $copies->purgeBefore(new \DateTimeImmutable('-' . self::RETENTION_DAYS . ' days'));

        if ($given > 0 || $dropped > 0) {
            $context->journal->log(
                'core',
                'mail_seed_copies_swept',
                'info',
                'Balayage des copies témoins',
                // Counters only — never an address, never a box
                // (SECURITY.md §11), the same rule the screen follows.
                ['given_up' => $given, 'dropped' => $dropped]
            );
        }

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }
}
