<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed\Task;

use Core\Mail\Feedback\Seed\DomainRouting;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Transport\DomainPreferences;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailProviderRepository;
use Core\Mail\Transport\ProviderConnections;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * The daily reading of the seed results: what they settle, settled once
 * (roadmap IT-07).
 *
 * **Three jobs, and they are one job.** Everything here turns evidence
 * that is still accumulating into a fact the rest of the site may rely
 * on — a copy that has waited long enough is missing, a result old enough
 * is gone, and a provider that has filed enough mailings away from the
 * inbox has, if the unit asked for it, been routed elsewhere. Spreading
 * them over three schedules would let a screen read a verdict the routing
 * had not seen yet.
 *
 * **Giving up is the one that carries meaning.** A copy
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

    /**
     * The window the automatic routing reads, and it is the screen's.
     *
     * A decision taken from a wider window than the one somebody looked
     * at is a decision they cannot account for: « the page says Gmail is
     * fine, so why did the site move it? ».
     */
    public const ROUTING_WINDOW_DAYS = 30;

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

        // **The routing may not cost the purge its next run.**
        //
        // `SchedulerRunner` marks a throwing handler's action failed and
        // does NOT queue the next one, so an exception escaping here ends
        // the daily chain permanently — and this chain is what stops the
        // seed copies accumulating. The retention that the privacy notice
        // promises would simply stop happening, silently, because of a
        // routing nicety.
        //
        // The two jobs are not equally important and the code now says
        // so: giving up on old copies and purging them is the contract,
        // applying a recommendation is a convenience.
        try {
            $this->routeIfAsked($copies, $context);
        } catch (\Throwable $error) {
            $context->journal->log(
                'core',
                'mail_seed_routing_failed',
                'error',
                'Routage automatique impossible',
                // The class, never the message: an exception text routinely
                // carries values this journal may not hold (§7.9).
                ['error' => $error::class]
            );
        }

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }

    /**
     * Apply what the results recommend — but only if the unit asked, and
     * only once per domain (D13).
     *
     * **Only once, and that is the whole of it.** `apply()` moves a
     * domain to the NEXT relay of the mailing chain, so calling it every
     * day on a provider that stays troubled would walk that domain around
     * the chain for ever, changing where a unit's mail comes from daily
     * and destroying the regular traffic each relay's reputation rests
     * on — the exact harm D13 weighs the remedy against. A domain that
     * already carries a decision is left alone.
     *
     * **And it never undoes one.** A domain that has stopped being
     * troubled has stopped being troubled ON ITS NEW RELAY; moving it
     * back would make it troubled again, and the two readings would take
     * turns for as long as the switch stayed on. Undoing is a person's
     * decision, from the button on the page.
     *
     * Journalled at `security`, like every other change to how mail
     * leaves this site: recipient domains and relay names only, which are
     * not personal data — an aggregated domain is a mail provider, an
     * address is a person (SECURITY.md §11).
     */
    private function routeIfAsked(SeedCopyRepository $copies, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();
        $preferences = new DomainPreferences($context->settings);
        $routing = new DomainRouting(
            $copies,
            $context->settings,
            $preferences,
            new LaneChainRepository($pdo),
            // No secrets, deliberately: this reading needs a relay's id
            // and its name and nothing else, and a background task that
            // decrypted `secrets.enc` to reorder a list would be handling
            // credentials it has no use for.
            new MailProviderDirectory(
                new MailProviderRepository($pdo),
                new ProviderConnections([]),
                $context->settings
            )
        );

        if (!$routing->isAutomatic()) {
            return;
        }

        $since = new \DateTimeImmutable('-' . self::ROUTING_WINDOW_DAYS . ' days');
        $applied = [];

        foreach ($routing->readings($since) as $reading) {
            if (!$reading['troubled'] || $preferences->forDomain($reading['provider']) !== null) {
                continue;
            }

            $moved = $routing->apply($reading['provider']);
            if ($moved !== null) {
                $applied[] = $reading['provider'] . ' → ' . $moved->name;
            }
        }

        if ($applied !== []) {
            $context->journal->log(
                'core',
                'mail_seed_routing_applied',
                'security',
                'Routage par domaine appliqué automatiquement',
                ['routes' => $applied]
            );
        }
    }
}
