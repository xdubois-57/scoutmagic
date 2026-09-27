<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Mail\Feedback\Seed\Task;

use Core\Mail\Feedback\Seed\MxProviderMap;
use Core\Mail\Feedback\Seed\SeedCopyRepository;
use Core\Mail\Transport\MailboxProviderRepository;
use Core\Net\DnsMxLookup;
use Core\Net\MxLookup;
use Core\Scheduler\SchedulerRepository;
use Core\Scheduler\SchedulerService;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;

/**
 * Reads the MX records of the domains this site writes to, and writes
 * down which provider hosts each (roadmap IT-07, issue #422).
 *
 * **The only place those records are ever read.** The send path looks the
 * answer up in `mail_domain_providers` and never asks the DNS itself: a
 * resolver that hangs must cost a background task a few seconds, never a
 * mailing a message. So the three things a lookup needs and the send path
 * could not afford live here:
 *
 * - **a limit** — `BATCH` domains a run, inside `BUDGET_SECONDS` of wall
 *   clock checked between two lookups, because `dns_get_record()` takes
 *   no timeout of its own;
 * - **a cache** — an answer is good for `TTL_DAYS`, and the send path
 *   reads it whatever its age;
 * - **a conduct on failure** — a lookup that could not be made keeps the
 *   last good answer and backs off, doubling up to `TTL_DAYS`, and nothing
 *   escapes to the scheduler: a throwing handler ends its own chain.
 *
 * And it is the table's retention: a domain nobody has written to for
 * `RETENTION_DAYS` is forgotten.
 *
 * **It changes who is counted in which column, not what is measured.**
 * The seed boxes still measure; this only says that « famille.be » served
 * by Google belongs in the gmail.com column and under gmail.com's routing.
 */
class ResolveMailboxProvidersHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'resolve_mail_domain_providers';
    public const REFERENCE = 'daily';

    /**
     * How many domains one run may ask about: enough for the cache's
     * ceiling (MailboxProviderRepository::MAXIMUM) to be refreshed within
     * one TTL, so a large unit's domains do not go stale behind their
     * newcomers. BUDGET_SECONDS is what actually bounds a run; the rest
     * waits for tomorrow without starting a back-off.
     */
    public const BATCH = 750;

    /**
     * How long one run may spend asking. A healthy resolver answers a
     * batch in about a second; this bound exists for the unhealthy one,
     * which can hold a single query for ten.
     */
    public const BUDGET_SECONDS = 20.0;

    /** How long an answer stands before it is asked again. */
    public const TTL_DAYS = 7;

    /**
     * How long a domain nobody writes to is kept. Six months: a unit that
     * writes to a family once a term keeps its attribution, and a family
     * that left is forgotten within the year.
     */
    public const RETENTION_DAYS = 180;

    private const INTERVAL_SECONDS = 86400;

    public function __construct(
        /** Replaced in tests: a test of this task never reaches the network. */
        private ?MxLookup $lookup = null,
        private float $budgetSeconds = self::BUDGET_SECONDS
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $pdo = $context->connection->getPdo();

        try {
            $this->resolve(
                new MailboxProviderRepository($pdo, $context->encryption),
                new SeedCopyRepository($pdo, $context->encryption),
                $context
            );
        } catch (\Throwable $error) {
            $context->journal->log(
                'core',
                'mail_domain_providers_failed',
                'error',
                'Lecture des enregistrements MX impossible',
                // The class, never the message: a PDO message quotes the
                // statement, and the statement names a domain.
                ['error' => $error::class]
            );
        }

        (new SchedulerService(new SchedulerRepository($pdo)))
            ->rearmAfter('core', self::TASK_KEY, self::REFERENCE, self::INTERVAL_SECONDS);
    }

    private function resolve(
        MailboxProviderRepository $providers,
        SeedCopyRepository $copies,
        TaskContext $context
    ): void {
        $now = new \DateTimeImmutable();

        // The seed boxes' own domains, so a box on a domain Google hosts
        // is counted under gmail.com even before a mailing notes it —
        // dated by their last send, so the retention stated on the RGPD
        // page counts from the site's last message to that domain, not
        // from today. One already past it is not noted back in.
        $retentionCut = $now->modify('-' . self::RETENTION_DAYS . ' days');
        foreach ($copies->measuredDomains() as $measured) {
            if ($measured['last_sent_at'] >= $retentionCut) {
                $providers->note($measured['domain'], $measured['last_sent_at']);
            }
        }

        $forgotten = $providers->purgeNotedBefore($retentionCut);

        $lookup = $this->lookup ?? new DnsMxLookup();
        $deadline = microtime(true) + $this->budgetSeconds;
        $resolved = 0;
        $attributed = 0;
        $failed = 0;
        $postponed = 0;

        $due = $providers->due($now, $now->modify('-' . self::TTL_DAYS . ' days'), self::BATCH);
        foreach ($due as $row) {
            if (microtime(true) >= $deadline) {
                // Left for tomorrow, untouched: running out of time is not
                // the domain's failure and must not start its back-off.
                $postponed++;
                continue;
            }

            try {
                $hosts = $lookup->hostsFor($row['domain']);
            } catch (\Throwable) {
                $hosts = null;
            }

            if ($hosts === null) {
                $providers->recordFailure(
                    $row['domain'],
                    'no_answer',
                    $now->modify('+' . self::backoffDays($row['failures'] + 1) . ' days')
                );
                $failed++;
                continue;
            }

            $provider = MxProviderMap::providerFor($hosts);
            $providers->recordResolved($row['domain'], $provider, $now);
            $resolved++;
            if ($provider !== null && $provider !== $row['domain']) {
                $attributed++;
            }
        }

        if ($resolved > 0 || $failed > 0 || $forgotten > 0) {
            $context->journal->log(
                'core',
                'mail_domain_providers_resolved',
                'info',
                'Fournisseurs de messagerie lus dans les enregistrements MX',
                // Counters only. A personal domain can name a family, so
                // not even the domains reach the journal.
                [
                    'resolved' => $resolved,
                    'attributed' => $attributed,
                    'failed' => $failed,
                    'postponed' => $postponed,
                    'forgotten' => $forgotten,
                ]
            );
        }
    }

    /**
     * Days before a domain that failed `$failures` times in a row is asked
     * again: 1, 2, 4, then the TTL. A domain whose DNS is broken is asked
     * weekly, like one whose DNS works — never daily for ever.
     */
    public static function backoffDays(int $failures): int
    {
        return min(self::TTL_DAYS, 2 ** max(0, min($failures - 1, 3)));
    }
}
