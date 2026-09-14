<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Mail\Transport\DeferredMailQueue;
use Core\Mail\Transport\DeferredMailRepository;
use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
use Core\Mail\Transport\MailReserve;
use Core\Mail\Transport\ProviderHealthRepository;
use Core\Support\SupportCollectorContext;
use Core\Support\SupportCollectorInterface;

/**
 * `outbound-mail.txt` — how this installation's mail actually leaves, and
 * what the chains have been doing (ARCHITECTURE.md §8.106, §8.48).
 *
 * The question it answers is the one nobody can answer from a screenshot:
 * "mail stopped going out" is the same sentence whether a relay
 * is refusing, a quota is spent, or a lane was emptied on the
 * configuration page three weeks ago. The chains, the counters and the
 * order are what tell those apart, and none of them was in the archive.
 *
 * **What it never carries**, and this is the part to read before adding a
 * line to it: no provider credential, no password, no token, no message
 * body, no recipient address. The package leaves the installation and
 * goes to a third party. What is here is counters, provider names and
 * host names — a host name is a server, an address is a person.
 *
 * `MailProvider` helps rather than hinders: it has no password property
 * at all, so this collector cannot print one by accident. Only
 * `Core\Mail\Transport\TransportConfigurator` reads one.
 *
 * **The one line that needed thought is the circuit breaker's reason.**
 * It is an SMTP server's own sentence, and a server refusing a message
 * says whose — « 550 5.1.1 <...>: Recipient address rejected ». It is
 * safe to print here only because it was redacted of addresses before it
 * was ever stored ({@see \Core\Mail\MailErrorRedaction}), and it goes
 * through {@see SupportCollectorContext::redact()} on the way out as
 * well, which is what turns « safe » into « safe twice ».
 */
class OutboundMailCollector implements SupportCollectorInterface
{
    /** Deep enough to see a pattern, short enough to stay readable. */
    private const COUNTER_DAYS = 30;

    /**
     * Everything after the chains is optional, and null means the section
     * is left out rather than printed empty: a support package collected
     * on an installation whose schema predates one of these tables is
     * still worth having.
     */
    public function __construct(
        private MailProviderDirectory $directory,
        private LaneChainRepository $chains,
        private ?ProviderHealthRepository $health = null,
        private ?MailReserve $reserve = null,
        private ?DeferredMailRepository $deferred = null,
        private ?DeferredMailQueue $queue = null
    ) {
    }

    public function name(): string
    {
        return 'outbound_mail';
    }

    public function collect(SupportCollectorContext $context): void
    {
        $providers = $this->directory->all();

        $lines = [];
        $lines[] = 'COURRIER SORTANT';
        $lines[] = '';
        $lines[] = 'Aucun identifiant, aucun mot de passe, aucun destinataire ne figure dans ce fichier.';
        $lines[] = '';

        $lines[] = '── Fournisseurs ────────────────────────────────────────────';
        foreach ($providers as $provider) {
            $lines[] = sprintf(
                '%s%s',
                $provider->name,
                $provider->isLocal() ? ' (envoi local, entrée permanente)' : ''
            );
            $lines[] = '  hôte        : ' . ($provider->isLocal() ? '(aucun relais)' : ($provider->host ?: '(vide)'));
            $lines[] = '  port        : ' . ($provider->isLocal() ? '-' : (string) $provider->port);
            $lines[] = '  quota/jour  : '
                . ($provider->dailyQuota === null ? '(aucun connu)' : (string) $provider->dailyQuota);
            $lines[] = sprintf(
                '  cadence     : %d message(s) toutes les %d minute(s) — voie « Masse » uniquement',
                $provider->batchSize,
                $provider->batchIntervalMinutes
            );
            $lines[] = '  identifiant : ' . ($provider->username !== '' ? 'configuré' : 'aucun');
            $lines[] = '  voies       : ' . ($this->lanesOf($provider->id) ?: '(aucune)');
            $lines[] = '';
        }

        $lines[] = '── Chaînes, dans leur ordre ────────────────────────────────';
        foreach (MailLane::ordered() as $lane) {
            $lines[] = $lane->label() . ' — ' . $lane->detail();
            $position = 0;
            foreach ($this->chains->forLane($lane) as $entry) {
                $provider = $providers[$entry->providerId] ?? null;
                $position++;
                $lines[] = sprintf(
                    '  %d. %s [%s]',
                    $position,
                    $provider === null
                    ? 'fournisseur #' . $entry->providerId . ' (introuvable)'
                    : $provider->name,
                    $entry->enabled ? 'actif' : 'désactivé'
                );
            }
            if ($position === 0) {
                $lines[] = '  (vide — aucun message ne peut partir sur cette voie)';
            }
            $lines[] = '';
        }

        foreach ($this->circuitLines($providers, $context) as $line) {
            $lines[] = $line;
        }

        foreach ($this->reserveLines($providers) as $line) {
            $lines[] = $line;
        }

        foreach ($this->queueLines() as $line) {
            $lines[] = $line;
        }

        $lines[] = sprintf('── Compteurs, %d derniers jours ────────────────────────────', self::COUNTER_DAYS);
        $lines[] = 'date        fournisseur                         voie             envoyés';
        foreach ($this->counterRows($context, $providers) as $row) {
            $lines[] = $row;
        }

        $context->addFileFromContent('outbound-mail.txt', implode("\n", $lines) . "\n");
    }

    /**
     * What the breaker has been doing (D15).
     *
     * The interesting column is `ouvertures` rather than the current
     * state: a provider closed right now that has opened eleven times
     * this month is a relay on its way out, and that is exactly the
     * pattern nobody can see from a screenshot taken between two
     * outages.
     *
     * @param array<int, MailProvider> $providers
     * @return array<int, string>
     */
    private function circuitLines(array $providers, SupportCollectorContext $context): array
    {
        if ($this->health === null) {
            return [];
        }

        try {
            $records = $this->health->all();
        } catch (\Throwable) {
            return [];
        }

        $lines = ['── Coupe-circuit ───────────────────────────────────────────'];

        if ($records === []) {
            $lines[] = '(aucun fournisseur n\'a jamais échoué)';
            $lines[] = '';

            return $lines;
        }

        $lines[] = 'fournisseur                         état     échecs  ouvertures  dernière ouverture';
        foreach ($records as $record) {
            $lines[] = sprintf(
                '%-35s %-8s %-7d %-11d %s',
                $providers[$record->providerId]->name ?? ('#' . $record->providerId),
                $record->isOpen() ? 'ouvert' : 'fermé',
                $record->consecutiveFailures,
                $record->openCount,
                $record->openedAt ?? '-'
            );
            if ($record->lastReason !== '') {
                $lines[] = '    dernier échec : ' . $context->redact($record->lastReason, 120);
            }
        }
        $lines[] = '';

        return $lines;
    }

    /**
     * What each provider is holding back for the authentication lane, and
     * the month that produced the number (D14).
     *
     * Printed with its provenance because the reserve is the one figure
     * on this page that looks arbitrary: « 74 » means nothing, « votre
     * pointe hors publipostage des 30 derniers jours (54), plus une marge
     * de 20 » can be checked against the counters two sections below.
     *
     * @param array<int, MailProvider> $providers
     * @return array<int, string>
     */
    private function reserveLines(array $providers): array
    {
        if ($this->reserve === null) {
            return [];
        }

        $lines = ['── Réserve pour les liens de connexion ─────────────────────'];

        foreach ($providers as $provider) {
            try {
                $reserve = $this->reserve->forProvider($provider);
            } catch (\Throwable) {
                continue;
            }

            $lines[] = $provider->name;
            $lines[] = '  ' . $reserve->provenance();
        }

        $lines[] = '';

        return $lines;
    }

    /**
     * The queue, in counts (D9, D17).
     *
     * Counts and ages only — never a subject, never a recipient. What
     * this section answers is « a-t-elle cessé de se vider », and a
     * depth per lane plus an age spread answers it without a single
     * message being decrypted.
     *
     * @return array<int, string>
     */
    private function queueLines(): array
    {
        if ($this->deferred === null) {
            return [];
        }

        try {
            $pending = $this->deferred->pendingCountByLane();
            $buckets = $this->queue?->abandonedByAge();
        } catch (\Throwable) {
            return [];
        }

        $lines = ['── Messages différés ───────────────────────────────────────'];

        foreach (MailLane::ordered() as $lane) {
            $waiting = $pending[$lane->value] ?? 0;
            $lines[] = sprintf(
                '  %-16s %d en attente%s',
                $lane->label(),
                $waiting,
                $lane === MailLane::Authentication ? ' (cette voie ne diffère jamais)' : ''
            );
        }

        if ($buckets !== null) {
            $lines[] = sprintf(
                '  abandonnés     : %d — dont %d de moins de 6 h, %d de moins de 24 h, '
                    . '%d de moins d\'une semaine, %d au-delà',
                $buckets['total'],
                $buckets['recent'],
                $buckets['day'],
                $buckets['week'],
                $buckets['older']
            );
        }

        $lines[] = '';

        return $lines;
    }

    private function lanesOf(int $providerId): string
    {
        $labels = [];
        foreach (MailLane::ordered() as $lane) {
            foreach ($this->chains->forLane($lane) as $entry) {
                if ($entry->providerId === $providerId && $entry->enabled) {
                    $labels[] = $lane->label();
                }
            }
        }

        return implode(', ', $labels);
    }

    /**
     * @param array<int, MailProvider> $providers
     * @return array<int, string>
     */
    private function counterRows(SupportCollectorContext $context, array $providers): array
    {
        // `COUNTER_DAYS - 1`, because the filter below is `>=` and today
        // counts: thirty days back from today is thirty-one dates, and the
        // heading above this table says thirty.
        $from = (new \DateTimeImmutable('-' . (self::COUNTER_DAYS - 1) . ' days'))->format('Y-m-d');

        $statement = $context->pdo()->prepare(
            'SELECT count_date, provider_id, lane, sent_count
             FROM mail_send_counters WHERE count_date >= ?
             ORDER BY count_date DESC, provider_id, lane'
        );
        $statement->execute([$from]);

        $rows = [];
        foreach ($statement->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $providerId = (int) $row['provider_id'];
            $rows[] = sprintf(
                '%-11s %-35s %-16s %d',
                (string) $row['count_date'],
                $providers[$providerId]->name ?? ('#' . $providerId),
                (string) $row['lane'],
                (int) $row['sent_count']
            );
        }

        return $rows === [] ? ['(aucun envoi enregistré sur la période)'] : $rows;
    }
}
