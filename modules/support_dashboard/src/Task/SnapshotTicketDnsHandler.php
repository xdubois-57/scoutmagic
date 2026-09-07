<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Modules\SupportDashboard\Task;

use Core\Net\DnsRecordReader;
use Core\Net\DomainName;
use Core\Scheduler\TaskContext;
use Core\Scheduler\TaskHandlerInterface;
use Modules\SupportDashboard\Repository\SupportInstallationRepository;
use Modules\SupportDashboard\Repository\SupportTicketRepository;

/**
 * Reads one ticket's installation zone, out of the request that filed it
 * (`support_dashboard`/`snapshot_ticket_dns`, one-shot, issue #198).
 *
 * **Why it is not done inline any more.** `dns_get_record()` takes no
 * timeout: it obeys the system resolver, which on a name whose
 * authoritative servers drop packets sits for seconds per type and cannot
 * be interrupted from PHP. `DnsRecordReader`'s budget bounds the walk
 * between types and nothing at all once a query is in flight, so an
 * installation whose `instance_url` points at such a name held the intake
 * worker on every ticket it sent. Making the resolver cancellable means an
 * async DNS library — a production dependency this project has for nothing
 * else on the network. Moving the read out of the request removes the
 * class of problem instead of shrinking it, and costs a dependency of
 * zero.
 *
 * **What it costs is the word « immediately », and that word was never
 * load-bearing.** The point of reading at intake rather than at reading
 * time is that a zone is answered days after a ticket is written and half
 * of « le site ne répond plus » is a record the reporter has corrected in
 * between (ARCHITECTURE.md §8.49septies). The cron runs every minute, so
 * the snapshot is still the zone as the ticket arrived, to within a
 * minute, against the days that argument is about.
 *
 * **A ticket that already carries a snapshot is left alone**, so a task
 * that ran, died after the write and got retried does not read the zone a
 * second time and does not overwrite a reading with a later one. The
 * moment recorded has to be the moment the ticket arrived.
 */
class SnapshotTicketDnsHandler implements TaskHandlerInterface
{
    public const TASK_KEY = 'snapshot_ticket_dns';

    /**
     * How long after the ticket the read is queued for.
     *
     * Zero: as soon as the next cron pass. The delay in practice is the
     * cron's own period, and adding to it here would only make the
     * snapshot less contemporary for no gain.
     */
    public const DELAY_SECONDS = 0;

    public function __construct(
        /**
         * Defaulted rather than injected: the scheduler resolves a handler
         * with `new $handlerClass()` and has nothing to pass. The
         * parameter exists so a test can hand in a reader that answers
         * from a script — a test that queried a real resolver would be a
         * test of the runner's network.
         */
        private ?DnsRecordReader $dns = null
    ) {
    }

    /**
     * The ticket repository, alone in its own method so a test can hand
     * back one whose write throws — the « the database went away » case,
     * which is the only thing this handler's catch is for and which
     * nothing else can produce, the reader below answering rather than
     * throwing.
     */
    protected function tickets(\PDO $pdo, TaskContext $context): SupportTicketRepository
    {
        return new SupportTicketRepository($pdo, $context->encryption);
    }

    /**
     * @param array<string, mixed> $payload {reference: the ticket's own
     *   `SUP-` reference — the ticket is looked up again rather than
     *   carried, because between the queueing and the pass it may have
     *   been purged}
     */
    public function handle(array $payload, TaskContext $context): void
    {
        $reference = (string) ($payload['reference'] ?? '');
        if ($reference === '') {
            return;
        }

        $pdo = $context->connection->getPdo();
        $tickets = $this->tickets($pdo, $context);
        $installations = new SupportInstallationRepository($pdo, $context->encryption);

        try {
            $target = $tickets->findDnsTarget($reference);
            if ($target === null || $target['dns_read_at'] !== null) {
                // Purged, or already read: both are ordinary, and neither
                // is worth a journal entry on a receiver whose journal is
                // read to find out what went wrong.
                return;
            }

            $installation = $installations->findById($target['installation_id']);
            $host = DomainName::hostOf((string) ($installation['instance_url'] ?? ''));

            if ($host === null) {
                $context->journal->log(
                    'support_dashboard',
                    'support_ticket_dns_skipped',
                    'info',
                    'Aucun relevé DNS : cette installation n\'a pas d\'URL exploitable',
                    ['ticket_reference' => $reference]
                );

                return;
            }

            $tickets->recordDnsSnapshot(
                $reference,
                ($this->dns ?? new DnsRecordReader())->read($host, new \DateTimeImmutable()),
                new \DateTimeImmutable()
            );
        } catch (\Throwable $e) {
            // The reader answers rather than throwing, so what reaches
            // here is the database or the encryption — and the ticket
            // itself is long since stored and answered for.
            $context->journal->log(
                'support_dashboard',
                'support_ticket_dns_failed',
                'warning',
                'Le relevé DNS du ticket n\'a pas pu être enregistré',
                ['ticket_reference' => $reference, 'exception' => $e::class]
            );
        }
    }
}
