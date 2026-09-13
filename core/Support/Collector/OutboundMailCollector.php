<?php
/**
 * ScoutMagic — Copyright (C) 2026 Xavier Dubois and contributors
 * Licensed under AGPL-3.0-or-later. See LICENSE and NOTICE.
 */

declare(strict_types=1);

namespace Core\Support\Collector;

use Core\Mail\Transport\LaneChainRepository;
use Core\Mail\Transport\MailLane;
use Core\Mail\Transport\MailProvider;
use Core\Mail\Transport\MailProviderDirectory;
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
 */
class OutboundMailCollector implements SupportCollectorInterface
{
    /** Deep enough to see a pattern, short enough to stay readable. */
    private const COUNTER_DAYS = 30;

    public function __construct(
        private MailProviderDirectory $directory,
        private LaneChainRepository $chains
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

        $lines[] = sprintf('── Compteurs, %d derniers jours ────────────────────────────', self::COUNTER_DAYS);
        $lines[] = 'date        fournisseur                         voie             envoyés';
        foreach ($this->counterRows($context, $providers) as $row) {
            $lines[] = $row;
        }

        $context->addFileFromContent('outbound-mail.txt', implode("\n", $lines) . "\n");
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
