<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Net;

/**
 * What the DNS says about one host, right now.
 *
 * **Read at a moment, kept for later.** A support ticket is answered days
 * after it is written, and half of « le site ne répond plus », « les
 * e-mails n'arrivent pas » or « le certificat est invalide » is a DNS
 * record that has since been corrected by the person reporting it. Reading
 * the zone when the maintainer finally looks answers a question nobody
 * asked; reading it when the ticket lands is the evidence.
 *
 * **Every failure is an empty answer with a reason, never an exception.**
 * The caller is an intake endpoint that has already accepted a ticket: a
 * resolver that is down, a name that does not exist and a host behind a
 * split-horizon view are all ordinary, and none of them may cost the
 * ticket. What must never happen is silence — a snapshot with no records
 * and no explanation reads as « ce domaine n'a rien », which is a
 * diagnosis, and the wrong one.
 *
 * **Bounded by a wall clock, not by a per-call timeout.** `dns_get_record()`
 * takes no timeout: it obeys the system resolver, which on an unreachable
 * network can sit for ten seconds per type. Seven types would be over a
 * minute, on a request a sender gives twenty seconds. So the budget is
 * checked between types and the rest are marked as skipped — a partial
 * snapshot that says which parts are missing, rather than a request that
 * dies.
 */
class DnsRecordReader
{
    /**
     * The types read, in the order they are asked for.
     *
     * Ordered by what a support question is usually about: where the site
     * points, then where its mail goes, then who is authoritative. If the
     * budget runs out it runs out at the end of the list, so the cheapest
     * diagnosis is the one that survives.
     *
     * @var array<string, int>
     */
    public const TYPES = [
        'A' => DNS_A,
        'AAAA' => DNS_AAAA,
        'CNAME' => DNS_CNAME,
        'MX' => DNS_MX,
        'TXT' => DNS_TXT,
        'NS' => DNS_NS,
        'SOA' => DNS_SOA,
    ];

    /**
     * How long the whole snapshot may take.
     *
     * Eight seconds against the twenty a sender allows the entire request
     * (`Core\Statistics\StreamStatisticsTransport`), leaving the ticket's
     * own work the rest. A healthy resolver answers all seven types in
     * well under a second; this bound exists for the unhealthy one.
     */
    public const BUDGET_SECONDS = 8.0;

    /**
     * How many records of one type are kept.
     *
     * A zone with four hundred TXT records is a zone somebody is doing
     * something else with, and the diagnostic value is in the first few.
     */
    public const MAX_RECORDS_PER_TYPE = 25;

    /** Outcomes, so a reader can tell the three apart. */
    public const STATUS_FOUND = 'found';
    public const STATUS_NONE = 'none';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    public function __construct(
        /**
         * Overridable so a test can prove the budget without spending it:
         * the interesting case is « le résolveur ne répond pas », and a
         * test that waited eight real seconds to show it would be a test
         * nobody runs.
         */
        private float $budgetSeconds = self::BUDGET_SECONDS
    ) {
    }

    /**
     * Every type this reader knows about for one host.
     *
     * @return array{
     *     host: string,
     *     read_at: string,
     *     records: array<string, array{status: string, values: string[]}>
     * }
     */
    public function read(string $host, \DateTimeImmutable $now): array
    {
        $records = [];
        $deadline = microtime(true) + $this->budgetSeconds;

        foreach (self::TYPES as $name => $type) {
            if (microtime(true) >= $deadline) {
                // Named rather than omitted: « pas de MX » and « je n'ai
                // pas eu le temps de demander » are opposite diagnoses.
                $records[$name] = ['status' => self::STATUS_SKIPPED, 'values' => []];
                continue;
            }

            $records[$name] = $this->readType($host, $type);
        }

        return [
            'host' => $host,
            'read_at' => $now->format(\DateTimeInterface::ATOM),
            'records' => $records,
        ];
    }

    /**
     * @return array{status: string, values: string[]}
     */
    private function readType(string $host, int $type): array
    {
        // Silenced on purpose: PHP emits a warning for NXDOMAIN and for a
        // resolver that did not answer, and neither is a fault of this
        // installation. The distinction that matters — nothing there
        // versus could not ask — is `false` against `[]`, below.
        $answer = @$this->query($host, $type);
        if ($answer === false) {
            return ['status' => self::STATUS_FAILED, 'values' => []];
        }

        $values = [];
        foreach (array_slice($answer, 0, self::MAX_RECORDS_PER_TYPE) as $record) {
            $line = self::render($record);
            if ($line !== null) {
                $values[] = $line;
            }
        }

        return [
            'status' => $values === [] ? self::STATUS_NONE : self::STATUS_FOUND,
            'values' => $values,
        ];
    }

    /**
     * The one call to the resolver, alone in its own method so a test can
     * replace it without a network and without a stub of this whole class.
     *
     * @return array<int, array<string, mixed>>|false
     */
    protected function query(string $host, int $type): array|false
    {
        return dns_get_record($host, $type);
    }

    /**
     * One record as the line a `dig` user would recognise.
     *
     * Kept as text rather than as the associative array PHP returns: this
     * snapshot is written into a support archive a human reads, and the
     * shape of `dns_get_record()`'s output is a PHP detail that would make
     * the file harder to read than the zone it describes.
     *
     * @param array<string, mixed> $record
     */
    private static function render(array $record): ?string
    {
        $ttl = isset($record['ttl']) ? (int) $record['ttl'] : 0;
        $prefix = $ttl > 0 ? $ttl . "\t" : '';

        $value = match ((string) ($record['type'] ?? '')) {
            'A' => (string) ($record['ip'] ?? ''),
            'AAAA' => (string) ($record['ipv6'] ?? ''),
            'CNAME', 'NS', 'PTR' => (string) ($record['target'] ?? ''),
            'MX' => trim(((string) ($record['pri'] ?? '')) . ' ' . ((string) ($record['target'] ?? ''))),
            // `txt` is the joined value; `entries` is the same thing split
            // at the 255-character boundary a long DKIM key is stored in.
            'TXT' => (string) ($record['txt'] ?? ''),
            'SOA' => trim(implode(' ', [
                (string) ($record['mname'] ?? ''),
                (string) ($record['rname'] ?? ''),
                (string) ($record['serial'] ?? ''),
            ])),
            default => '',
        };

        $value = trim($value);

        return $value === '' ? null : $prefix . $value;
    }

    /**
     * The snapshot as the plain text a support archive carries.
     *
     * Typed loosely on purpose: this reads a snapshot that has been
     * through the database and `json_decode()` since it was built, so its
     * shape is a claim about the past rather than a guarantee about the
     * value in hand.
     *
     * @param array<string, mixed> $snapshot
     */
    public static function asText(array $snapshot): string
    {
        $lines = [
            'Enregistrements DNS lus par le receveur',
            '=======================================',
            '',
            'Hôte  : ' . (string) ($snapshot['host'] ?? 'inconnu'),
            'Lu le : ' . (string) ($snapshot['read_at'] ?? 'inconnu'),
            '',
            'Cette lecture date du moment où le ticket est arrivé, pas du moment',
            'où vous lisez ce fichier : c\'est tout l\'intérêt de la conserver.',
            '',
        ];

        foreach ((array) ($snapshot['records'] ?? []) as $type => $result) {
            $result = is_array($result) ? $result : [];
            $status = (string) ($result['status'] ?? self::STATUS_FAILED);
            $values = array_values(array_filter(
                (array) ($result['values'] ?? []),
                static fn(mixed $value): bool => is_string($value)
            ));

            $lines[] = '--- ' . ((string) $type) . ' ---';
            $lines[] = match ($status) {
                self::STATUS_FOUND => implode("\n", $values),
                self::STATUS_NONE => '(aucun enregistrement de ce type)',
                self::STATUS_SKIPPED => '(non demandé : le temps imparti à la lecture était écoulé)',
                default => '(la résolution a échoué — résolveur injoignable, ou domaine inexistant)',
            };
            $lines[] = '';
        }

        // Named, for the same reason a skipped type is named: a snapshot
        // that simply lacks its TXT records reads as a domain that has
        // none, and that is a diagnosis rather than a gap. The cause is
        // the storage column, not the resolver (see
        // `SupportTicketRepository::encodeSnapshot()`).
        $truncated = array_values(array_filter(
            (array) ($snapshot['truncated'] ?? []),
            static fn(mixed $value): bool => is_string($value)
        ));

        if ($truncated !== []) {
            $lines[] = 'Types retirés du relevé conservé, faute de place : ' . implode(', ', $truncated) . '.';
            $lines[] = 'Ils ont bien été demandés ; c\'est leur conservation qui a été tronquée.';
            $lines[] = '';
        }

        return implode("\n", $lines);
    }
}
